<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\ApiController;
use App\Models\Course;
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
