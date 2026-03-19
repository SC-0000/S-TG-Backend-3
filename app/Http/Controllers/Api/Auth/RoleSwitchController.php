<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\UserResource;
use App\Models\Teacher;
use App\Models\TeacherProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RoleSwitchController extends ApiController
{
    /**
     * Check if the current admin/super_admin user has a teacher profile.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'super_admin'])) {
            return $this->error('Only admins can check teacher mode status.', [], 403);
        }

        $teacherProfile = TeacherProfile::where('user_id', $user->id)->first();
        $teacher = Teacher::where('user_id', $user->id)->first();

        return $this->success([
            'has_teacher_profile' => (bool) $teacherProfile,
            'has_teacher_record' => (bool) $teacher,
            'teacher_profile' => $teacherProfile ? [
                'id' => $teacherProfile->id,
                'display_name' => $teacherProfile->display_name,
                'bio' => $teacherProfile->bio,
            ] : null,
            'can_switch_to_teacher' => (bool) $teacherProfile,
        ]);
    }

    /**
     * Create a teacher profile for the current admin user.
     */
    public function createTeacherProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'super_admin'])) {
            return $this->error('Only admins can create a teacher profile.', [], 403);
        }

        // Check if already has a teacher profile
        if (TeacherProfile::where('user_id', $user->id)->exists()) {
            return $this->error('You already have a teacher profile.', [], 422);
        }

        $validator = Validator::make($request->all(), [
            'display_name' => 'required|string|max:255',
            'bio' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed.', $validator->errors()->toArray(), 422);
        }

        DB::beginTransaction();

        try {
            // Create teacher record if not exists
            $teacher = Teacher::where('user_id', $user->id)->first();
            if (!$teacher) {
                $teacher = Teacher::create([
                    'user_id' => $user->id,
                    'name' => $request->input('display_name'),
                    'title' => 'Teacher',
                    'role' => 'teacher',
                    'bio' => $request->input('bio', ''),
                ]);
            }

            // Create teacher profile
            $teacherProfile = TeacherProfile::create([
                'user_id' => $user->id,
                'display_name' => $request->input('display_name'),
                'bio' => $request->input('bio', ''),
            ]);

            DB::commit();

            return $this->success([
                'message' => 'Teacher profile created successfully.',
                'teacher_profile' => [
                    'id' => $teacherProfile->id,
                    'display_name' => $teacherProfile->display_name,
                    'bio' => $teacherProfile->bio,
                ],
            ], status: 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to create teacher profile.', [], 500);
        }
    }

    /**
     * Switch an admin user to teacher mode.
     * Creates a new token scoped to teacher role and stores the original role.
     */
    public function switchToTeacher(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'super_admin'])) {
            return $this->error('Only admins can switch to teacher mode.', [], 403);
        }

        // Must have a teacher profile
        if (!TeacherProfile::where('user_id', $user->id)->exists()) {
            return $this->error('You need a teacher profile to switch to teacher mode.', [], 422);
        }

        // Store original role in metadata so we can switch back
        $metadata = $user->metadata ?? [];
        $metadata['original_role'] = $user->role;
        $metadata['switched_at'] = now()->toIso8601String();

        $user->update([
            'role' => 'teacher',
            'metadata' => $metadata,
        ]);

        // Delete old token and create a new one
        $user->currentAccessToken()->delete();
        $newToken = $user->createToken('teacher-mode')->plainTextToken;

        // Reload user to get fresh data
        $user->refresh();
        $user->load('currentOrganization');

        return $this->success([
            'message' => 'Switched to teacher mode.',
            'token' => $newToken,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
            'switched_from' => $metadata['original_role'],
        ]);
    }

    /**
     * Switch back from teacher mode to the original admin role.
     */
    public function switchToAdmin(Request $request): JsonResponse
    {
        $user = $request->user();

        $metadata = $user->metadata ?? [];
        $originalRole = $metadata['original_role'] ?? null;

        if (!$originalRole || !in_array($originalRole, ['admin', 'super_admin'])) {
            return $this->error('You are not in teacher mode or have no admin role to return to.', [], 403);
        }

        // Restore original role and clean up metadata
        unset($metadata['original_role'], $metadata['switched_at']);

        $user->update([
            'role' => $originalRole,
            'metadata' => !empty($metadata) ? $metadata : null,
        ]);

        // Delete old token and create a new one
        $user->currentAccessToken()->delete();
        $newToken = $user->createToken('admin-mode')->plainTextToken;

        // Reload user to get fresh data
        $user->refresh();
        $user->load('currentOrganization');

        return $this->success([
            'message' => 'Switched back to admin mode.',
            'token' => $newToken,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
        ]);
    }
}
