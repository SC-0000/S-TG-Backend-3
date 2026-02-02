<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\LessonPlayer\LessonQuestionSubmitRequest;
use App\Models\Access;
use App\Models\Child;
use App\Models\ContentLesson;
use App\Models\LessonProgress;
use App\Models\LessonQuestionResponse;
use App\Models\LessonSlide;
use App\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LessonQuestionController extends ApiController
{
    public function submitResponse(LessonQuestionSubmitRequest $request, ContentLesson $lesson, LessonSlide $slide): JsonResponse
    {
        $child = $this->resolveChild($request);
        if ($child instanceof JsonResponse) {
            return $child;
        }

        if ($response = $this->ensureLessonAccess($request, $child, $lesson)) {
            return $response;
        }

        if ($slide->lesson_id !== $lesson->id) {
            return $this->error('Slide not found in this lesson.', [], 404);
        }

        $validated = $request->validated();

        $progress = LessonProgress::where('child_id', $child->id)
            ->where('lesson_id', $lesson->id)
            ->firstOrFail();

        $question = Question::findOrFail($validated['question_id']);

        $existingAttempts = LessonQuestionResponse::where('child_id', $child->id)
            ->where('lesson_progress_id', $progress->id)
            ->where('question_id', $validated['question_id'])
            ->where('block_id', $validated['block_id'])
            ->count();

        $response = LessonQuestionResponse::create([
            'child_id' => $child->id,
            'lesson_progress_id' => $progress->id,
            'slide_id' => $slide->id,
            'block_id' => $validated['block_id'],
            'question_id' => $validated['question_id'],
            'answer_data' => $validated['answer_data'],
            'score_possible' => $question->marks,
            'attempt_number' => $existingAttempts + 1,
            'time_spent_seconds' => $validated['time_spent_seconds'],
            'hints_used' => $validated['hints_used'] ?? [],
            'answered_at' => now(),
        ]);

        $answerData = $validated['answer_data'];
        if ($question->question_type === 'mcq') {
            $selectedValue = null;

            if (is_array($answerData) && isset($answerData['selectedOption'])) {
                $selectedValue = $answerData['selectedOption'];
            } elseif (is_array($answerData) && isset($answerData['selected_options'])) {
                $selectedValue = $answerData['selected_options'];
            } else {
                $selectedValue = $answerData;
            }

            $selectedIndices = is_array($selectedValue) ? $selectedValue : [$selectedValue];
            $optionIds = [];

            $questionData = $question->question_data;
            foreach ($selectedIndices as $index) {
                if (is_numeric($index) && isset($questionData['options'][$index])) {
                    $optionIds[] = $questionData['options'][$index]['id'];
                } else {
                    $optionIds[] = $index;
                }
            }

            $answerData = [
                'selected_options' => $optionIds,
            ];
        } elseif (!is_array($answerData)) {
            $answerData = [$answerData];
        }

        Log::info('Grading question', [
            'question_id' => $question->id,
            'question_type' => $question->question_type,
        ]);

        $gradeResult = $question->gradeResponse($answerData);

        $response->update([
            'is_correct' => $gradeResult['is_correct'] ?? false,
            'score_earned' => $gradeResult['score'] ?? 0,
            'feedback' => $gradeResult['feedback'] ?? null,
        ]);

        $progress->increment('questions_attempted');
        if ($response->is_correct) {
            $progress->increment('questions_correct');
        }
        $this->updateProgressQuestionScore($progress);
        $progress->checkCompletion();

        return $this->success([
            'response' => [
                'id' => $response->id,
                'is_correct' => $response->is_correct,
                'score_earned' => $response->score_earned,
                'score_possible' => $response->score_possible,
                'feedback' => $response->feedback,
                'attempt_number' => $response->attempt_number,
                'grading_details' => $gradeResult['details'] ?? null,
            ],
            'progress' => [
                'questions_attempted' => $progress->questions_attempted,
                'questions_correct' => $progress->questions_correct,
                'questions_score' => $progress->questions_score,
                'completion_percentage' => $progress->completion_percentage,
            ],
        ]);
    }

    public function getResponses(Request $request, ContentLesson $lesson): JsonResponse
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

        $responses = LessonQuestionResponse::where('lesson_progress_id', $progress->id)
            ->with(['question', 'slide'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($response) {
                $questionText = $response->question->question_data['question_text'] ?? $response->question->title ?? null;
                return [
                    'id' => $response->id,
                    'question_id' => $response->question_id,
                    'question_text' => $questionText,
                    'slide_id' => $response->slide_id,
                    'slide_title' => $response->slide->title ?? null,
                    'block_id' => $response->block_id,
                    'answer_data' => $response->answer_data,
                    'is_correct' => $response->is_correct,
                    'score_earned' => $response->score_earned,
                    'score_possible' => $response->score_possible,
                    'attempt_number' => $response->attempt_number,
                    'feedback' => $response->feedback,
                    'answered_at' => $response->answered_at?->toISOString(),
                ];
            });

        return $this->success([
            'responses' => $responses,
            'summary' => [
                'total_attempted' => $progress->questions_attempted,
                'total_correct' => $progress->questions_correct,
                'accuracy_percentage' => $progress->questions_attempted > 0
                    ? round(($progress->questions_correct / $progress->questions_attempted) * 100, 1)
                    : 0,
                'average_score' => $progress->questions_score,
            ],
        ]);
    }

    public function getSlideResponses(Request $request, ContentLesson $lesson, LessonSlide $slide): JsonResponse
    {
        $child = $this->resolveChild($request);
        if ($child instanceof JsonResponse) {
            return $child;
        }

        if ($response = $this->ensureLessonAccess($request, $child, $lesson)) {
            return $response;
        }

        if ($slide->lesson_id !== $lesson->id) {
            return $this->error('Slide not found in this lesson.', [], 404);
        }

        $progress = LessonProgress::where('child_id', $child->id)
            ->where('lesson_id', $lesson->id)
            ->firstOrFail();

        $responses = LessonQuestionResponse::where('lesson_progress_id', $progress->id)
            ->where('slide_id', $slide->id)
            ->with('question')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($response) {
                return [
                    'id' => $response->id,
                    'question_id' => $response->question_id,
                    'block_id' => $response->block_id,
                    'answer_data' => $response->answer_data,
                    'is_correct' => $response->is_correct,
                    'score_earned' => $response->score_earned,
                    'score_possible' => $response->score_possible,
                    'attempt_number' => $response->attempt_number,
                    'feedback' => $response->feedback,
                ];
            });

        return $this->success([
            'responses' => $responses,
        ]);
    }

    public function retryQuestion(Request $request, ContentLesson $lesson, LessonQuestionResponse $response): JsonResponse
    {
        $child = $this->resolveChild($request);
        if ($child instanceof JsonResponse) {
            return $child;
        }

        if ($response = $this->ensureLessonAccess($request, $child, $lesson)) {
            return $response;
        }

        if ($response->child_id !== $child->id) {
            return $this->error('Unauthorized.', [], 403);
        }

        $slide = $response->slide;
        if (!$slide) {
            return $this->error('Slide not found.', [], 404);
        }

        $blocks = $slide->blocks ?? [];
        $questionBlock = collect($blocks)->firstWhere('id', $response->block_id);

        if (!$questionBlock) {
            return $this->error('Question block not found.', [], 404);
        }

        $retryAllowed = $questionBlock['content']['retry_allowed'] ?? false;
        $maxAttempts = $questionBlock['content']['max_attempts'] ?? null;

        if (!$retryAllowed) {
            return $this->error('Retries not allowed for this question.', [], 400);
        }

        $currentAttempts = LessonQuestionResponse::where('child_id', $child->id)
            ->where('lesson_progress_id', $response->lesson_progress_id)
            ->where('question_id', $response->question_id)
            ->where('block_id', $response->block_id)
            ->count();

        if ($maxAttempts && $currentAttempts >= $maxAttempts) {
            return $this->error('Maximum attempts reached.', [], 400);
        }

        return $this->success([
            'can_retry' => true,
            'attempts_used' => $currentAttempts,
            'max_attempts' => $maxAttempts,
            'question' => [
                'id' => $response->question->id,
                'question_text' => $response->question->question_data['question_text'] ?? $response->question->title ?? null,
                'question_type' => $response->question->question_type ?? null,
                'question_data' => $response->question->question_data ?? null,
            ],
        ]);
    }

    private function updateProgressQuestionScore(LessonProgress $progress): void
    {
        $responses = LessonQuestionResponse::where('lesson_progress_id', $progress->id)
            ->get();

        $totalPossible = $responses->sum('score_possible');
        $totalEarned = $responses->sum('score_earned');
        $score = $totalPossible > 0 ? ($totalEarned / $totalPossible) * 100 : 0;

        $progress->update([
            'questions_score' => round($score, 2),
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
}
