<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Organization;
use App\Models\Teacher;
use App\Models\User;
use App\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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

        $query = User::where('role', 'teacher')
            ->when($orgId, fn ($q) => $q->where('current_organization_id', $orgId))
            ->orderBy('name');

        $teachers = $query->paginate(ApiPagination::perPage($request, 20));
        $profiles = Teacher::whereIn('user_id', $teachers->pluck('id'))
            ->get()
            ->keyBy('user_id');

        $data = $teachers->getCollection()->map(function (User $teacher) use ($profiles) {
            $profile = $profiles->get($teacher->id);
            return [
                'id' => $teacher->id,
                'name' => $teacher->name,
                'email' => $teacher->email,
                'profile_id' => $profile?->id,
                'title' => $profile?->title,
                'category' => $profile?->category,
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

        if ($teacher->role !== User::ROLE_TEACHER) {
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

        $teacherData = [
            'id' => $teacher->id,
            'name' => $teacher->name,
            'email' => $teacher->email,
            'role' => $teacher->role,
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

        $users = User::select('id', 'name')->orderBy('name')->get();

        return $this->success(['users' => $users]);
    }

    public function storeProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $data = $this->validateProfile($request);

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('teachers', 'public');
        }

        $data['specialties'] = isset($data['specialties']) && is_array($data['specialties'])
            ? array_map('trim', $data['specialties'])
            : [];
        $data['metadata'] = $data['metadata'] ?? [];

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

        $users = User::select('id', 'name')->orderBy('name')->get();

        return $this->success([
            'teacher' => $teacher,
            'users' => $users,
        ]);
    }

    public function updateProfile(Request $request, Teacher $teacher): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $data = $this->validateProfile($request);

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

        $teacher->update($data);

        return $this->success(['teacher' => $teacher]);
    }

    public function destroyProfile(Request $request, Teacher $teacher): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        if ($teacher->image_path) {
            Storage::disk('public')->delete($teacher->image_path);
        }
        $teacher->delete();

        return $this->success(['message' => 'Teacher deleted.']);
    }

    private function validateProfile(Request $request): array
    {
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
        ]);
    }
}
