<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\ApiController;
use App\Models\ContentLesson;
use App\Models\Module;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Support\ApiPagination;

class AdminContentLessonController extends ApiController
{
    private function resolveOrgId(Request $request): ?int
    {
        $orgId = $request->header('X-Organization-Id') ?? $request->query('organization_id');
        return $orgId ? (int) $orgId : null;
    }

    public function store(Request $request, Module $module): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $module->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'year_group' => 'nullable|string|max:50',
            'lesson_type' => 'nullable|string|max:50',
            'delivery_mode' => 'nullable|string|max:50',
            'estimated_minutes' => 'nullable|integer|min:0',
            'completion_rules' => 'nullable|array',
            'enable_ai_help' => 'nullable|boolean',
            'enable_tts' => 'nullable|boolean',
            'journey_category_id' => 'nullable|exists:journey_categories,id',
            'order_position' => 'nullable|integer|min:0',
        ]);

        $lesson = ContentLesson::create([
            'organization_id' => $module->organization_id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'year_group' => $validated['year_group'] ?? null,
            'lesson_type' => $validated['lesson_type'] ?? null,
            'delivery_mode' => $validated['delivery_mode'] ?? null,
            'status' => 'draft',
            'estimated_minutes' => $validated['estimated_minutes'] ?? null,
            'completion_rules' => $validated['completion_rules'] ?? null,
            'enable_ai_help' => $request->boolean('enable_ai_help'),
            'enable_tts' => $request->boolean('enable_tts'),
            'journey_category_id' => $validated['journey_category_id'] ?? null,
        ]);

        $module->lessons()->attach($lesson->id, [
            'order_position' => $validated['order_position'] ?? ($module->lessons()->max('order_position') + 1),
        ]);

        return $this->success([
            'lesson' => $lesson,
        ], status: 201);
    }

    public function storeStandalone(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'year_group' => 'nullable|string|max:50',
            'lesson_type' => 'nullable|string|max:50',
            'delivery_mode' => 'nullable|string|max:50',
            'estimated_minutes' => 'nullable|integer|min:0',
            'completion_rules' => 'nullable|array',
            'enable_ai_help' => 'nullable|boolean',
            'enable_tts' => 'nullable|boolean',
            'journey_category_id' => 'nullable|exists:journey_categories,id',
            'organization_id' => 'nullable|integer|exists:organizations,id',
        ]);

        $user = $request->user();
        $organizationId = $validated['organization_id'] ?? null;

        if ($user?->role !== 'super_admin') {
            $organizationId = $user?->current_organization_id;
        }

        if ($user?->role === 'super_admin' && !$organizationId) {
            throw ValidationException::withMessages([
                'organization_id' => 'Organization is required.',
            ]);
        }

        $lesson = ContentLesson::create([
            'organization_id' => $organizationId,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'year_group' => $validated['year_group'] ?? null,
            'lesson_type' => $validated['lesson_type'] ?? null,
            'delivery_mode' => $validated['delivery_mode'] ?? null,
            'status' => 'draft',
            'estimated_minutes' => $validated['estimated_minutes'] ?? null,
            'completion_rules' => $validated['completion_rules'] ?? null,
            'enable_ai_help' => $request->boolean('enable_ai_help'),
            'enable_tts' => $request->boolean('enable_tts'),
            'journey_category_id' => $validated['journey_category_id'] ?? null,
        ]);

        return $this->success([
            'lesson' => $lesson,
        ], status: 201);
    }

    public function index(Request $request): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        $user = $request->user();

        $query = ContentLesson::query()
            ->with(['modules.course', 'organization'])
            ->when($user?->role === 'super_admin', function ($q) use ($request) {
                if ($request->filled('organization_id')) {
                    $q->where('organization_id', $request->integer('organization_id'));
                }
            }, function ($q) use ($orgId) {
                if ($orgId) {
                    $q->where('organization_id', $orgId);
                }
            });

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $lessons = $query->orderBy('created_at', 'desc')
            ->paginate(ApiPagination::perPage($request, 20));

        $data = $lessons->getCollection()->map(function ($lesson) {
            $module = $lesson->modules->first();
            return [
                'id' => $lesson->id,
                'uid' => $lesson->uid,
                'title' => $lesson->title,
                'description' => $lesson->description,
                'lesson_type' => $lesson->lesson_type,
                'status' => $lesson->status,
                'module' => $module ? [
                    'id' => $module->id,
                    'title' => $module->title,
                    'course' => $module->course ? [
                        'id' => $module->course->id,
                        'title' => $module->course->title,
                    ] : null,
                ] : null,
            ];
        })->all();

        return $this->paginated($lessons, $data);
    }

    public function show(Request $request, ContentLesson $lesson): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $lesson->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $lesson->load([
            'modules.course.journeyCategory.journey',
            'journeyCategory.journey',
            'slides',
            'assessments',
        ]);

        $module = $lesson->modules->first();

        return $this->success([
            'lesson' => [
                'id' => $lesson->id,
                'uid' => $lesson->uid,
                'title' => $lesson->title,
                'description' => $lesson->description,
                'year_group' => $lesson->year_group,
                'lesson_type' => $lesson->lesson_type,
                'delivery_mode' => $lesson->delivery_mode,
                'status' => $lesson->status,
                'estimated_minutes' => $lesson->estimated_minutes,
                'enable_ai_help' => $lesson->enable_ai_help,
                'enable_tts' => $lesson->enable_tts,
                'journey_category_id' => $lesson->journey_category_id,
                'created_at' => $lesson->created_at?->toDateTimeString(),
                'updated_at' => $lesson->updated_at?->toDateTimeString(),
                'slides_count' => $lesson->slides->count(),
                'slides' => $lesson->slides->map(function ($slide) {
                    return [
                        'id' => $slide->id,
                        'title' => $slide->title,
                        'order_position' => $slide->order_position,
                        'blocks_count' => is_array($slide->blocks) ? count($slide->blocks) : 0,
                    ];
                })->values(),
                'assessments' => $lesson->assessments->map(fn ($assessment) => [
                    'id' => $assessment->id,
                    'title' => $assessment->title,
                    'description' => $assessment->description,
                ])->values(),
                'module' => $module ? [
                    'id' => $module->id,
                    'title' => $module->title,
                    'course' => $module->course ? [
                        'id' => $module->course->id,
                        'title' => $module->course->title,
                        'journey_category' => $module->course->journeyCategory ? [
                            'id' => $module->course->journeyCategory->id,
                            'name' => $module->course->journeyCategory->name,
                            'journey' => $module->course->journeyCategory->journey ? [
                                'id' => $module->course->journeyCategory->journey->id,
                                'name' => $module->course->journeyCategory->journey->name,
                            ] : null,
                        ] : null,
                    ] : null,
                ] : null,
                'journey_category' => $lesson->journeyCategory ? [
                    'id' => $lesson->journeyCategory->id,
                    'name' => $lesson->journeyCategory->name,
                    'journey' => $lesson->journeyCategory->journey ? [
                        'id' => $lesson->journeyCategory->journey->id,
                        'name' => $lesson->journeyCategory->journey->name,
                    ] : null,
                ] : null,
                'is_standalone' => $lesson->modules->isEmpty(),
            ],
        ]);
    }

    public function update(Request $request, ContentLesson $lesson): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $lesson->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'year_group' => 'nullable|string|max:50',
            'lesson_type' => 'nullable|string|max:50',
            'delivery_mode' => 'nullable|string|max:50',
            'status' => 'nullable|in:draft,published,archived,live',
            'estimated_minutes' => 'nullable|integer|min:0',
            'completion_rules' => 'nullable|array',
            'enable_ai_help' => 'nullable|boolean',
            'enable_tts' => 'nullable|boolean',
            'module_id' => 'nullable|integer|exists:modules,id',
            'journey_category_id' => 'nullable|exists:journey_categories,id',
        ]);

        $lesson->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'year_group' => $validated['year_group'] ?? null,
            'lesson_type' => $validated['lesson_type'] ?? null,
            'delivery_mode' => $validated['delivery_mode'] ?? null,
            'status' => $validated['status'] ?? $lesson->status,
            'estimated_minutes' => $validated['estimated_minutes'] ?? $lesson->estimated_minutes,
            'completion_rules' => $validated['completion_rules'] ?? $lesson->completion_rules,
            'enable_ai_help' => $request->boolean('enable_ai_help', $lesson->enable_ai_help),
            'enable_tts' => $request->boolean('enable_tts', $lesson->enable_tts),
            'journey_category_id' => $validated['journey_category_id'] ?? $lesson->journey_category_id,
        ]);

        if (!empty($validated['module_id'])) {
            $module = Module::find($validated['module_id']);
            if ($module && (int) $module->organization_id !== (int) $lesson->organization_id) {
                throw ValidationException::withMessages([
                    'module_id' => 'Module organization mismatch.',
                ]);
            }

            $lesson->modules()->sync([
                $validated['module_id'] => ['order_position' => 0],
            ]);
        }

        return $this->success([
            'lesson' => $lesson->fresh(),
        ]);
    }

    public function destroy(Request $request, ContentLesson $lesson): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $lesson->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $lesson->delete();

        return $this->success(['message' => 'Lesson deleted successfully.']);
    }

    public function reorder(Request $request, Module $module): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $module->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $validated = $request->validate([
            'lesson_ids' => 'required|array',
            'lesson_ids.*' => 'integer|exists:new_lessons,id',
        ]);

        DB::transaction(function () use ($module, $validated) {
            foreach ($validated['lesson_ids'] as $index => $id) {
                $module->lessons()->updateExistingPivot($id, ['order_position' => $index]);
            }
        });

        return $this->success(['message' => 'Lessons reordered successfully.']);
    }

    public function publish(Request $request, ContentLesson $lesson): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $lesson->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $lesson->update(['status' => 'live']);

        return $this->success(['message' => 'Lesson published successfully.']);
    }

    public function duplicate(Request $request, ContentLesson $lesson): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $lesson->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $newLesson = $lesson->replicate();
        $newLesson->title = $lesson->title . ' (Copy)';
        $newLesson->status = 'draft';
        $newLesson->uid = null;
        $newLesson->save();

        foreach ($lesson->modules as $module) {
            $newLesson->modules()->attach($module->id, [
                'order_position' => $module->pivot->order_position,
            ]);
        }

        foreach ($lesson->assessments as $assessment) {
            $newLesson->assessments()->attach($assessment->id, [
                'order_position' => $assessment->pivot->order_position,
                'timing' => $assessment->pivot->timing,
            ]);
        }

        return $this->success([
            'lesson' => $newLesson->fresh(),
        ], status: 201);
    }

    public function attachAssessment(Request $request, ContentLesson $lesson): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $lesson->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $validated = $request->validate([
            'assessment_id' => 'required|integer|exists:assessments,id',
            'order_position' => 'nullable|integer|min:0',
            'timing' => 'nullable|string',
        ]);

        if ($lesson->assessments()->where('assessment_id', $validated['assessment_id'])->exists()) {
            return $this->error('Assessment already attached to this lesson.', [], 422);
        }

        $lesson->assessments()->attach($validated['assessment_id'], [
            'order_position' => $validated['order_position'] ?? 0,
            'timing' => $validated['timing'] ?? null,
        ]);

        return $this->success(['message' => 'Assessment attached successfully.']);
    }

    public function detachAssessment(Request $request, ContentLesson $lesson, int $assessmentId): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $lesson->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $lesson->assessments()->detach($assessmentId);

        return $this->success(['message' => 'Assessment detached successfully.']);
    }
}
