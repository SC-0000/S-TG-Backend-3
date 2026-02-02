<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class GuestOnboardingController extends Controller
{
    /**
     * Show the "complete your profile" page for guest_parent accounts.
     * The middleware redirects guest_parent users here when they attempt to access
     * a parent-only page. The original URL is preserved in the session flash
     * as 'redirect_to' so we can return them after successful onboarding.
     */
    public function show(Request $request)
    {
        $user = Auth::user();

        if (! $user || ($user->role ?? '') !== 'guest_parent') {
            // Not a guest parent — send them back to home
            return redirect()->route('home');
        }

        $children = $user->children()->get();

        // redirect_to may be flashed in session by middleware
        $redirectTo = session('redirect_to') ?? $request->query('redirect_to') ?? route('portal.assessments.index');

        // Render Inertia React page for guest onboarding
        return Inertia::render('@public/Auth/GuestComplete', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'children' => $children->map(fn($c) => [
                'id' => $c->id,
                'child_name' => $c->child_name,
                'date_of_birth' => $c->date_of_birth
                ? Carbon::parse($c->date_of_birth)->toDateString()
                : null,
            ]),
            'redirect_to' => $redirectTo,
        ]);
    }

    /**
     * Handle submission of the complete-profile form.
     * Accepts parent fields (name, email) and multiple children entries.
     * On success: updates/creates children, upgrades role to 'parent', logs in the user,
     * and redirects to the original URL.
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        Log::info('GuestOnboarding: store() called', [
            'user_id' => $user->id ?? null,
        ]);

        if (! $user || ($user->role ?? '') !== 'guest_parent') {
            Log::warning('GuestOnboarding: user not guest_parent or not authenticated', [
                'user_id' => $user->id ?? null,
            ]);
            return redirect()->route('home');
        }

        Log::info('GuestOnboarding: raw request', [
            'user_id' => $user->id ?? null,
            'input' => $request->all(),
        ]);

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'required|email|max:255',
            'phone_number' => 'nullable|string|max:30',
            'mobile_number' => 'nullable|string|max:30',
            'address_line1' => 'nullable|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'referral_source' => 'nullable|string|max:255',
            'application_type' => 'nullable|string|max:50',
            'terms_accepted' => 'nullable|boolean',
            'children' => 'nullable|array',
            'children.*.id' => 'nullable|integer|exists:children,id',
            'children.*.child_name' => 'required_without:children.*.id|string|max:255',
            'children.*.date_of_birth' => 'nullable|date',
            'children.*.school_name' => 'nullable|string|max:255',
            'children.*.area' => 'nullable|string|max:255',
            'children.*.year_group' => 'nullable|string|max:255',
            'children.*.age' => 'nullable|integer',
            'children.*.emergency_contact_name' => 'nullable|string|max:255',
            'children.*.emergency_contact_phone' => 'nullable|string|max:30',
            'children.*.academic_info' => 'nullable|string',
            'children.*.previous_grades' => 'nullable|string',
            'children.*.medical_info' => 'nullable|string',
            'children.*.additional_info' => 'nullable|string',
        ]);

        Log::info('GuestOnboarding: request validated', [
            'user_id' => $user->id ?? null,
            'validated' => $validated,
        ]);

        DB::beginTransaction();
        Log::info('GuestOnboarding: DB transaction started', [
            'user_id' => $user->id ?? null,
        ]);

        try {
            Log::info('GuestOnboarding: updating parent details', [
                'user_id' => $user->id ?? null,
            ]);

            if (! empty($validated['name'])) {
                $user->name = $validated['name'];
                Log::info('GuestOnboarding: updated name', [
                    'user_id' => $user->id,
                    'name' => $user->name,
                ]);
            }
            if (! empty($validated['mobile_number'])) {
                $user->mobile_number = $validated['mobile_number'];
                Log::info('GuestOnboarding: updated mobile_number', [
                    'user_id' => $user->id,
                    'mobile_number' => $user->mobile_number,
                ]);
            }
            if (! empty($validated['phone_number'])) {
                $user->phone_number = $validated['phone_number'];
                Log::info('GuestOnboarding: updated phone_number', [
                    'user_id' => $user->id,
                    'phone_number' => $user->phone_number,
                ]);
            }
            if (! empty($validated['address_line1'])) {
                $user->address_line1 = $validated['address_line1'];
                Log::info('GuestOnboarding: updated address_line1', [
                    'user_id' => $user->id,
                    'address_line1' => $user->address_line1,
                ]);
            }
            if (! empty($validated['address_line2'])) {
                $user->address_line2 = $validated['address_line2'];
                Log::info('GuestOnboarding: updated address_line2', [
                    'user_id' => $user->id,
                    'address_line2' => $user->address_line2,
                ]);
            }

            $user->email = $validated['email'];
            $user->role = 'parent';
            $user->onboarding_complete = true;
            $user->save();
            Log::info('GuestOnboarding: parent details saved', [
                'user_id' => $user->id,
                'role' => $user->role,
                'onboarding_complete' => $user->onboarding_complete,
            ]);

            $applicationDefaults = [
                'referral_source' => $validated['referral_source'] ?? null,
                'application_type' => $validated['application_type'] ?? null,
                'address_line1' => $validated['address_line1'] ?? null,
                'address_line2' => $validated['address_line2'] ?? null,
                'mobile_number' => $validated['mobile_number'] ?? null,
            ];

            $guestService = app(\App\Services\GuestCheckoutService::class);
            Log::info('GuestOnboarding: GuestCheckoutService resolved', [
                'user_id' => $user->id,
            ]);

            $childrenInput = $validated['children'] ?? [];
            $childrenInput = $validated['children'] ?? [];
            Log::info('GuestOnboarding: processing children', [
                'user_id' => $user->id,
                'children_count' => count($childrenInput),
            ]);

            foreach ($childrenInput as $c) {
                Log::info('GuestOnboarding: processing child', [
                    'user_id' => $user->id,
                    'child_input' => $c,
                ]);
                if (! empty($c['id'])) {
                    $existing = $user->children()->where('id', $c['id'])->first();
                    if ($existing) {
                        // Update all relevant child fields from payload (preserve existing values when omitted)
                        $existing->child_name = $c['child_name'] ?? $existing->child_name;
                        if (! empty($c['date_of_birth'])) {
                            $existing->date_of_birth = $c['date_of_birth'];
                        }
                        if (isset($c['age'])) {
                            $existing->age = (int) $c['age'];
                        }
                        if (isset($c['year_group'])) {
                            $existing->year_group = $c['year_group'];
                        }
                        if (isset($c['school_name'])) {
                            $existing->school_name = $c['school_name'];
                        }
                        if (isset($c['area'])) {
                            $existing->area = $c['area'];
                        }
                        if (isset($c['emergency_contact_name'])) {
                            $existing->emergency_contact_name = $c['emergency_contact_name'];
                        }
                        if (isset($c['emergency_contact_phone'])) {
                            $existing->emergency_contact_phone = $c['emergency_contact_phone'];
                        }
                        if (isset($c['academic_info'])) {
                            $existing->academic_info = $c['academic_info'];
                        }
                        if (isset($c['previous_grades'])) {
                            $existing->previous_grades = $c['previous_grades'];
                        }
                        if (isset($c['medical_info'])) {
                            $existing->medical_info = $c['medical_info'];
                        }
                        // map additional_info -> other_information in child model
                        if (array_key_exists('additional_info', $c)) {
                            $existing->other_information = $c['additional_info'];
                        }

                        $existing->save();
                        Log::info('GuestOnboarding: updated existing child', [
                            'user_id' => $user->id,
                            'child_id' => $existing->id,
                        ]);
                        continue;
                    } else {
                        Log::warning('GuestOnboarding: child id provided but not found for user', [
                            'user_id' => $user->id,
                            'child_id' => $c['id'],
                        ]);
                    }
                }

                $childPayload = [
                    'child_name' => $c['child_name'] ?? null,
                    'date_of_birth' => $c['date_of_birth'] ?? null,
                    'age' => isset($c['age']) ? (int)$c['age'] : null,
                    'year_group' => $c['year_group'] ?? null,
                    'school_name' => $c['school_name'] ?? null,
                    'area' => $c['area'] ?? null,
                    'emergency_contact_name' => $c['emergency_contact_name'] ?? null,
                    'emergency_contact_phone' => $c['emergency_contact_phone'] ?? null,
                    'academic_info' => $c['academic_info'] ?? null,
                    'previous_grades' => $c['previous_grades'] ?? null,
                    'medical_info' => $c['medical_info'] ?? null,
                    'other_information' => $c['additional_info'] ?? null,
                ];

                $childPayload['application'] = array_merge(
                    $applicationDefaults,
                    $c['application'] ?? []
                );

                Log::info('GuestOnboarding: creating child via service', [
                    'user_id' => $user->id,
                    'childPayload' => $childPayload,
                ]);

                $createdChild = $guestService->createChildForUser($user, $childPayload);

                if ($createdChild) {
                    Log::info('GuestOnboarding: child creation succeeded', [
                        'user_id' => $user->id,
                        'child_id' => $createdChild->id,
                        'application_id' => $createdChild->application_id ?? null,
                    ]);
                } else {
                    Log::warning('GuestOnboarding: child creation returned null', [
                        'user_id' => $user->id,
                        'childPayload' => $childPayload,
                    ]);
                }
            }

            DB::commit();
            Log::info('GuestOnboarding: DB transaction committed', [
                'user_id' => $user->id,
            ]);

            Auth::login($user);
            Log::info('GuestOnboarding: user re-authenticated', [
                'user_id' => $user->id,
                'role' => $user->role,
            ]);

            $redirectTo = $request->input('redirect_to') ?? session('redirect_to') ?? route('portal.assessments.index');
            Log::info('GuestOnboarding: redirecting after onboarding', [
                'user_id' => $user->id,
                'redirect_to' => $redirectTo,
            ]);

            return redirect($redirectTo)->with('success', 'Profile completed. You are now a registered parent.');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Guest onboarding failed', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->withErrors(['error' => 'Could not complete onboarding. Please try again.']);
        }
    }
}
