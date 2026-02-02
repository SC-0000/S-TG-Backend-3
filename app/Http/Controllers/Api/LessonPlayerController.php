<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\LessonPlayer\LessonProgressUpdateRequest;
use App\Http\Requests\Api\LessonPlayer\SlideConfidenceRequest;
use App\Http\Requests\Api\LessonPlayer\SlideInteractionRequest;
use App\Http\Resources\LessonProgressResource;
use App\Http\Resources\StudentQuestionResource;
use App\Models\Access;
use App\Models\Child;
use App\Models\ContentLesson;
use App\Models\LessonProgress;
use App\Models\LessonSlide;
use App\Models\Question;
use App\Models\SlideInteraction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LessonPlayerController extends ApiController
{
    public function start(Request $request, ContentLesson $lesson): JsonResponse
    {
        $child = $this->resolveChild($request);
        if ($child instanceof JsonResponse) {
            return $child;
        }

        if ($response = $this->ensureLessonAccess($request, $child, $lesson)) {
            return $response;
        }

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

        return $this->success([
            'lesson_id' => $lesson->id,
            'child_id' => $child->id,
            'progress' => (new LessonProgressResource($progress))->resolve(),
        ]);
    }

    public function show(Request $request, ContentLesson $lesson): JsonResponse
    {
        $child = $this->resolveChild($request);
        if ($child instanceof JsonResponse) {
            return $child;
        }

        if ($response = $this->ensureLessonAccess($request, $child, $lesson)) {
            return $response;
        }

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

        $lesson->load(['slides' => function ($query) {
            $query->orderBy('order_position');
        }]);

        $slides = $lesson->slides->map(function ($slide) {
            $blocks = $this->expandBlocks($slide->blocks ?? []);

            return [
                'id' => $slide->id,
                'uid' => $slide->uid,
                'title' => $slide->title,
                'order_position' => $slide->order_position,
                'blocks' => $blocks,
                'layout_settings' => $slide->layout_settings,
                'settings' => $slide->settings,
                'estimated_seconds' => $slide->estimated_seconds,
                'min_time_seconds' => $slide->min_time_seconds,
                'auto_advance' => (bool) $slide->auto_advance,
            ];
        });

        return $this->success([
            'lesson' => [
                'id' => $lesson->id,
                'uid' => $lesson->uid,
                'title' => $lesson->title,
                'description' => $lesson->description,
                'lesson_type' => $lesson->lesson_type,
                'estimated_minutes' => $lesson->estimated_minutes,
                'enable_ai_help' => (bool) $lesson->enable_ai_help,
                'enable_tts' => (bool) $lesson->enable_tts,
                'slides' => $slides,
            ],
            'progress' => (new LessonProgressResource($progress))->resolve(),
        ]);
    }

    public function summary(Request $request, ContentLesson $lesson): JsonResponse
    {
        $child = $this->resolveChild($request);
        if ($child instanceof JsonResponse) {
            return $child;
        }

        if ($response = $this->ensureLessonAccess($request, $child, $lesson)) {
            return $response;
        }

        $progress = LessonProgress::where('child_id', $child->id)
            ->where('lesson_id', $lesson->id)
            ->firstOrFail();

        $lesson->load(['slides', 'assessments']);

        $difficultSlides = SlideInteraction::where('child_id', $child->id)
            ->where('lesson_progress_id', $progress->id)
            ->where('flagged_difficult', true)
            ->count();

        return $this->success([
            'lesson' => [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'description' => $lesson->description,
            ],
            'progress' => (new LessonProgressResource($progress))->resolve(),
            'stats' => [
                'total_slides' => $lesson->slides->count(),
                'slides_viewed' => count($progress->slides_viewed ?? []),
                'difficult_slides' => $difficultSlides,
            ],
        ]);
    }

    public function getSlide(Request $request, ContentLesson $lesson, LessonSlide $slide): JsonResponse
    {
        $child = $this->resolveChild($request);
        if ($child instanceof JsonResponse) {
            return $child;
        }

        if ($response = $this->ensureLessonAccess($request, $child, $lesson)) {
            return $response;
        }

        if ($slide->lesson_id !== $lesson->id) {
            return $this->error('Slide not found for this lesson.', [], 404);
        }

        $blocks = $this->expandBlocks($slide->blocks ?? []);

        return $this->success([
            'slide' => [
                'id' => $slide->id,
                'uid' => $slide->uid,
                'title' => $slide->title,
                'order_position' => $slide->order_position,
                'blocks' => $blocks,
                'layout_settings' => $slide->layout_settings,
                'settings' => $slide->settings,
                'estimated_seconds' => $slide->estimated_seconds,
                'min_time_seconds' => $slide->min_time_seconds,
                'auto_advance' => (bool) $slide->auto_advance,
            ],
        ]);
    }

    public function recordSlideView(Request $request, ContentLesson $lesson, LessonSlide $slide): JsonResponse
    {
        $child = $this->resolveChild($request);
        if ($child instanceof JsonResponse) {
            return $child;
        }

        if ($response = $this->ensureLessonAccess($request, $child, $lesson)) {
            return $response;
        }

        if ($slide->lesson_id !== $lesson->id) {
            return $this->error('Slide not found for this lesson.', [], 404);
        }

        $progress = LessonProgress::where('child_id', $child->id)
            ->where('lesson_id', $lesson->id)
            ->firstOrFail();

        $progress->markSlideViewed($slide->id);

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

        $interaction->increment('interactions_count');
        $interaction->update(['last_viewed_at' => now()]);

        return $this->success([
            'progress' => [
                'completion_percentage' => $progress->completion_percentage,
                'slides_viewed' => $progress->slides_viewed ?? [],
            ],
        ]);
    }

    public function updateProgress(LessonProgressUpdateRequest $request, ContentLesson $lesson): JsonResponse
    {
        $child = $this->resolveChild($request);
        if ($child instanceof JsonResponse) {
            return $child;
        }

        if ($response = $this->ensureLessonAccess($request, $child, $lesson)) {
            return $response;
        }

        $validated = $request->validated();

        $progress = LessonProgress::where('child_id', $child->id)
            ->where('lesson_id', $lesson->id)
            ->firstOrFail();

        $progress->updateTimeSpent($validated['time_spent_seconds']);

        if (isset($validated['last_slide_id'])) {
            $progress->update(['last_slide_id' => $validated['last_slide_id']]);
        } elseif (isset($validated['slide_id'])) {
            $progress->update(['last_slide_id' => $validated['slide_id']]);
        }

        if (isset($validated['slides_viewed'])) {
            $progress->update(['slides_viewed' => $validated['slides_viewed']]);
            $progress->checkCompletion();
        }

        $progress->update(['last_accessed_at' => now()]);

        if (isset($validated['slide_id'])) {
            $interaction = SlideInteraction::where('child_id', $child->id)
                ->where('slide_id', $validated['slide_id'])
                ->where('lesson_progress_id', $progress->id)
                ->first();

            if ($interaction) {
                $interaction->increment('time_spent_seconds', $validated['time_spent_seconds']);
                $interaction->update(['last_viewed_at' => now()]);
            }
        }

        $progress->refresh();

        return $this->success([
            'progress' => [
                'time_spent_seconds' => $progress->time_spent_seconds,
                'last_slide_id' => $progress->last_slide_id,
                'slides_viewed' => $progress->slides_viewed ?? [],
                'completion_percentage' => $progress->completion_percentage,
                'last_accessed_at' => $progress->last_accessed_at?->toISOString(),
            ],
        ]);
    }

    public function recordInteraction(SlideInteractionRequest $request, ContentLesson $lesson, LessonSlide $slide): JsonResponse
    {
        $child = $this->resolveChild($request);
        if ($child instanceof JsonResponse) {
            return $child;
        }

        if ($response = $this->ensureLessonAccess($request, $child, $lesson)) {
            return $response;
        }

        if ($slide->lesson_id !== $lesson->id) {
            return $this->error('Slide not found for this lesson.', [], 404);
        }

        $validated = $request->validated();

        $progress = LessonProgress::where('child_id', $child->id)
            ->where('lesson_id', $lesson->id)
            ->firstOrFail();

        $interaction = SlideInteraction::where('child_id', $child->id)
            ->where('slide_id', $slide->id)
            ->where('lesson_progress_id', $progress->id)
            ->first();

        if (!$interaction) {
            $interaction = SlideInteraction::create([
                'child_id' => $child->id,
                'slide_id' => $slide->id,
                'lesson_progress_id' => $progress->id,
                'first_viewed_at' => now(),
                'interactions_count' => 0,
            ]);
        }

        switch ($validated['interaction_type']) {
            case 'help_request':
                $interaction->addHelpRequest('general', $validated['data'] ?? []);
                break;
            case 'flag_difficult':
                $interaction->update(['flagged_difficult' => true]);
                break;
            case 'hint_used':
            case 'tts_used':
                $interaction->addHelpRequest($validated['interaction_type'], $validated['data'] ?? []);
                break;
        }

        return $this->success(['message' => 'Interaction recorded.']);
    }

    public function submitConfidence(SlideConfidenceRequest $request, ContentLesson $lesson, LessonSlide $slide): JsonResponse
    {
        $child = $this->resolveChild($request);
        if ($child instanceof JsonResponse) {
            return $child;
        }

        if ($response = $this->ensureLessonAccess($request, $child, $lesson)) {
            return $response;
        }

        if ($slide->lesson_id !== $lesson->id) {
            return $this->error('Slide not found for this lesson.', [], 404);
        }

        $validated = $request->validated();

        $progress = LessonProgress::where('child_id', $child->id)
            ->where('lesson_id', $lesson->id)
            ->firstOrFail();

        $interaction = SlideInteraction::where('child_id', $child->id)
            ->where('slide_id', $slide->id)
            ->where('lesson_progress_id', $progress->id)
            ->first();

        if (!$interaction) {
            $interaction = SlideInteraction::create([
                'child_id' => $child->id,
                'slide_id' => $slide->id,
                'lesson_progress_id' => $progress->id,
                'first_viewed_at' => now(),
            ]);
        }

        $interaction->setConfidenceRating($validated['rating']);

        return $this->success(['message' => 'Confidence recorded.']);
    }

    public function complete(Request $request, ContentLesson $lesson): JsonResponse
    {
        $child = $this->resolveChild($request);
        if ($child instanceof JsonResponse) {
            return $child;
        }

        if ($response = $this->ensureLessonAccess($request, $child, $lesson)) {
            return $response;
        }

        $progress = LessonProgress::where('child_id', $child->id)
            ->where('lesson_id', $lesson->id)
            ->firstOrFail();

        $progress->checkCompletion();

        $progress->refresh();

        return $this->success([
            'completed' => $progress->status === 'completed',
            'progress' => [
                'status' => $progress->status,
                'completion_percentage' => $progress->completion_percentage,
                'score' => $progress->score,
                'completed_at' => $progress->completed_at?->toISOString(),
            ],
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

    private function ensureLessonAccess(Request $request, Child $child, ContentLesson $lesson): ?JsonResponse
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

    private function expandBlocks(array $blocks): array
    {
        if (empty($blocks)) {
            return [];
        }

        $questionIds = [];
        foreach ($blocks as $block) {
            $type = $block['type'] ?? null;
            $content = $block['content'] ?? [];
            if ($type === 'question' && isset($content['question_id'])) {
                $questionIds[] = (int) $content['question_id'];
            }
            if ($type === 'QuestionBlock' && !empty($content['question_ids'])) {
                foreach ((array) $content['question_ids'] as $id) {
                    $questionIds[] = (int) $id;
                }
            }
        }

        $questions = Question::whereIn('id', array_unique($questionIds))
            ->get()
            ->keyBy('id');

        $questionResources = [];
        foreach ($questions as $question) {
            $questionResources[$question->id] = (new StudentQuestionResource($question))->resolve();
        }

        foreach ($blocks as &$block) {
            $type = $block['type'] ?? null;
            $content = $block['content'] ?? [];

            if ($type === 'question' && isset($content['question_id'])) {
                $questionId = (int) $content['question_id'];
                if (isset($questionResources[$questionId])) {
                    $content['selected_question'] = $questionResources[$questionId];
                }
                $block['content'] = $content;
            }

            if ($type === 'QuestionBlock') {
                $ids = (array) ($content['question_ids'] ?? []);
                $content['questions'] = collect($ids)
                    ->map(fn ($id) => $questionResources[(int) $id] ?? null)
                    ->filter()
                    ->values();
                $block['content'] = $content;
            }
        }

        unset($block);

        return $blocks;
    }
}
