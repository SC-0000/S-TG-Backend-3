<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\UserResource;
use App\Services\GuestCheckoutService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class GuestOnboardingController extends ApiController
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || ($user->role ?? '') !== 'guest_parent') {
            return $this->error('Not a guest parent.', [], 403);
        }

        $children = $user->children()->get();

        return $this->success([
            'user' => new UserResource($user),
            'children' => $children->map(fn ($c) => [
                'id' => $c->id,
                'child_name' => $c->child_name,
                'date_of_birth' => $c->date_of_birth
                    ? Carbon::parse($c->date_of_birth)->toDateString()
                    : null,
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || ($user->role ?? '') !== 'guest_parent') {
            return $this->error('Not a guest parent.', [], 403);
        }

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

        DB::beginTransaction();

        try {
            if (!empty($validated['name'])) {
                $user->name = $validated['name'];
            }
            if (!empty($validated['mobile_number'])) {
                $user->mobile_number = $validated['mobile_number'];
            }
            if (!empty($validated['phone_number'])) {
                $user->phone_number = $validated['phone_number'];
            }
            if (!empty($validated['address_line1'])) {
                $user->address_line1 = $validated['address_line1'];
            }
            if (!empty($validated['address_line2'])) {
                $user->address_line2 = $validated['address_line2'];
            }

            $user->email = $validated['email'];
            $user->role = 'parent';
            $user->onboarding_complete = true;
            $user->save();

            $guestService = app(GuestCheckoutService::class);

            $childrenInput = $validated['children'] ?? [];
            foreach ($childrenInput as $c) {
                if (!empty($c['id'])) {
                    $existing = $user->children()->where('id', $c['id'])->first();
                    if ($existing) {
                        $existing->child_name = $c['child_name'] ?? $existing->child_name;
                        if (!empty($c['date_of_birth'])) {
                            $existing->date_of_birth = $c['date_of_birth'];
                        }
                        if (isset($c['age'])) {
                            $existing->age = (int) $c['age'];
                        }
                        $existing->school_name = $c['school_name'] ?? $existing->school_name;
                        $existing->area = $c['area'] ?? $existing->area;
                        $existing->year_group = $c['year_group'] ?? $existing->year_group;
                        $existing->emergency_contact_name = $c['emergency_contact_name'] ?? $existing->emergency_contact_name;
                        $existing->emergency_contact_phone = $c['emergency_contact_phone'] ?? $existing->emergency_contact_phone;
                        $existing->academic_info = $c['academic_info'] ?? $existing->academic_info;
                        $existing->previous_grades = $c['previous_grades'] ?? $existing->previous_grades;
                        $existing->medical_info = $c['medical_info'] ?? $existing->medical_info;
                        $existing->additional_info = $c['additional_info'] ?? $existing->additional_info;
                        $existing->save();
                    }
                } else {
                    $guestService->createChild($user, $c);
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $this->success([
            'user' => new UserResource($user->fresh()),
        ]);
    }
}
