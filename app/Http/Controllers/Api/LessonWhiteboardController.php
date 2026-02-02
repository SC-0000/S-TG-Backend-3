<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\LessonPlayer\WhiteboardLoadRequest;
use App\Http\Requests\Api\LessonPlayer\WhiteboardSaveRequest;
use App\Models\Access;
use App\Models\Child;
use App\Models\LessonProgress;
use App\Models\LessonSlide;
use App\Models\SlideInteraction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LessonWhiteboardController extends ApiController
{
    public function save(WhiteboardSaveRequest $request, LessonSlide $slide): JsonResponse
    {
        $child = $this->resolveChild($request);
        if ($child instanceof JsonResponse) {
            return $child;
        }

        $lesson = $slide->lesson;
        if (!$lesson) {
            return $this->error('Lesson not found.', [], 404);
        }

        if ($response = $this->ensureLessonAccess($request, $child, $lesson)) {
            return $response;
        }

        $validated = $request->validated();

        $progress = LessonProgress::firstOrCreate(
            [
                'child_id' => $child->id,
                'lesson_id' => $lesson->id,
            ],
            [
                'status' => 'in_progress',
                'started_at' => now(),
                'last_accessed_at' => now(),
                'slides_viewed' => [],
                'completion_percentage' => 0,
                'time_spent_seconds' => 0,
            ]
        );

        $interaction = SlideInteraction::firstOrCreate(
            [
                'child_id' => $child->id,
                'slide_id' => $slide->id,
                'lesson_progress_id' => $progress->id,
            ],
            [
                'first_viewed_at' => now(),
                'interactions_count' => 0,
                'time_spent_seconds' => 0,
            ]
        );

        $blockInteractions = $interaction->block_interactions ?? [];
        $blockInteractions[$validated['block_id']] = [
            'type' => 'whiteboard',
            'canvas_data' => $validated['canvas_data'],
            'timestamp' => now()->toISOString(),
        ];

        $interaction->block_interactions = $blockInteractions;
        $interaction->save();

        return $this->success([
            'message' => 'Whiteboard saved successfully.',
            'timestamp' => $blockInteractions[$validated['block_id']]['timestamp'],
        ]);
    }

    public function load(WhiteboardLoadRequest $request, LessonSlide $slide): JsonResponse
    {
        $child = $this->resolveChild($request);
        if ($child instanceof JsonResponse) {
            return $child;
        }

        $lesson = $slide->lesson;
        if (!$lesson) {
            return $this->error('Lesson not found.', [], 404);
        }

        if ($response = $this->ensureLessonAccess($request, $child, $lesson)) {
            return $response;
        }

        $validated = $request->validated();

        $interaction = SlideInteraction::where('child_id', $child->id)
            ->where('slide_id', $slide->id)
            ->latest('updated_at')
            ->first();

        if (!$interaction) {
            return $this->success([
                'canvas_data' => null,
                'timestamp' => null,
            ]);
        }

        $blockInteractions = $interaction->block_interactions ?? [];
        $block = $blockInteractions[$validated['block_id']] ?? null;

        return $this->success([
            'canvas_data' => $block['canvas_data'] ?? null,
            'timestamp' => $block['timestamp'] ?? null,
        ]);
    }

    private function resolveChild(Request $request): Child|JsonResponse
    {
        $user = $request->user();
        $children = $user?->children ?? collect();

        if ($children->isEmpty()) {
            return $this->error('No child profile found.', [], 400);
        }

        if ($request->filled('child_id')) {
            $child = $children->firstWhere('id', $request->integer('child_id'));
            if (!$child) {
                return $this->error('Invalid child selection.', [], 422);
            }
        } elseif ($children->count() > 1) {
            return $this->error('child_id is required when multiple children exist.', [], 422);
        } else {
            $child = $children->first();
        }

        $orgId = $request->attributes->get('organization_id');
        if ($orgId && $child->organization_id && (int) $child->organization_id !== (int) $orgId) {
            return $this->error('Invalid organization context.', [], 403);
        }

        return $child;
    }

    private function ensureLessonAccess(Request $request, Child $child, $lesson): ?JsonResponse
    {
        $user = $request->user();
        if ($user && ($user->isAdmin() || $user->isTeacher() || $user->isSuperAdmin())) {
            return null;
        }

        if ($lesson->status !== 'live') {
            return $this->error('Lesson not found.', [], 404);
        }

        $orgId = $request->attributes->get('organization_id');
        if ($orgId && $lesson->organization_id && (int) $lesson->organization_id !== (int) $orgId) {
            return $this->error('Lesson not found.', [], 404);
        }

        $hasAccess = Access::forChild($child->id)
            ->where('access', true)
            ->where('payment_status', 'paid')
            ->withLessonAccess($lesson->id)
            ->exists();

        if (!$hasAccess) {
            return $this->error('You do not have access to this lesson.', [], 403);
        }

        return null;
    }
}
