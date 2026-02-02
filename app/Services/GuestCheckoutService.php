<?php

namespace App\Services;

use App\Models\User;
use App\Models\Child;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Mail\SendLoginCredentials;
use App\Mail\VerifyApplicationEmail;

class GuestCheckoutService
{
    /**
     * Find or create a guest_parent user for quick checkout.
     *
     * Returns an array with:
     *  - status: 'created' | 'existing_guest' | 'existing_parent'
     *  - user: User instance (when applicable)
     *
     * If an existing user with role 'parent' exists for the email, the method
     * returns status 'existing_parent' and DOES NOT attach the purchase to them
     * automatically (to avoid hijacking accounts). The caller should prompt login
     * or send a magic-link in that case.
     *
     * @param  array  $data  ['email' => string, 'name' => string|null]
     * @return array
     */
    public function findOrCreateGuestUser(array $data): array
    {
        $email = strtolower(trim($data['email'] ?? ''));
        if ($email === '') {
            return ['status' => 'invalid', 'user' => null];
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            // If a full parent already exists, we don't auto-attach purchases.
            if ($user->role === User::ROLE_PARENT) {
                return ['status' => 'existing_parent', 'user' => $user];
            }

            // If user exists and is already guest_parent or basic, reuse them.
            if ($user->role === User::ROLE_GUEST_PARENT || $user->role === User::ROLE_BASIC) {
                // Ensure onboarding flags exist and temporary_at is set for guests
                $user->onboarding_complete = $user->onboarding_complete ?? false;
                if (! $user->temporary_at) {
                    $user->temporary_at = $user->temporary_at ?? Carbon::now();
                }
                $user->save();
                return ['status' => 'existing_guest', 'user' => $user];
            }

            // For admin or other roles, treat as existing_parent to be safe
            return ['status' => 'existing_parent', 'user' => $user];
        }

        // Get organization_id from data (same pattern as teacher/parent registration)
        $organizationId = $data['organization_id'] ?? 2;

        // Create a new guest_parent user
        $name = $data['name'] ?? ($data['email'] ?? 'Guest Parent');
        $tempPassword = Str::random(40);

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($tempPassword),
            'role' => User::ROLE_GUEST_PARENT,
            'onboarding_complete' => false,
            'temporary_at' => Carbon::now(),
            'current_organization_id' => $organizationId,
        ]);

        // Attach to organization pivot table (same as teacher registration)
        if (!$user->organizations()->where('organization_id', $organizationId)->exists()) {
            $user->organizations()->attach($organizationId, [
                'role' => 'parent',
                'status' => 'active',
                'invited_by' => null,
                'joined_at' => Carbon::now(),
            ]);
        }

        // Dispatch a welcome / credentials email asynchronously.
        // Prefer magic-link / passwordless flows; here we send credentials as a fallback.
        try {
            // If your SendLoginCredentials expects ($user, $password) adjust accordingly.
            Mail::to($user->email)->queue(new SendLoginCredentials($user, $tempPassword));
        } catch (\Throwable $e) {
            // Don't block checkout on email failure; log or handle via monitoring.
                Log::warning('Failed to queue SendLoginCredentials for guest user', [
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
        }

        return ['status' => 'created', 'user' => $user];
    }

    /**
     * Create a child record for the given user if child data provided.
     * Returns the created Child model.
     *
     * @param  \App\Models\User  $user
     * @param  array             $childData
     * @return \App\Models\Child|null
     */
    public function createChildForUser(User $user, array $childData = [])
    {
        $childName = $childData['child_name'] ?? null;
 
        if (! $childName) {
            Log::info('createChildForUser called without child_name, skipping', [
                'user_id' => $user->id ?? null,
                'childData' => $childData,
            ]);
            return null;
        }
 
        // Some deployments require application_id on children. Create a lightweight
        // placeholder Application and create the child inside a single DB transaction
        // so the FK to applications.application_id is satisfied.
        $applicationId = (string) Str::uuid();
 
        // Log intent and payload for debugging
        Log::info('Creating child for guest user', [
            'user_id' => $user->id ?? null,
            'application_id' => $applicationId,
            'childData' => $childData,
        ]);
 
        try {
            $child = null;
            DB::transaction(function () use ($applicationId, $user, $childData, $childName, &$child) {
                // Insert minimal application row using query builder to avoid model side-effects
                // Allow caller to provide application-level fields via $childData['application']
                $applicationOverrides = $childData['application'] ?? [];

                // Whitelist application fields we allow overriding to avoid accidental mass-assignment of protected columns
                $allowedAppKeys = [
                    'applicant_name', 'email', 'phone_number', 'application_status',
                    'application_type', 'signature_path', 'admin_feedback', 'reviewer_id',
                    'verification_token', 'verified_at', 'children_data', 'referral_source',
                    'address_line1', 'address_line2', 'mobile_number', 'user_id',
                ];

                $appInsert = [
                    'application_id'    => $applicationId,
                    'applicant_name'    => $user->name ?? $user->email,
                    'email'             => $user->email,
                    'application_status'=> 'Pending',
                    'application_type'  => 'Type1',
                    'submitted_date'    => Carbon::now(),
                    'user_id'           => $user->id,
                    'created_at'        => Carbon::now(),
                    'updated_at'        => Carbon::now(),
                ];

                // Merge allowed overrides provided by caller
                foreach ($applicationOverrides as $k => $v) {
                    if (in_array($k, $allowedAppKeys, true) && ! is_null($v)) {
                        $appInsert[$k] = $v;
                    }
                }

                DB::table('applications')->insert($appInsert);
 
                // Build child payload with safe defaults for NOT NULL columns
                $payload = [
                    'user_id'        => $user->id,
                    'application_id' => $applicationId,
                    'child_name'     => $childName,
                    'date_of_birth'  => $childData['date_of_birth'] ?? null,
                    'age'            => $childData['age'] ?? 0,
                    'year_group'     => $childData['year_group'] ?? 'Not specified',
                    'school_name'    => $childData['school_name'] ?? 'Not specified',
                    'area'           => $childData['area'] ?? 'Not specified',
                    'learning_difficulties' => $childData['learning_difficulties'] ?? null,
                    'focus_targets'         => $childData['focus_targets'] ?? null,
                    'other_information'     => $childData['other_information'] ?? null,
                    'organization_id'       => $childData['organization_id'] ?? 2,
                ];
 
                // Note: do not pass created_at/updated_at in payload; Eloquent will handle timestamps.
                // Remove only truly null optional fields
                $payload = array_filter($payload, function ($v, $k) {
                    $optionalKeys = ['date_of_birth', 'learning_difficulties', 'focus_targets', 'other_information'];
                    if (in_array($k, $optionalKeys)) {
                        return !is_null($v);
                    }
                    return true;
                }, ARRAY_FILTER_USE_BOTH);
 
                // Insert child (Eloquent) inside same transaction
                $child = Child::create($payload);
 
                // For extra safety, ensure the child was created and log inside transaction
                if ($child && $child->id) {
                    Log::info('Inserted child row inside transaction', [
                        'child_id' => $child->id,
                        'application_id' => $applicationId,
                        'user_id' => $user->id,
                    ]);
                } else {
                    // This should not happen; throw to rollback
                    throw new \Exception('Child::create did not return a model instance');
                }
            }, 5);
        } catch (\Throwable $e) {
            Log::error('Could not create application+child for guest checkout', [
                'user_id' => $user->id,
                'application_id' => $applicationId,
                'child_name' => $childName,
                'childData' => $childData,
                'error' => $e->getMessage(),
                'exception' => (string) $e,
            ]);
            return null;
        }
 
        // Log final result
        if ($child) {
            Log::info('Successfully created child for guest', [
                'child_id' => $child->id,
                'user_id' => $user->id,
                'application_id' => $applicationId,
            ]);
        } else {
            Log::warning('createChildForUser completed but child is null', [
                'user_id' => $user->id,
                'application_id' => $applicationId,
            ]);
        }
 
        return $child ?? null;
    }
}
