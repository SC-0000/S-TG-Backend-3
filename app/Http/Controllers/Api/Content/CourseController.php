<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\ApiController;
use App\Models\Course;
use App\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourseController extends ApiController
{
    private function resolveOrgId(Request $request): ?int
    {
        $orgId = $request->header('X-Organization-Id')
            ?? $request->query('organization_id')
            ?? $request->attributes->get('organization_id');

        return $orgId ? (int) $orgId : null;
    }

    public function index(Request $request): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);

        $query = Course::query()
            ->visibleToOrg($orgId)
            ->published();

        if ($request->filled('year_group')) {
            $query->where('year_group', $request->year_group);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $courses = $query->orderBy('title')
            ->paginate(ApiPagination::perPage($request));

        $data = $courses->getCollection()->map(fn ($course) => $this->mapCourse($course))->all();

        return $this->paginated($courses, $data);
    }

    public function show(Request $request, Course $course): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);

        if (!$course->is_global && $orgId && (int) $course->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        if ($course->status !== 'published') {
            return $this->error('Not found.', [], 404);
        }

        $course->load([
            'modules.lessons:id,uid,title,description,estimated_minutes,status',
            'modules.assessments:id,title,description,status',
            'assessments:id,title,description,status',
        ]);

        $payload = $this->mapCourse($course);
        $payload['modules'] = $course->modules->map(fn ($module) => [
            'id' => $module->id,
            'uid' => $module->uid,
            'title' => $module->title,
            'description' => $module->description,
            'order_position' => $module->order_position,
            'status' => $module->status,
            'estimated_duration_minutes' => $module->estimated_duration_minutes,
            'lessons' => $module->lessons->map(fn ($lesson) => [
                'id' => $lesson->id,
                'uid' => $lesson->uid,
                'title' => $lesson->title,
                'description' => $lesson->description,
                'estimated_minutes' => $lesson->estimated_minutes,
                'status' => $lesson->status,
            ]),
            'assessments' => $module->assessments->map(fn ($assessment) => [
                'id' => $assessment->id,
                'title' => $assessment->title,
                'description' => $assessment->description,
                'status' => $assessment->status,
            ]),
        ]);

        return $this->success($payload);
    }

    public function modules(Request $request, Course $course): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if (!$course->is_global && $orgId && (int) $course->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $modules = $course->modules()->orderBy('order_position')->paginate(ApiPagination::perPage($request));

        $data = $modules->getCollection()->map(fn ($module) => [
            'id' => $module->id,
            'uid' => $module->uid,
            'title' => $module->title,
            'description' => $module->description,
            'order_position' => $module->order_position,
            'status' => $module->status,
            'estimated_duration_minutes' => $module->estimated_duration_minutes,
        ])->all();

        return $this->paginated($modules, $data);
    }

    private function mapCourse(Course $course): array
    {
        return [
            'id' => $course->id,
            'uid' => $course->uid,
            'organization_id' => $course->organization_id,
            'is_global' => (bool) $course->is_global,
            'title' => $course->title,
            'description' => $course->description,
            'thumbnail' => $course->thumbnail,
            'cover_image' => $course->cover_image,
            'year_group' => $course->year_group,
            'category' => $course->category,
            'level' => $course->level,
            'status' => $course->status,
            'estimated_duration_minutes' => $course->estimated_duration_minutes,
            'is_featured' => (bool) $course->is_featured,
        ];
    }
}
