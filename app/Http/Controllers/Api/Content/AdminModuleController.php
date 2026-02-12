<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\ApiController;
use App\Models\Course;
use App\Models\Module;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminModuleController extends ApiController
{
    private function resolveOrgId(Request $request): ?int
    {
        $orgId = $request->header('X-Organization-Id') ?? $request->query('organization_id');
        return $orgId ? (int) $orgId : null;
    }

    public function store(Request $request, Course $course): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $course->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'order_position' => 'nullable|integer|min:0',
            'metadata' => 'nullable|array',
        ]);

        $module = Module::create([
            'course_id' => $course->id,
            'organization_id' => $course->organization_id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'order_position' => $validated['order_position'] ?? ($course->modules()->max('order_position') + 1),
            'status' => 'draft',
            'metadata' => $validated['metadata'] ?? [],
        ]);

        return $this->success([
            'module' => $module,
        ], status: 201);
    }

    public function update(Request $request, Module $module): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $module->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'order_position' => 'nullable|integer|min:0',
            'status' => 'nullable|in:draft,published,archived,live',
            'metadata' => 'nullable|array',
        ]);

        $module->update($validated);

        return $this->success([
            'module' => $module->fresh(),
        ]);
    }

    public function destroy(Request $request, Module $module): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $module->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $module->delete();

        return $this->success(['message' => 'Module deleted successfully.']);
    }

    public function reorder(Request $request, Course $course): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $course->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $validated = $request->validate([
            'module_ids' => 'required|array',
            'module_ids.*' => 'integer|exists:modules,id',
        ]);

        DB::transaction(function () use ($course, $validated) {
            foreach ($validated['module_ids'] as $index => $id) {
                $course->modules()->where('id', $id)->update(['order_position' => $index]);
            }
        });

        return $this->success(['message' => 'Modules reordered successfully.']);
    }

    public function publish(Request $request, Module $module): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $module->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        DB::transaction(function () use ($module) {
            $module->update(['status' => 'live']);
            $module->lessons()->update(['status' => 'live']);
        });

        return $this->success(['message' => 'Module published successfully.']);
    }

    public function duplicate(Request $request, Module $module): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $module->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $newModule = $module->replicate();
        $newModule->title = $module->title . ' (Copy)';
        $newModule->status = 'draft';
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

        return $this->success([
            'module' => $newModule->fresh(),
        ], status: 201);
    }

    public function attachLesson(Request $request, Module $module): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $module->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $validated = $request->validate([
            'lesson_id' => 'required|integer|exists:new_lessons,id',
            'order_position' => 'nullable|integer|min:0',
        ]);

        if ($module->lessons()->where('content_lesson_id', $validated['lesson_id'])->exists()) {
            return $this->error('Lesson already attached to this module.', [], 422);
        }

        $module->lessons()->attach($validated['lesson_id'], [
            'order_position' => $validated['order_position'] ?? (($module->lessons()->max('content_lesson_module.order_position') ?? 0) + 1),
        ]);

        return $this->success(['message' => 'Lesson attached successfully.']);
    }

    public function detachLesson(Request $request, Module $module, int $lessonId): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $module->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $module->lessons()->detach($lessonId);

        return $this->success(['message' => 'Lesson detached successfully.']);
    }

    public function attachAssessment(Request $request, Module $module): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $module->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $validated = $request->validate([
            'assessment_id' => 'required|integer|exists:assessments,id',
            'timing' => 'nullable|string',
        ]);

        if ($module->assessments()->where('assessment_id', $validated['assessment_id'])->exists()) {
            return $this->error('Assessment already attached to this module.', [], 422);
        }

        $module->assessments()->attach($validated['assessment_id'], [
            'timing' => $validated['timing'] ?? null,
        ]);

        return $this->success(['message' => 'Assessment attached successfully.']);
    }

    public function detachAssessment(Request $request, Module $module, int $assessmentId): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $module->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $module->assessments()->detach($assessmentId);

        return $this->success(['message' => 'Assessment detached successfully.']);
    }
}
