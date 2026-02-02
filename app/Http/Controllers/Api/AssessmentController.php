<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Assessments\AssessmentStoreRequest;
use App\Http\Requests\Api\Assessments\AssessmentUpdateRequest;
use App\Http\Resources\AssessmentResource;
use App\Models\Assessment;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AssessmentController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id');

        $query = Assessment::query()->with(['lesson', 'organization']);

        if ($user?->isSuperAdmin()) {
            if ($request->filled('organization_id')) {
                $query->where('organization_id', $request->integer('organization_id'));
            }
            if ($request->filled('is_global')) {
                $query->where('is_global', $request->boolean('is_global'));
            }
        } else {
            $query->visibleToOrg($orgId);
        }

        ApiQuery::applyFilters($query, $request, [
            'status' => true,
            'type' => true,
            'lesson_id' => true,
            'journey_category_id' => true,
            'year_group' => true,
        ]);

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', '%' . $search . '%')
                    ->orWhere('description', 'like', '%' . $search . '%');
            });
        }

        ApiQuery::applySort($query, $request, ['created_at', 'title', 'deadline', 'availability'], '-created_at');

        $assessments = $query->paginate(ApiPagination::perPage($request, 20));
        $data = AssessmentResource::collection($assessments->items())->resolve();

        return $this->paginated($assessments, $data);
    }

    public function show(Request $request, Assessment $assessment): JsonResponse
    {
        if ($response = $this->ensureAssessmentScope($request, $assessment)) {
            return $response;
        }

        $assessment->load(['lesson', 'organization']);
        $data = (new AssessmentResource($assessment))->resolve();

        return $this->success($data);
    }

    public function store(AssessmentStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        [$organizationId, $isGlobal] = $this->resolveOrganization($request, $validated);

        [$questionsJson, $bankQuestionIds] = $this->buildQuestionsPayload($validated['questions'] ?? []);
        unset($validated['questions']);

        $validated['questions_json'] = $questionsJson;
        $validated['organization_id'] = $organizationId;
        $validated['is_global'] = $isGlobal;

        $assessment = Assessment::create($validated);

        if (!empty($bankQuestionIds)) {
            $pivotData = [];
            foreach ($bankQuestionIds as $bankQuestion) {
                $pivotData[$bankQuestion['question_id']] = [
                    'order_position' => $bankQuestion['order_position'],
                    'custom_points' => $bankQuestion['custom_points'],
                    'custom_settings' => null,
                ];
            }
            $assessment->bankQuestions()->sync($pivotData);
        }

        $assessment->load(['lesson', 'organization']);
        $data = (new AssessmentResource($assessment))->resolve();

        return $this->success(['assessment' => $data], status: 201);
    }

    public function update(AssessmentUpdateRequest $request, Assessment $assessment): JsonResponse
    {
        if ($response = $this->ensureAssessmentScope($request, $assessment)) {
            return $response;
        }

        $validated = $request->validated();
        [$organizationId, $isGlobal] = $this->resolveOrganization($request, $validated);

        $bankQuestionIds = null;
        if (array_key_exists('questions', $validated)) {
            [$questionsJson, $bankQuestionIds] = $this->buildQuestionsPayload($validated['questions'] ?? []);
            $validated['questions_json'] = $questionsJson;
            unset($validated['questions']);
        }

        $validated['organization_id'] = $organizationId;
        $validated['is_global'] = $isGlobal;

        $assessment->update($validated);

        if ($bankQuestionIds !== null) {
            if (!empty($bankQuestionIds)) {
                $pivotData = [];
                foreach ($bankQuestionIds as $bankQuestion) {
                    $pivotData[$bankQuestion['question_id']] = [
                        'order_position' => $bankQuestion['order_position'],
                        'custom_points' => $bankQuestion['custom_points'],
                        'custom_settings' => null,
                    ];
                }
                $assessment->bankQuestions()->sync($pivotData);
            } else {
                $assessment->bankQuestions()->detach();
            }
        }

        $assessment->load(['lesson', 'organization']);
        $data = (new AssessmentResource($assessment))->resolve();

        return $this->success(['assessment' => $data]);
    }

    public function destroy(Request $request, Assessment $assessment): JsonResponse
    {
        if ($response = $this->ensureAssessmentScope($request, $assessment)) {
            return $response;
        }

        $assessment->delete();

        return $this->success(['message' => 'Assessment deleted successfully.']);
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

    private function resolveOrganization(Request $request, array $validated): array
    {
        $user = $request->user();
        $isSuperAdmin = $user?->isSuperAdmin() ?? false;
        $isGlobal = $isSuperAdmin && !empty($validated['is_global']);
        $organizationId = $validated['organization_id'] ?? null;

        if (!$isSuperAdmin) {
            $organizationId = $request->attributes->get('organization_id');
            $isGlobal = false;
        }

        if ($isGlobal) {
            $organizationId = null;
        }

        if ($isSuperAdmin && !$isGlobal && !$organizationId) {
            throw ValidationException::withMessages([
                'organization_id' => 'Organization is required unless the assessment is global.',
            ]);
        }

        return [(int) $organizationId ?: null, (bool) $isGlobal];
    }

    private function buildQuestionsPayload(array $questionsInput): array
    {
        $questionsJson = [];
        $bankQuestionIds = [];

        foreach ($questionsInput as $index => $qData) {
            if (!empty($qData['question_bank_id'])) {
                $bankQuestionIds[] = [
                    'question_id' => $qData['question_bank_id'],
                    'order_position' => $index + 1,
                    'custom_points' => $qData['marks'] ?? null,
                ];
                continue;
            }

            $entry = [
                'question_text' => $qData['question_text'] ?? null,
                'type' => $qData['type'] ?? null,
                'options' => $qData['options'] ?? null,
                'correct_answer' => $qData['correct_answer'] ?? null,
                'marks' => $qData['marks'] ?? null,
                'category' => $qData['category'] ?? null,
                'question_image' => null,
            ];

            if (!empty($qData['question_image']) && $qData['question_image'] instanceof \Illuminate\Http\UploadedFile) {
                $path = $qData['question_image']->store('assessment_questions', 'public');
                $entry['question_image'] = $path;
            }

            $questionsJson[] = $entry;
        }

        return [$questionsJson, $bankQuestionIds];
    }
}
