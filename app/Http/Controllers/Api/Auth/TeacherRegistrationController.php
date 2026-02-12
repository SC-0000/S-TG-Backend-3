<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ApiController;
use App\Mail\GuestVerificationCode;
use App\Mail\TeacherApplicationReceived;
use App\Models\AdminTask;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class TeacherRegistrationController extends ApiController
{
    private function otpCacheKey(string $email): string
    {
        return 'teacher_otp:' . Str::lower($email);
    }

    private function verifiedCacheKey(string $email): string
    {
        return 'teacher_otp_verified:' . Str::lower($email);
    }

    public function sendOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|unique:users,email',
        ], [
            'email.unique' => 'This email is already registered.'
        ]);

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->otpCacheKey($validated['email']), $otp, now()->addMinutes(5));
        Cache::forget($this->verifiedCacheKey($validated['email']));

        Mail::to($validated['email'])->send(new GuestVerificationCode($otp));

        return $this->success(['message' => 'Verification code sent to your email.']);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'otp' => 'required|string|size:6',
        ]);

        $storedOtp = Cache::get($this->otpCacheKey($validated['email']));

        if (!$storedOtp) {
            return $this->error('Verification code has expired. Please request a new one.', [], 422);
        }

        if (!hash_equals((string) $storedOtp, (string) $validated['otp'])) {
            return $this->error('Invalid verification code.', [], 422);
        }

        Cache::put($this->verifiedCacheKey($validated['email']), true, now()->addMinutes(10));

        return $this->success(['message' => 'Email verified successfully.']);
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'mobile_number' => 'nullable|string|max:20',
            'qualifications' => 'nullable|string',
            'experience' => 'nullable|string',
            'specialization' => 'nullable|string',
            'organization_id' => 'required|integer|exists:organizations,id',
        ]);

        $verified = Cache::get($this->verifiedCacheKey($validated['email']));
        if (!$verified) {
            return $this->error('Please verify your email first.', [], 422);
        }

        $organization = Organization::find($validated['organization_id']);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'teacher',
            'mobile_number' => $validated['mobile_number'] ?? null,
            'current_organization_id' => $validated['organization_id'],
            'metadata' => [
                'status' => 'pending_approval',
                'qualifications' => $validated['qualifications'] ?? null,
                'experience' => $validated['experience'] ?? null,
                'specialization' => $validated['specialization'] ?? null,
                'applied_at' => now()->toISOString(),
            ],
        ]);

        $user->organizations()->attach($validated['organization_id'], [
            'role' => 'teacher',
            'status' => 'active',
            'invited_by' => null,
            'joined_at' => now(),
        ]);

        $task = AdminTask::create([
            'organization_id' => $validated['organization_id'],
            'task_type' => 'teacher_approval',
            'title' => 'New Teacher Application: ' . $user->name,
            'description' => 'Review and approve teacher application from ' . $user->email,
            'status' => 'pending',
            'related_entity' => url('/teacher-applications'),
            'metadata' => [
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'mobile_number' => $user->mobile_number,
                'qualifications' => $validated['qualifications'] ?? null,
                'experience' => $validated['experience'] ?? null,
                'specialization' => $validated['specialization'] ?? null,
            ],
        ]);

        if ($organization) {
            Mail::to($user->email)->send(new TeacherApplicationReceived($user->name, $user->email));
        }

        Cache::forget($this->otpCacheKey($validated['email']));
        Cache::forget($this->verifiedCacheKey($validated['email']));

        return $this->success([
            'message' => 'Teacher application submitted successfully.',
            'task_id' => $task->id ?? null,
        ], status: 201);
    }
}
