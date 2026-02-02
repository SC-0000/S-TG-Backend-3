<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Assessments\AssessmentQuestionAttachRequest;
use App\Models\Assessment;
use App\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssessmentQuestionController extends ApiController
{
    public function index(Request $request, Assessment $assessment): JsonResponse
    {
        if ($response = $this->ensureAssessmentScope($request, $assessment)) {
            return $response;
        }

        $questions = $assessment->getAllQuestions();

        return $this->success([
            'questions' => $questions,
            'total_marks' => array_sum(array_map(fn ($q) => (int) ($q['marks'] ?? 0), $questions)),
            'question_count' => count($questions),
        ]);
    }

    public function attach(AssessmentQuestionAttachRequest $request, Assessment $assessment): JsonResponse
    {
        if ($response = $this->ensureAssessmentScope($request, $assessment)) {
            return $response;
        }

        $validated = $request->validated();
        $items = $validated['items'] ?? [];
        $questionIds = $validated['question_ids'] ?? array_map(
            fn ($item) => $item['question_id'],
            $items
        );

        $questionIds = array_values(array_unique(array_map('intval', $questionIds)));
        if (empty($questionIds)) {
            return $this->error('No questions provided.', [], 422);
        }

        $user = $request->user();
        $orgId = $request->attributes->get('organization_id');

        if (!$user?->isSuperAdmin()) {
            $validCount = Question::whereIn('id', $questionIds)
                ->where('organization_id', $orgId)
                ->count();

            if ($validCount !== count($questionIds)) {
                return $this->error('One or more questions are not in your organization.', [], 422);
            }
        }

        $currentMaxOrder = $assessment->bankQuestions()->max('order_position') ?? 0;
        $pivotData = [];

        if (!empty($items)) {
            foreach ($items as $index => $item) {
                $pivotData[$item['question_id']] = [
                    'order_position' => $item['order_position'] ?? ($currentMaxOrder + $index + 1),
                    'custom_points' => $item['custom_points'] ?? null,
                    'custom_settings' => $item['custom_settings'] ?? null,
                ];
            }
        } else {
            foreach ($questionIds as $index => $questionId) {
                $pivotData[$questionId] = [
                    'order_position' => $currentMaxOrder + $index + 1,
                    'custom_points' => null,
                    'custom_settings' => null,
                ];
            }
        }

        $assessment->bankQuestions()->syncWithoutDetaching($pivotData);

        $questions = $assessment->getAllQuestions();

        return $this->success([
            'message' => 'Questions attached successfully.',
            'questions' => $questions,
            'total_marks' => array_sum(array_map(fn ($q) => (int) ($q['marks'] ?? 0), $questions)),
            'question_count' => count($questions),
        ]);
    }

    public function detach(Request $request, Assessment $assessment, Question $question): JsonResponse
    {
        if ($response = $this->ensureAssessmentScope($request, $assessment)) {
            return $response;
        }

        $assessment->bankQuestions()->detach($question->id);

        $questions = $assessment->getAllQuestions();

        return $this->success([
            'message' => 'Question removed successfully.',
            'questions' => $questions,
            'total_marks' => array_sum(array_map(fn ($q) => (int) ($q['marks'] ?? 0), $questions)),
            'question_count' => count($questions),
        ]);
    }

    private function ensureAssessmentScope(Request $request, Assessment $assessment): ?JsonResponse
    {
        $user = $request->user();
        if ($user?->isSuperAdmin()) {
            return null;
        }

        $orgId = $request->attributes->get('organization_id');
        if ($assessment->is_global || (int) $assessment->organization_id === (int) $orgId) {
            return null;
        }

        return $this->error('Not found.', [], 404);
    }
}
