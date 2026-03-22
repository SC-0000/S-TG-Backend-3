<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\ProfileUpdateRequest;
use App\Http\Requests\Api\PasswordUpdateRequest;
use App\Http\Resources\UserResource;
use App\Models\Teacher;
use App\Models\TeacherProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileController extends ApiController
{
    public function show(): JsonResponse
    {
        $user = request()->user();

        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $user->load('currentOrganization');
        [$teacher, $roleProfile] = $this->loadTeacherContext($user->id);

        return $this->success([
            'user' => new UserResource($user),
            'teacher_profile' => $this->buildTeacherProfilePayload($user, $teacher, $roleProfile),
        ]);
    }

    public function update(ProfileUpdateRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $canManageTeacherProfile = $this->canManageTeacherProfile($user);
        $teacherPayload = $data['teacher_profile'] ?? null;
        unset($data['teacher_profile']);

        // Handle avatar upload
        if ($request->hasFile('avatar')) {
            // Delete old avatar if exists
            if ($user->avatar_path && Storage::disk('public')->exists($user->avatar_path)) {
                Storage::disk('public')->delete($user->avatar_path);
            }

            $data['avatar_path'] = $request->file('avatar')->store("users/{$user->id}", 'public');
        }

        // Map phone to mobile_number
        if (array_key_exists('phone', $data)) {
            $data['mobile_number'] = $data['phone'];
            unset($data['phone']);
        }

        // Remove avatar from $data — already handled above
        unset($data['avatar']);

        if (array_key_exists('password', $data)) {
            $data['password'] = Hash::make($data['password']);
        }

        $user->fill($data);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        // Track if billing-relevant fields changed
        $billingFieldsChanged = $user->isDirty('name') || $user->isDirty('email') || $user->isDirty('mobile_number');

        $user->save();
        $user->load('currentOrganization');

        // Sync billing customer if name/email/phone changed
        if ($billingFieldsChanged && $user->billing_customer_id) {
            try {
                app(\App\Services\BillingService::class)->updateCustomer($user);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Profile update: billing sync failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }

        if ($teacherPayload !== null) {
            if (!$canManageTeacherProfile) {
                return $this->error('You cannot update teacher profile details for this account.', [], 403);
            }

            $this->upsertTeacherProfile($user->id, $user->name, $teacherPayload);
        }

        if ($request->hasFile('avatar') && $canManageTeacherProfile) {
            $this->syncTeacherAvatarToUserAvatar($user->id);
        }

        [$teacher, $roleProfile] = $this->loadTeacherContext($user->id);

        return $this->success([
            'user' => new UserResource($user),
            'teacher_profile' => $this->buildTeacherProfilePayload($user, $teacher, $roleProfile),
        ]);
    }

    public function destroy(): JsonResponse
    {
        $payload = request()->validate([
            'password' => ['required', 'string'],
        ]);

        $user = request()->user();
        if (!$user || !Hash::check($payload['password'], $user->password)) {
            return $this->error('Password is incorrect.', [], 422);
        }

        if ($user?->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        Auth::logout();
        $user->delete();

        return $this->success(['message' => 'Account deleted.']);
    }

    public function updatePassword(PasswordUpdateRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->password = Hash::make($request->input('password'));
        $user->save();

        return $this->success(['message' => 'Password updated.']);
    }

    private function loadTeacherContext(int $userId): array
    {
        return [
            Teacher::where('user_id', $userId)->first(),
            TeacherProfile::where('user_id', $userId)->first(),
        ];
    }

    private function canManageTeacherProfile($user): bool
    {
        $metadata = $user->metadata ?? [];

        return $user->role === 'teacher'
            || in_array($metadata['original_role'] ?? null, ['admin', 'super_admin'], true)
            || Teacher::where('user_id', $user->id)->exists()
            || TeacherProfile::where('user_id', $user->id)->exists();
    }

    private function upsertTeacherProfile(int $userId, string $userName, array $payload): void
    {
        $teacher = Teacher::firstOrNew(['user_id' => $userId]);
        $metadata = is_array($teacher->metadata) ? $teacher->metadata : [];

        if (!$teacher->exists) {
            $teacher->name = $userName;
            $teacher->role = 'teacher';
        }

        if (array_key_exists('title', $payload)) {
            $teacher->title = $this->nullIfBlank($payload['title'] ?? null);
        }

        if (array_key_exists('category', $payload)) {
            $teacher->category = $this->nullIfBlank($payload['category'] ?? null);
        }

        if (array_key_exists('bio', $payload)) {
            $teacher->bio = trim((string) ($payload['bio'] ?? ''));
        }

        if (array_key_exists('specialties', $payload)) {
            $teacher->specialties = collect($payload['specialties'] ?? [])
                ->map(fn ($item) => trim((string) $item))
                ->filter()
                ->values()
                ->all();
        }

        if (array_key_exists('metadata', $payload) && is_array($payload['metadata'])) {
            if (array_key_exists('address', $payload['metadata'])) {
                $address = $this->nullIfBlank($payload['metadata']['address'] ?? null);
                if ($address === null) {
                    unset($metadata['address']);
                } else {
                    $metadata['address'] = $address;
                }
            }
        }

        $teacher->metadata = !empty($metadata) ? $metadata : null;
        $teacher->save();

        $roleProfile = TeacherProfile::firstOrNew(['user_id' => $userId]);
        if (!$roleProfile->exists) {
            $roleProfile->display_name = $userName;
        }

        if (array_key_exists('bio', $payload)) {
            $roleProfile->bio = trim((string) ($payload['bio'] ?? ''));
        }

        $roleProfile->save();
    }

    private function syncTeacherAvatarToUserAvatar(int $userId): void
    {
        $teacher = Teacher::where('user_id', $userId)->first();
        if (!$teacher || !$teacher->image_path) {
            return;
        }

        if (Storage::disk('public')->exists($teacher->image_path)) {
            Storage::disk('public')->delete($teacher->image_path);
        }

        $teacher->image_path = null;
        $teacher->save();
    }

    private function buildTeacherProfilePayload($user, ?Teacher $teacher, ?TeacherProfile $roleProfile): ?array
    {
        if (!$this->canManageTeacherProfile($user) && !$teacher && !$roleProfile) {
            return null;
        }

        $metadata = is_array($teacher?->metadata) ? $teacher->metadata : [];
        $specialties = collect($teacher?->specialties ?? [])
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->values()
            ->all();

        return [
            'id' => $teacher?->id,
            'role_profile_id' => $roleProfile?->id,
            'display_name' => $roleProfile?->display_name ?: $teacher?->name ?: $user->name,
            'title' => $teacher?->title,
            'role' => $teacher?->role,
            'bio' => $teacher?->bio ?? $roleProfile?->bio ?? '',
            'category' => $teacher?->category,
            'specialties' => $specialties,
            'qualifications' => is_array($roleProfile?->qualifications) ? $roleProfile->qualifications : [],
            'metadata' => [
                'address' => $metadata['address'] ?? null,
            ],
            'image_path' => $teacher?->image_path,
            'image_url' => $teacher?->image_path
                ? '/storage/' . $teacher->image_path
                : ($user->avatar_path ? '/storage/' . $user->avatar_path : null),
        ];
    }

    private function nullIfBlank($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
