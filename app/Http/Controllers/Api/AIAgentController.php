<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\AIAgentController as BaseAIAgentController;
use App\Models\Child;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AIAgentController extends BaseAIAgentController
{
    use ApiResponse;

    public function tutorChat(Request $request): JsonResponse
    {
        if ($response = $this->ensureChildOrgScope($request, $request->integer('child_id'))) {
            return $response;
        }

        return $this->wrap(parent::tutorChat($request));
    }

    public function gradingReview(Request $request): JsonResponse
    {
        if ($response = $this->ensureChildOrgScope($request, $request->integer('child_id'))) {
            return $response;
        }

        return $this->wrap(parent::gradingReview($request));
    }

    public function progressAnalysis(Request $request): JsonResponse
    {
        if ($response = $this->ensureChildOrgScope($request, $request->integer('child_id'))) {
            return $response;
        }

        return $this->wrap(parent::progressAnalysis($request));
    }

    public function generateHint(Request $request): JsonResponse
    {
        if ($response = $this->ensureChildOrgScope($request, $request->integer('child_id'))) {
            return $response;
        }

        return $this->wrap(parent::generateHint($request));
    }

    public function getDataOptions(int $childId): JsonResponse
    {
        if ($response = $this->ensureChildOrgScope(request(), $childId)) {
            return $response;
        }

        return $this->wrap(parent::getDataOptions($childId));
    }

    public function getConversations(int $childId): JsonResponse
    {
        if ($response = $this->ensureChildOrgScope(request(), $childId)) {
            return $response;
        }

        return $this->wrap(parent::getConversations($childId));
    }

    public function getSessions(int $childId): JsonResponse
    {
        if ($response = $this->ensureChildOrgScope(request(), $childId)) {
            return $response;
        }

        return $this->wrap(parent::getSessions($childId));
    }

    public function getCapabilities(): JsonResponse
    {
        return $this->wrap(parent::getCapabilities());
    }

    public function getPerformanceData(int $childId): JsonResponse
    {
        if ($response = $this->ensureChildOrgScope(request(), $childId)) {
            return $response;
        }

        return $this->wrap(parent::getPerformanceData($childId));
    }

    public function getRecommendations(int $childId): JsonResponse
    {
        if ($response = $this->ensureChildOrgScope(request(), $childId)) {
            return $response;
        }

        return $this->wrap(parent::getRecommendations($childId));
    }

    public function executeRecommendation(Request $request): JsonResponse
    {
        return $this->wrap(parent::executeRecommendation($request));
    }

    public function dismissRecommendation(int $id): JsonResponse
    {
        return $this->wrap(parent::dismissRecommendation($id));
    }

    public function getWeaknessAnalysis(int $childId): JsonResponse
    {
        if ($response = $this->ensureChildOrgScope(request(), $childId)) {
            return $response;
        }

        return $this->wrap(parent::getWeaknessAnalysis($childId));
    }

    public function executeIntervention(Request $request): JsonResponse
    {
        return $this->wrap(parent::executeIntervention($request));
    }

    public function generateContextualPrompts(Request $request): JsonResponse
    {
        return $this->wrap(parent::generateContextualPrompts($request));
    }

    public function getPromptLibrary(int $childId): JsonResponse
    {
        if ($response = $this->ensureChildOrgScope(request(), $childId)) {
            return $response;
        }

        return $this->wrap(parent::getPromptLibrary($childId));
    }

    public function generateCustomPrompt(Request $request): JsonResponse
    {
        return $this->wrap(parent::generateCustomPrompt($request));
    }

    public function executePrompt(Request $request): JsonResponse
    {
        return $this->wrap(parent::executePrompt($request));
    }

    public function getLearningPaths(int $childId): JsonResponse
    {
        if ($response = $this->ensureChildOrgScope(request(), $childId)) {
            return $response;
        }

        return $this->wrap(parent::getLearningPaths($childId));
    }

    public function getPathProgress(int $childId): JsonResponse
    {
        if ($response = $this->ensureChildOrgScope(request(), $childId)) {
            return $response;
        }

        return $this->wrap(parent::getPathProgress($childId));
    }

    public function generateLearningPath(Request $request): JsonResponse
    {
        return $this->wrap(parent::generateLearningPath($request));
    }

    public function startLearningPath(int $pathId): JsonResponse
    {
        return $this->wrap(parent::startLearningPath($pathId));
    }

    public function executePathStep(int $pathId, int $stepId): JsonResponse
    {
        return $this->wrap(parent::executePathStep($pathId, $stepId));
    }

    public function initiateReviewChat(Request $request): JsonResponse
    {
        if ($response = $this->ensureChildOrgScope($request, $request->integer('child_id'))) {
            return $response;
        }

        return $this->wrap(parent::initiateReviewChat($request));
    }

    public function reviewChat(Request $request): JsonResponse
    {
        if ($response = $this->ensureChildOrgScope($request, $request->integer('child_id'))) {
            return $response;
        }

        return $this->wrap(parent::reviewChat($request));
    }

    private function wrap(JsonResponse $response): JsonResponse
    {
        $status = $response->getStatusCode();
        $payload = $response->getData(true);

        if (isset($payload['data'], $payload['meta'], $payload['errors'])) {
            return $response;
        }

        if (($payload['success'] ?? true) === false || $status >= 400) {
            $message = $payload['error'] ?? $payload['message'] ?? 'Request failed';
            $errors = [];

            if (isset($payload['details']) && is_array($payload['details'])) {
                foreach ($payload['details'] as $field => $messages) {
                    foreach ((array) $messages as $errorMessage) {
                        $errors[] = [
                            'field' => (string) $field,
                            'message' => $errorMessage,
                        ];
                    }
                }
            }

            $httpStatus = $status >= 400 ? $status : 400;

            return $this->error($message, $errors, $httpStatus);
        }

        return $this->success($payload);
    }

    private function ensureChildOrgScope(Request $request, ?int $childId): ?JsonResponse
    {
        if (!$childId) {
            return null;
        }

        $user = $request->user();
        if (!$user || $user->isSuperAdmin()) {
            return null;
        }

        $child = Child::find($childId);
        if (!$child) {
            return null;
        }

        $orgId = $request->attributes->get('organization_id');
        if ($orgId && (int) $child->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        return null;
    }
}
