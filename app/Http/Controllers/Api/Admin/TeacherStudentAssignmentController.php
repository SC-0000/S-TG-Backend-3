<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\AdminAssignments\TeacherStudentAssignRequest;
use App\Http\Requests\Api\AdminAssignments\TeacherStudentBulkAssignRequest;
use App\Http\Requests\Api\AdminAssignments\TeacherStudentUnassignRequest;
use App\Models\AdminTask;
use App\Models\Child;
use App\Models\User;
use App\Models\Organization;
use App\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeacherStudentAssignmentController extends ApiController
{
    private function portalBaseUrl(?Organization $organization): ?string
    {
        $value = $organization?->portal_domain;
        if (! $value || ! is_string($value)) {
            $value = (string) config('app.frontend_url');
        }

        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        $scheme = null;
        $host = null;
        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            $parsed = parse_url($raw);
            $scheme = $parsed['scheme'] ?? null;
            $host = $parsed['host'] ?? null;
        } else {
            $host = preg_replace('#/.*$#', '', $raw);
        }

        if (! $host) {
            return null;
        }

        if (! $scheme) {
            $isLocal = str_starts_with($host, 'localhost') || str_starts_with($host, '127.0.0.1');
            $scheme = $isLocal ? 'http' : 'https';
        }

        return $scheme . '://' . $host;
    }

    private function portalUrl(string $path, ?Organization $organization): ?string
    {
        $base = $this->portalBaseUrl($organization);
        if (! $base) {
            return null;
        }
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
    public function data(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;

        $teachers = User::where('role', 'teacher')
            ->when($orgId, fn($q) => $q->where('current_organization_id', $orgId))
            ->when($request->filled('teacher_search'), function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->query('teacher_search') . '%');
            })
            ->withCount('assignedStudents')
            ->get()
            ->map(function ($teacher) {
                return [
                    'id' => $teacher->id,
                    'name' => $teacher->name,
                    'email' => $teacher->email,
                    'assigned_students_count' => $teacher->assigned_students_count,
                ];
            })
            ->values();

        $studentsQuery = Child::query()
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->with(['user', 'assignedTeachers']);

        if ($request->filled('student_search')) {
            $studentSearch = $request->query('student_search');
            $studentsQuery->where(function ($q) use ($studentSearch) {
                $q->where('child_name', 'like', "%{$studentSearch}%")
                    ->orWhereHas('user', function ($userQuery) use ($studentSearch) {
                        $userQuery->where('name', 'like', "%{$studentSearch}%")
                            ->orWhere('email', 'like', "%{$studentSearch}%");
                    });
            });
        }

        if ($request->filled('year_group')) {
            $studentsQuery->where('year_group', $request->query('year_group'));
        }

        if ($request->filled('area')) {
            $studentsQuery->where('area', $request->query('area'));
        }

        if ($request->filled('school')) {
            $studentsQuery->where('school_name', 'like', '%' . $request->query('school') . '%');
        }

        if ($request->boolean('unassigned_only')) {
            $studentsQuery->doesntHave('assignedTeachers');
        }

        $students = $studentsQuery->paginate(ApiPagination::perPage($request, 50));
        $studentsData = $students->getCollection()->map(function ($student) {
            return [
                'id' => $student->id,
                'child_name' => $student->child_name,
                'year_group' => $student->year_group,
                'area' => $student->area,
                'school_name' => $student->school_name,
                'user' => [
                    'id' => $student->user?->id,
                    'name' => $student->user?->name,
                    'email' => $student->user?->email,
                ],
                'assigned_teachers' => $student->assignedTeachers->map(function ($teacher) {
                    return [
                        'id' => $teacher->id,
                        'name' => $teacher->name,
                    ];
                })->values(),
            ];
        })->values();

        return $this->paginated($students, [
            'teachers' => $teachers,
            'students' => $studentsData,
        ]);
    }

    public function assign(TeacherStudentAssignRequest $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $validated = $request->validated();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;

        $teacher = User::where('role', 'teacher')
            ->when($orgId, fn($q) => $q->where('current_organization_id', $orgId))
            ->findOrFail($validated['teacher_id']);
        $organization = $orgId ? Organization::find($orgId) : null;

        $students = Child::whereIn('id', $validated['student_ids'])
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->get();

        if ($students->count() !== count($validated['student_ids'])) {
            return $this->error('One or more students were not found for this organization.', [], 422);
        }

        foreach ($students as $student) {
            $teacher->assignedStudents()->syncWithoutDetaching([
                $student->id => [
                    'assigned_by' => $user->id,
                    'assigned_at' => now(),
                    'notes' => $validated['notes'] ?? null,
                    'organization_id' => $orgId,
                ],
            ]);

            $notes = !empty($validated['notes']) ? $validated['notes'] : null;

            AdminTask::create([
                'task_type' => 'New Student Assigned',
                'assigned_to' => $teacher->id,
                'status' => 'Pending',
                'related_entity' => $this->portalUrl('/teacher/students/' . $student->id, $organization),
                'priority' => 'Medium',
                'description' => "Student '{$student->child_name}' (Parent: {$student->user->name}) has been assigned to you by {$user->name}" .
                    ($notes ? ". Notes: {$notes}" : '.'),
                'organization_id' => $orgId,
            ]);
        }

        return $this->success([
            'message' => count($validated['student_ids']) . ' student(s) assigned to ' . $teacher->name . ' successfully!',
        ], status: 201);
    }

    public function bulkAssign(TeacherStudentBulkAssignRequest $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $validated = $request->validated();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;

        $teachers = User::where('role', 'teacher')
            ->when($orgId, fn($q) => $q->where('current_organization_id', $orgId))
            ->whereIn('id', $validated['teacher_ids'])
            ->get();
        $organization = $orgId ? Organization::find($orgId) : null;

        if ($teachers->count() !== count($validated['teacher_ids'])) {
            return $this->error('One or more teachers were not found for this organization.', [], 422);
        }

        $students = Child::whereIn('id', $validated['student_ids'])
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->get();

        if ($students->count() !== count($validated['student_ids'])) {
            return $this->error('One or more students were not found for this organization.', [], 422);
        }

        $assignedCount = 0;
        foreach ($teachers as $teacher) {
            foreach ($students as $student) {
                $teacher->assignedStudents()->syncWithoutDetaching([
                    $student->id => [
                        'assigned_by' => $user->id,
                        'assigned_at' => now(),
                        'notes' => $validated['notes'] ?? null,
                        'organization_id' => $orgId,
                    ],
                ]);

                $notes = !empty($validated['notes']) ? $validated['notes'] : null;

                AdminTask::create([
                    'task_type' => 'New Student Assigned',
                    'assigned_to' => $teacher->id,
                    'status' => 'Pending',
                    'related_entity' => $this->portalUrl('/teacher/students/' . $student->id, $organization),
                    'priority' => 'Medium',
                    'description' => "Student '{$student->child_name}' (Parent: {$student->user->name}) has been assigned to you by {$user->name}" .
                        ($notes ? ". Notes: {$notes}" : '.'),
                    'organization_id' => $orgId,
                ]);

                $assignedCount++;
            }
        }

        return $this->success([
            'message' => "Bulk assignment completed! {$assignedCount} assignment(s) created.",
        ], status: 201);
    }

    public function unassign(TeacherStudentUnassignRequest $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $validated = $request->validated();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;

        $teacher = User::where('role', 'teacher')
            ->when($orgId, fn($q) => $q->where('current_organization_id', $orgId))
            ->findOrFail($validated['teacher_id']);

        $student = Child::where('id', $validated['student_id'])
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->firstOrFail();

        $teacher->assignedStudents()->detach($student->id);

        return $this->success(['message' => 'Student unassigned from teacher successfully!']);
    }

    public function destroy(Request $request, int $assignment): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;

        $record = DB::table('child_teacher')
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->where('id', $assignment)
            ->first();

        if (!$record) {
            return $this->error('Assignment not found.', [], 404);
        }

        DB::table('child_teacher')
            ->where('id', $assignment)
            ->delete();

        return $this->success(['message' => 'Assignment removed successfully!']);
    }

    public function assignments(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;

        $assignments = DB::table('child_teacher')
            ->join('children', 'child_teacher.child_id', '=', 'children.id')
            ->join('users as teachers', 'child_teacher.teacher_id', '=', 'teachers.id')
            ->join('users as students_parents', 'children.user_id', '=', 'students_parents.id')
            ->join('users as assigned_by_users', 'child_teacher.assigned_by', '=', 'assigned_by_users.id')
            ->when($orgId, fn($q) => $q->where('child_teacher.organization_id', $orgId))
            ->select(
                'child_teacher.id as assignment_id',
                'children.id as child_id',
                'children.child_name',
                'teachers.id as teacher_id',
                'teachers.name as teacher_name',
                'students_parents.name as parent_name',
                'students_parents.email as parent_email',
                'assigned_by_users.name as assigned_by_name',
                'child_teacher.assigned_at',
                'child_teacher.notes'
            )
            ->orderBy('child_teacher.assigned_at', 'desc')
            ->paginate(ApiPagination::perPage($request, 50));

        return $this->paginated($assignments, $assignments->items());
    }
}
