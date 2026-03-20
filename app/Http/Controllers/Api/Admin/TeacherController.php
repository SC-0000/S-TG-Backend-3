<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Organization;
use App\Models\Teacher;
use App\Models\User;
use App\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;

class TeacherController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;
        if ($user->isSuperAdmin() && $request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
        }

        // Include users with teacher role AND users who have a Teacher record (e.g. admins with teacher profiles)
        $userIdsWithTeacherProfile = Teacher::whereNotNull('user_id')->pluck('user_id');

        $query = User::where(function ($q) use ($userIdsWithTeacherProfile) {
                $q->where('role', 'teacher')
                  ->orWhereIn('id', $userIdsWithTeacherProfile);
            })
            ->whereNull('deleted_at')
            ->when($orgId, fn ($q) => $q->where('current_organization_id', $orgId))
            ->orderBy('name');

        $teachers = $query->paginate(ApiPagination::perPage($request, 20));
        $profiles = Teacher::whereIn('user_id', $teachers->pluck('id'))
            ->get()
            ->keyBy('user_id');

        $orgs = Organization::whereIn('id', $teachers->pluck('current_organization_id')->filter())
            ->get(['id', 'name'])
            ->keyBy('id');

        $data = $teachers->getCollection()->map(function (User $teacher) use ($profiles, $orgs) {
            $profile = $profiles->get($teacher->id);
            $metadata = $teacher->metadata ?? [];
            $org = $teacher->current_organization_id ? $orgs->get($teacher->current_organization_id) : null;

            // Resolve avatar: prefer teacher profile image, fall back to user avatar
            $avatarUrl = null;
            if ($profile?->image_path) {
                $avatarUrl = '/storage/' . $profile->image_path;
            } elseif ($teacher->avatar_path) {
                $avatarUrl = '/storage/' . $teacher->avatar_path;
            }

            return [
                'id' => $teacher->id,
                'name' => $teacher->name,
                'email' => $teacher->email,
                'mobile_number' => $teacher->mobile_number,
                'role' => $teacher->role,
                'status' => $metadata['status'] ?? null,
                'avatar_url' => $avatarUrl,
                'organization_id' => $teacher->current_organization_id,
                'organization_name' => $org?->name,
                'profile_id' => $profile?->id,
                'title' => $profile?->title,
                'category' => $profile?->category,
                'bio' => $profile?->bio,
                'student_count' => $teacher->assignedStudents()->count(),
            ];
        })->all();

        $organizations = null;
        if ($user->isSuperAdmin()) {
            $organizations = Organization::orderBy('name')->get(['id', 'name']);
        }

        return $this->paginated($teachers, $data, [
            'organizations' => $organizations,
            'filters' => $request->only(['organization_id']),
        ]);
    }

    public function show(Request $request, User $teacher): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        // Allow users with teacher role OR users who have a Teacher record
        if ($teacher->role !== User::ROLE_TEACHER && !Teacher::where('user_id', $teacher->id)->exists()) {
            return $this->error('Not found.', [], 404);
        }

        if (!$user->isSuperAdmin() && $user->current_organization_id && $teacher->current_organization_id !== $user->current_organization_id) {
            return $this->error('Not found.', [], 404);
        }

        $teacher->load(['assignedStudents.user']);
        $profile = Teacher::where('user_id', $teacher->id)->first();

        $childIds = $teacher->assignedStudents->pluck('id');
        $transactionIds = \App\Models\Access::whereIn('child_id', $childIds)
            ->whereNotNull('transaction_id')
            ->pluck('transaction_id')
            ->unique();

        $transactions = \App\Models\Transaction::with('user')
            ->whereIn('id', $transactionIds)
            ->where('status', 'completed')
            ->latest()
            ->get();

        $organization = $teacher->current_organization_id ? Organization::find($teacher->current_organization_id) : null;

        // Resolve avatar
        $avatarUrl = null;
        if ($profile?->image_path) {
            $avatarUrl = '/storage/' . $profile->image_path;
        } elseif ($teacher->avatar_path) {
            $avatarUrl = '/storage/' . $teacher->avatar_path;
        }

        $teacherData = [
            'id' => $teacher->id,
            'name' => $teacher->name,
            'email' => $teacher->email,
            'mobile_number' => $teacher->mobile_number,
            'role' => $teacher->role,
            'avatar_url' => $avatarUrl,
            'metadata' => $teacher->metadata ?? [],
            'organization_id' => $teacher->current_organization_id,
            'organization_name' => $organization?->name,
            'profile_id' => $profile?->id,
            'profile' => [
                'id' => $profile?->id,
                'title' => $profile?->title,
                'bio' => $profile?->bio,
                'category' => $profile?->category,
                'specialties' => $profile?->specialties ?? [],
                'metadata' => $profile?->metadata ?? [],
                'image_path' => $profile?->image_path,
            ],
            'assigned_students' => $teacher->assignedStudents->map(function ($student) {
                return [
                    'id' => $student->id,
                    'name' => $student->child_name,
                    'year_group' => $student->year_group,
                    'parent_name' => $student->user->name ?? null,
                    'notes' => $student->pivot->notes,
                    'assigned_at' => optional($student->pivot->assigned_at)->toDateTimeString(),
                ];
            }),
            'transactions' => $transactions->map(function ($tx) {
                return [
                    'id' => $tx->id,
                    'user_name' => $tx->user?->name,
                    'user_email' => $tx->user_email,
                    'total' => $tx->total,
                    'status' => $tx->status,
                    'created_at' => $tx->created_at,
                ];
            }),
            'revenue' => $transactions->sum('total'),
        ];

        return $this->success($teacherData);
    }

    public function assignments(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $teachers = User::where('role', 'teacher')
            ->when(
                !$user->isSuperAdmin() && $user->current_organization_id,
                fn ($q) => $q->where('current_organization_id', $user->current_organization_id)
            )
            ->with(['assignedStudents.user'])
            ->orderBy('name')
            ->get()
            ->map(function ($teacher) {
                return [
                    'id' => $teacher->id,
                    'name' => $teacher->name,
                    'email' => $teacher->email,
                    'assigned_students' => $teacher->assignedStudents->map(function ($student) {
                        return [
                            'id' => $student->id,
                            'name' => $student->child_name,
                            'parent_name' => $student->user->name ?? null,
                            'year_group' => $student->year_group,
                            'notes' => $student->pivot->notes,
                            'assigned_at' => $student->pivot->assigned_at,
                        ];
                    }),
                ];
            });

        return $this->success($teachers);
    }

    public function createData(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $users = User::select('id', 'name', 'email', 'mobile_number')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        return $this->success(['users' => $users]);
    }

    public function storeProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $data = $this->validateProfile($request);

        if (empty($data['user_id'])) {
            $email = $data['user_email'] ?? null;
            $password = $data['user_password'] ?? null;
            if (!$email || !$password) {
                return $this->error('Account email and password are required when creating a new teacher user.', [], 422);
            }
            if (User::where('email', $email)->exists()) {
                return $this->error('A user with this email already exists.', [], 422);
            }

            $orgId = $user->current_organization_id;
            $newUser = User::create([
                'name' => $data['name'],
                'email' => $email,
                'password' => Hash::make($password),
                'role' => User::ROLE_TEACHER,
                'mobile_number' => $data['user_mobile_number'] ?? null,
                'current_organization_id' => $orgId,
            ]);

            if ($orgId) {
                $newUser->organizations()->attach($orgId, [
                    'role' => 'teacher',
                    'status' => 'active',
                    'invited_by' => $user->id,
                    'joined_at' => now(),
                ]);
            }

            $data['user_id'] = $newUser->id;
        }

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('teachers', 'public');
        }

        $data['specialties'] = isset($data['specialties']) && is_array($data['specialties'])
            ? array_map('trim', $data['specialties'])
            : [];
        $data['metadata'] = $data['metadata'] ?? [];

        unset($data['user_email'], $data['user_password'], $data['user_password_confirmation'], $data['user_mobile_number']);

        $teacher = Teacher::create($data);

        return $this->success([
            'teacher' => $teacher,
        ], [], 201);
    }

    public function showProfile(Request $request, Teacher $teacher): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        return $this->success(['teacher' => $teacher]);
    }

    public function editData(Request $request, Teacher $teacher): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $users = User::select('id', 'name', 'email', 'mobile_number')->orderBy('name')->get();
        $linkedUser = $teacher->user()->select('id', 'name', 'email', 'mobile_number')->first();

        return $this->success([
            'teacher' => $teacher,
            'users' => $users,
            'linked_user' => $linkedUser,
        ]);
    }

    public function updateProfile(Request $request, Teacher $teacher): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $data = $this->validateProfile($request, $teacher);

        $linkedUserId = $data['user_id'] ?? $teacher->user_id;
        $linkedUser = $linkedUserId ? User::find($linkedUserId) : null;

        if ($linkedUser) {
            $linkedUser->fill([
                'name' => $data['name'],
                'email' => $data['user_email'] ?? $linkedUser->email,
                'mobile_number' => $data['user_mobile_number'] ?? $linkedUser->mobile_number,
            ]);
            $linkedUser->save();
        }

        if ($request->hasFile('image')) {
            if ($teacher->image_path) {
                Storage::disk('public')->delete($teacher->image_path);
            }
            $data['image_path'] = $request->file('image')->store('teachers', 'public');
        }

        $data['specialties'] = isset($data['specialties']) && is_array($data['specialties'])
            ? array_map('trim', $data['specialties'])
            : [];
        $data['metadata'] = $data['metadata'] ?? [];

        unset($data['user_email'], $data['user_password'], $data['user_password_confirmation'], $data['user_mobile_number']);

        $teacher->update($data);

        return $this->success(['teacher' => $teacher]);
    }

    /**
     * Soft-delete a teacher profile (preserves data, hides from lists).
     */
    public function destroyProfile(Request $request, Teacher $teacher): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $teacher->delete(); // soft delete

        return $this->success(['message' => 'Teacher profile archived.']);
    }

    /**
     * Soft-delete a teacher user and their profile.
     * User is soft-deleted (has SoftDeletes), Teacher profile is soft-deleted.
     * child_teacher assignments are preserved but the teacher won't appear in lists.
     */
    public function destroyUser(Request $request, User $teacher): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        if ($teacher->role !== User::ROLE_TEACHER && !Teacher::where('user_id', $teacher->id)->exists()) {
            return $this->error('Not found.', [], 404);
        }

        if (!$user->isSuperAdmin() && $user->current_organization_id && $teacher->current_organization_id !== $user->current_organization_id) {
            return $this->error('Not found.', [], 404);
        }

        // Soft-delete the teacher profile
        $profile = Teacher::where('user_id', $teacher->id)->first();
        if ($profile) {
            $profile->delete(); // soft delete
        }

        // Soft-delete the user
        $teacher->delete(); // User model has SoftDeletes

        return $this->success(['message' => 'Teacher archived successfully.']);
    }

    private function validateProfile(Request $request, ?Teacher $teacher = null): array
    {
        $linkedUserId = $request->input('user_id') ?: $teacher?->user_id;

        return $request->validate([
            'user_id' => 'nullable|exists:users,id',
            'name' => 'required|string|max:255',
            'title' => 'required|string|max:255',
            'role' => 'nullable|string|max:255',
            'bio' => 'required|string',
            'category' => 'nullable|string|max:100',
            'metadata' => 'nullable|array',
            'metadata.phone' => 'nullable|string',
            'metadata.email' => 'nullable|email',
            'metadata.address' => 'nullable|string',
            'specialties' => 'nullable|array',
            'image' => 'nullable|image|max:2048',
            'user_email' => [
                'nullable',
                'email',
                Rule::unique('users', 'email')->ignore($linkedUserId),
            ],
            'user_mobile_number' => 'nullable|string|max:255',
            'user_password' => 'nullable|string|min:8|confirmed',
        ]);
    }
}
