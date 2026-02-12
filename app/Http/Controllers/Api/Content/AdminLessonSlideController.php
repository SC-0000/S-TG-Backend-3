<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\ApiController;
use App\Models\ContentLesson;
use App\Models\LessonSlide;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminLessonSlideController extends ApiController
{
    private function resolveOrgId(Request $request): ?int
    {
        $orgId = $request->header('X-Organization-Id') ?? $request->query('organization_id');
        return $orgId ? (int) $orgId : null;
    }

    public function show(Request $request, LessonSlide $slide): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && $slide->lesson && (int) $slide->lesson->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $slide->load('lesson:id,organization_id');

        return $this->success([
            'id' => $slide->id,
            'uid' => $slide->uid,
            'lesson_id' => $slide->lesson_id,
            'title' => $slide->title,
            'order_position' => $slide->order_position,
            'blocks' => $slide->blocks,
            'template_id' => $slide->template_id,
            'layout_settings' => $slide->layout_settings,
            'teacher_notes' => $slide->teacher_notes,
            'estimated_seconds' => $slide->estimated_seconds,
            'auto_advance' => (bool) $slide->auto_advance,
            'min_time_seconds' => $slide->min_time_seconds,
            'settings' => $slide->settings,
        ]);
    }


    public function store(Request $request, ContentLesson $lesson): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $lesson->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'order_position' => 'nullable|integer|min:0',
            'blocks' => 'nullable|array',
            'template_id' => 'nullable|string',
            'layout_settings' => 'nullable|array',
            'teacher_notes' => 'nullable|string',
            'estimated_seconds' => 'nullable|integer|min:0',
            'auto_advance' => 'nullable|boolean',
            'min_time_seconds' => 'nullable|integer|min:0',
            'settings' => 'nullable|array',
        ]);

        $slide = LessonSlide::create([
            'lesson_id' => $lesson->id,
            'title' => $validated['title'],
            'order_position' => $validated['order_position'] ?? ($lesson->slides()->max('order_position') + 1),
            'blocks' => $validated['blocks'] ?? [],
            'template_id' => $validated['template_id'] ?? null,
            'layout_settings' => $validated['layout_settings'] ?? null,
            'teacher_notes' => $validated['teacher_notes'] ?? null,
            'estimated_seconds' => $validated['estimated_seconds'] ?? 60,
            'auto_advance' => $request->boolean('auto_advance'),
            'min_time_seconds' => $validated['min_time_seconds'] ?? null,
            'settings' => $validated['settings'] ?? null,
        ]);

        return $this->success([
            'slide' => $slide,
        ], status: 201);
    }

    public function update(Request $request, LessonSlide $slide): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && $slide->lesson && (int) $slide->lesson->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'order_position' => 'nullable|integer|min:0',
            'blocks' => 'nullable|array',
            'template_id' => 'nullable|string',
            'layout_settings' => 'nullable|array',
            'teacher_notes' => 'nullable|string',
            'estimated_seconds' => 'nullable|integer|min:0',
            'auto_advance' => 'nullable|boolean',
            'min_time_seconds' => 'nullable|integer|min:0',
            'settings' => 'nullable|array',
        ]);

        $slide->update([
            'title' => $validated['title'],
            'order_position' => $validated['order_position'] ?? $slide->order_position,
            'blocks' => $validated['blocks'] ?? $slide->blocks,
            'template_id' => $validated['template_id'] ?? $slide->template_id,
            'layout_settings' => $validated['layout_settings'] ?? $slide->layout_settings,
            'teacher_notes' => $validated['teacher_notes'] ?? $slide->teacher_notes,
            'estimated_seconds' => $validated['estimated_seconds'] ?? $slide->estimated_seconds,
            'auto_advance' => $request->boolean('auto_advance', $slide->auto_advance),
            'min_time_seconds' => $validated['min_time_seconds'] ?? $slide->min_time_seconds,
            'settings' => $validated['settings'] ?? $slide->settings,
        ]);

        return $this->success([
            'slide' => $slide->fresh(),
        ]);
    }

    public function destroy(Request $request, LessonSlide $slide): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && $slide->lesson && (int) $slide->lesson->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $slide->delete();

        return $this->success(['message' => 'Slide deleted successfully.']);
    }

    public function reorder(Request $request, ContentLesson $lesson): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $lesson->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $validated = $request->validate([
            'slide_ids' => 'required|array',
            'slide_ids.*' => 'integer|exists:lesson_slides,id',
        ]);

        DB::transaction(function () use ($lesson, $validated) {
            foreach ($validated['slide_ids'] as $index => $id) {
                $lesson->slides()->where('id', $id)->update(['order_position' => $index]);
            }
        });

        return $this->success(['message' => 'Slides reordered successfully.']);
    }

    public function duplicate(Request $request, LessonSlide $slide): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && $slide->lesson && (int) $slide->lesson->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $newSlide = $slide->replicate();
        $newSlide->uid = null;
        $newSlide->order_position = $slide->order_position + 1;
        $newSlide->save();

        return $this->success([
            'slide' => $newSlide->fresh(),
        ], status: 201);
    }

    public function addBlock(Request $request, LessonSlide $slide): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && $slide->lesson && (int) $slide->lesson->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $validated = $request->validate([
            'block' => 'required|array',
        ]);

        $blocks = $slide->blocks ?? [];
        $blocks[] = $validated['block'];

        $slide->update(['blocks' => $blocks]);

        return $this->success(['blocks' => $slide->blocks]);
    }

    public function updateBlock(Request $request, LessonSlide $slide, int $blockId): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && $slide->lesson && (int) $slide->lesson->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $validated = $request->validate([
            'block' => 'required|array',
        ]);

        $blocks = $slide->blocks ?? [];
        if (!array_key_exists($blockId, $blocks)) {
            return $this->error('Block not found.', [], 404);
        }

        $blocks[$blockId] = $validated['block'];
        $slide->update(['blocks' => $blocks]);

        return $this->success(['blocks' => $slide->blocks]);
    }

    public function deleteBlock(Request $request, LessonSlide $slide, int $blockId): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && $slide->lesson && (int) $slide->lesson->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $blocks = $slide->blocks ?? [];
        if (!array_key_exists($blockId, $blocks)) {
            return $this->error('Block not found.', [], 404);
        }

        array_splice($blocks, $blockId, 1);
        $slide->update(['blocks' => $blocks]);

        return $this->success(['blocks' => $slide->blocks]);
    }
}
