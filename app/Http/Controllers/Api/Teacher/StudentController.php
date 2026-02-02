<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\TeacherStudentResource;
use App\Models\Child;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $teacher = $request->user();
        if (!$teacher) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $query = $teacher->assignedStudents()
            ->with([
                'user',
                'attendances',
                'assessmentSubmissions',
                'lessonProgress',
                'assignedTeachers' => function ($q) {
                    $q->select('users.id', 'users.name');
                },
            ]);

        ApiQuery::applyFilters($query, $request, [
            'year_group' => true,
        ]);

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('child_name', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $students = $query->paginate(ApiPagination::perPage($request, 20));
        $data = TeacherStudentResource::collection($students->items())->resolve();

        return $this->paginated($students, $data);
    }

    public function show(Request $request, Child $child): JsonResponse
    {
        $teacher = $request->user();
        if (!$teacher) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $hasAccess = $teacher->assignedStudents()
            ->where('children.id', $child->id)
            ->exists();

        if (!$hasAccess) {
            return $this->error('Unauthorized access to student details.', [], 403);
        }

        $teacherId = $teacher->id;

        $child->load([
            'user',
            'assignedTeachers' => function ($q) {
                $q->select('users.id', 'users.name')
                    ->withPivot(['assigned_at', 'assigned_by', 'notes']);
            },
            'lessonProgress' => function ($q) use ($teacherId) {
                $q->whereHas('lesson.liveSessions', function ($sq) use ($teacherId) {
                    $sq->where('teacher_id', $teacherId);
                });
            },
            'assessmentSubmissions' => function ($q) use ($teacherId) {
                $q->whereHas('assessment.lesson', function ($sq) use ($teacherId) {
                    $sq->where('teacher_id', $teacherId);
                })->latest();
            },
        ]);

        $data = (new TeacherStudentResource($child))->resolve();

        return $this->success($data);
    }
}
