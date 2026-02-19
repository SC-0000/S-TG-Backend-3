<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\ApiController;
use App\Models\Course;
use App\Models\ContentLesson;
use App\Models\Assessment;
use App\Models\LiveLessonSession;
use App\Models\User;
use App\Models\JourneyCategory;
use App\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class AdminCourseController extends ApiController
{
    private function resolveOrgId(Request $request): ?int
    {
        $orgId = $request->header('X-Organization-Id') ?? $request->query('organization_id');
        return $orgId ? (int) $orgId : null;
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $this->resolveOrgId($request);

        $query = Course::with(['organization', 'modules']);

        if ($user?->role === 'super_admin') {
            if ($orgId) {
                $query->where('organization_id', $orgId);
            }
        } else {
            $query->visibleToOrg($request->attributes->get('organization_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', '%' . $search . '%')
                    ->orWhere('description', 'like', '%' . $search . '%');
            });
        }

        $courses = $query->orderBy('created_at', 'desc')
            ->paginate(ApiPagination::perPage($request, 20));

        $data = $courses->getCollection()->map(function ($course) use ($user) {
            return [
                'id' => $course->id,
                'uid' => $course->uid,
                'title' => $course->title,
                'description' => $course->description,
                'thumbnail' => $course->thumbnail,
                'status' => $course->status,
                'modules_count' => $course->modules->count(),
                'estimated_duration_minutes' => $course->estimated_duration_minutes,
                'is_featured' => $course->is_featured,
                'organization' => $course->organization ? [
                    'id' => $course->organization->id,
                    'name' => $course->organization->name,
                ] : null,
                'created_at' => $course->created_at?->toISOString(),
                'created_by' => $course->created_by,
                'created_by_me' => $course->created_by === $user?->id,
            ];
        })->all();

        return $this->paginated($courses, $data);
    }

    public function show(Request $request, Course $course): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if (!$course->is_global && $orgId && (int) $course->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $course->load(['modules', 'organization']);

        return $this->success([
            'course' => [
                'id' => $course->id,
                'uid' => $course->uid,
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
                'is_global' => (bool) $course->is_global,
                'organization_id' => $course->organization_id,
                'journey_category_id' => $course->journey_category_id,
                'metadata' => $course->metadata ?? [],
                'created_by' => $course->created_by,
                'organization' => $course->organization ? [
                    'id' => $course->organization->id,
                    'name' => $course->organization->name,
                ] : null,
            ],
        ]);
    }

    public function editData(Request $request, Course $course): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if (!$course->is_global && $orgId && (int) $course->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $course->load([
            'modules' => function ($query) {
                $query->orderBy('order_position');
            },
            'modules.lessons' => function ($query) {
                $query->orderBy('order_position');
            },
            'modules.lessons.slides',
            'modules.lessons.liveSessions',
            'modules.assessments',
            'organization',
        ]);

        $filterOrgId = $course->organization_id ?? $orgId;

        $allLessons = ContentLesson::select('id', 'uid', 'title', 'description', 'estimated_minutes')
            ->when($filterOrgId, fn ($q, $orgId) => $q->where('organization_id', $orgId))
            ->orderBy('title')
            ->get();

        $allAssessments = Assessment::select('id', 'uid', 'title', 'description')
            ->when($filterOrgId, fn ($q, $orgId) => $q->where('organization_id', $orgId))
            ->orderBy('title')
            ->get();

        $courseLessonIds = $course->modules()
            ->with('lessons')
            ->get()
            ->pluck('lessons')
            ->flatten()
            ->pluck('id')
            ->unique()
            ->values();

        $allLiveSessions = LiveLessonSession::with('lesson:id,title,description')
            ->select('id', 'uid', 'lesson_id', 'course_id', 'scheduled_start_time', 'status', 'session_code')
            ->whereIn('lesson_id', $courseLessonIds)
            ->where('course_id', $course->id)
            ->orderBy('scheduled_start_time', 'desc')
            ->get()
            ->map(function ($session) {
                return [
                    'id' => $session->id,
                    'uid' => $session->uid,
                    'lesson_id' => $session->lesson_id,
                    'course_id' => $session->course_id,
                    'title' => $session->lesson ? $session->lesson->title : 'Untitled Session',
                    'description' => $session->lesson ? $session->lesson->description : '',
                    'scheduled_start_time' => $session->scheduled_start_time?->format('Y-m-d H:i:s'),
                    'status' => $session->status,
                    'session_code' => $session->session_code,
                ];
            });

        $teachers = User::where(function ($q) {
                $q->where('role', 'teacher')
                  ->orWhere('role', 'admin');
            })
            ->when($filterOrgId, fn ($q, $orgId) => $q->where('current_organization_id', $orgId))
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        $journeyCategories = JourneyCategory::with('journey')
            ->when(
                ($request->user()?->role === 'super_admin') ? null : $request->user()?->current_organization_id,
                fn ($q, $orgId) => $q->forOrganization($orgId)
            )
            ->orderBy('name')
            ->get();

        $organizations = $request->user()?->role === 'super_admin'
            ? \App\Models\Organization::select('id', 'name')->orderBy('name')->get()
            : null;

        return $this->success([
            'course' => [
                'id' => $course->id,
                'uid' => $course->uid,
                'title' => $course->title,
                'description' => $course->description,
                'thumbnail' => $course->thumbnail,
                'cover_image' => $course->cover_image,
                'status' => $course->status,
                'metadata' => $course->metadata,
                'created_at' => $course->created_at?->toISOString(),
                'journey_category_id' => $course->journey_category_id,
                'year_group' => $course->year_group,
                'organization_id' => $course->organization_id,
                'is_global' => (bool) $course->is_global,
                'modules' => $course->modules->map(function ($module) use ($course) {
                    return [
                        'id' => $module->id,
                        'uid' => $module->uid,
                        'title' => $module->title,
                        'description' => $module->description,
                        'order_position' => $module->order_position,
                        'status' => $module->status,
                        'lessons_count' => $module->lessons->count(),
                        'estimated_duration_minutes' => $module->estimated_duration_minutes,
                        'lessons' => $module->lessons->map(function ($lesson) use ($course) {
                            return [
                                'id' => $lesson->id,
                                'uid' => $lesson->uid,
                                'title' => $lesson->title,
                                'description' => $lesson->description,
                                'order_position' => $lesson->pivot->order_position ?? 0,
                                'status' => $lesson->status,
                                'lesson_type' => $lesson->lesson_type,
                                'estimated_minutes' => $lesson->estimated_minutes,
                                'slides_count' => $lesson->slides->count(),
                                'live_sessions' => $lesson->liveSessions
                                    ->where('course_id', $course->id)
                                    ->map(function ($session) {
                                        return [
                                            'id' => $session->id,
                                            'uid' => $session->uid,
                                            'session_code' => $session->session_code,
                                            'status' => $session->status,
                                            'scheduled_start_time' => $session->scheduled_start_time?->format('Y-m-d H:i:s'),
                                        ];
                                    }),
                            ];
                        }),
                        'assessments' => $module->assessments->map(function ($assessment) {
                            return [
                                'id' => $assessment->id,
                                'uid' => $assessment->uid,
                                'title' => $assessment->title,
                                'description' => $assessment->description,
                            ];
                        }),
                    ];
                }),
            ],
            'all_lessons' => $allLessons,
            'all_assessments' => $allAssessments,
            'all_live_sessions' => $allLiveSessions,
            'teachers' => $teachers,
            'journey_categories' => $journeyCategories,
            'organizations' => $organizations,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'year_group' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'thumbnail' => 'nullable|string',
            'cover_image' => 'nullable|string',
            'metadata' => 'nullable|array',
            'journey_category_id' => 'nullable|exists:journey_categories,id',
            'is_global' => 'nullable|boolean',
            'organization_id' => 'nullable|integer|exists:organizations,id',
        ]);

        $user = $request->user();
        $isSuperAdmin = $user->role === 'super_admin';
        $isGlobal = $isSuperAdmin && $request->boolean('is_global');
        $organizationId = $request->input('organization_id');

        if (!$isSuperAdmin) {
            $organizationId = $user->current_organization_id;
            $isGlobal = false;
        }

        if ($isGlobal) {
            $organizationId = null;
        }

        if ($isSuperAdmin && !$isGlobal && !$organizationId) {
            throw ValidationException::withMessages([
                'organization_id' => 'Organization is required unless the course is global.',
            ]);
        }

        $course = Course::create([
            'organization_id' => $organizationId,
            'is_global' => $isGlobal,
            'journey_category_id' => $validated['journey_category_id'] ?? null,
            'title' => $validated['title'],
            'year_group' => $validated['year_group'] ?? null,
            'description' => $validated['description'] ?? null,
            'thumbnail' => $validated['thumbnail'] ?? null,
            'cover_image' => $validated['cover_image'] ?? null,
            'status' => 'draft',
            'metadata' => $validated['metadata'] ?? [],
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return $this->success([
            'course' => $course,
        ], status: 201);
    }

    public function update(Request $request, Course $course): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if (!$course->is_global && $orgId && (int) $course->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'year_group' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'thumbnail' => 'nullable|string',
            'cover_image' => 'nullable|string',
            'metadata' => 'nullable|array',
            'journey_category_id' => 'nullable|exists:journey_categories,id',
            'status' => 'nullable|in:draft,published,archived,live',
        ]);

        $course->update($validated);

        return $this->success([
            'course' => $course->fresh(),
        ]);
    }

    public function destroy(Request $request, Course $course): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if (!$course->is_global && $orgId && (int) $course->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $course->delete();

        return $this->success(['message' => 'Course deleted successfully.']);
    }

    public function publish(Request $request, Course $course): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if (!$course->is_global && $orgId && (int) $course->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        DB::transaction(function () use ($course) {
            $course->update(['status' => 'live']);
            $course->modules()->update(['status' => 'live']);
        });

        return $this->success(['message' => 'Course published successfully.']);
    }

    public function archive(Request $request, Course $course): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if (!$course->is_global && $orgId && (int) $course->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $course->update(['status' => 'archived']);

        return $this->success(['message' => 'Course archived successfully.']);
    }

    public function duplicate(Request $request, Course $course): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if (!$course->is_global && $orgId && (int) $course->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $newCourse = $course->replicate();
        $newCourse->title = $course->title . ' (Copy)';
        $newCourse->status = 'draft';
        $newCourse->uid = null;
        $newCourse->save();

        foreach ($course->modules as $module) {
            $newModule = $module->replicate();
            $newModule->course_id = $newCourse->id;
            $newModule->uid = null;
            $newModule->save();

            foreach ($module->lessons as $lesson) {
                $newModule->lessons()->attach($lesson->id, [
                    'order_position' => $lesson->pivot->order_position,
                ]);
            }

            foreach ($module->assessments as $assessment) {
                $newModule->assessments()->attach($assessment->id, [
                    'timing' => $assessment->pivot->timing,
                ]);
            }
        }

        foreach ($course->assessments as $assessment) {
            $newCourse->assessments()->attach($assessment->id, [
                'timing' => $assessment->pivot->timing,
            ]);
        }

        return $this->success([
            'course' => $newCourse->fresh(),
        ], status: 201);
    }
}
