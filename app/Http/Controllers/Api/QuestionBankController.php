<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\QuestionController as BaseQuestionController;
use App\Http\Requests\Api\Questions\QuestionQuickCreateRequest;
use App\Http\Requests\Api\Questions\QuestionStoreRequest;
use App\Http\Requests\Api\Questions\QuestionUpdateRequest;
use App\Http\Resources\QuestionResource;
use App\Models\Question;
use App\Services\QuestionTypeRegistry;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class QuestionBankController extends BaseQuestionController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id');

        $query = Question::query()
            ->with(['creator:id,name', 'organization:id,name']);

        if ($user?->isSuperAdmin()) {
            if ($request->filled('organization_id')) {
                $query->where('organization_id', $request->integer('organization_id'));
            }
        } else {
            $query->where('organization_id', $orgId);
        }

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhere('subcategory', 'like', "%{$search}%")
                    ->orWhereJsonContains('tags', $search)
                    ->orWhereRaw("JSON_EXTRACT(question_data, '$.question_text') LIKE ?", ["%{$search}%"]);
            });
        }

        if ($request->filled('type')) {
            $query->where('question_type', $request->query('type'));
        }

        ApiQuery::applyFilters($query, $request, [
            'question_type' => true,
            'category' => true,
            'grade' => true,
            'status' => true,
            'difficulty_level' => true,
        ]);

        if ($request->filled('difficulty_min')) {
            $query->where('difficulty_level', '>=', $request->integer('difficulty_min'));
        }

        if ($request->filled('difficulty_max')) {
            $query->where('difficulty_level', '<=', $request->integer('difficulty_max'));
        }

        ApiQuery::applySort($query, $request, ['created_at', 'title', 'difficulty_level', 'marks', 'updated_at'], '-created_at');

        $questions = $query->paginate(ApiPagination::perPage($request, 20));
        $data = QuestionResource::collection($questions->items())->resolve();

        return $this->paginated($questions, $data, [
            'question_types' => QuestionTypeRegistry::getAllTypes(),
        ]);
    }

    public function show(Question $question): JsonResponse
    {
        $request = request();
        if ($response = $this->ensureQuestionScope($request, $question)) {
            return $response;
        }

        $question->load(['creator:id,name', 'organization:id,name']);
        $data = (new QuestionResource($question))->resolve();

        return $this->success($data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate((new QuestionStoreRequest())->rules());
        $organizationId = $this->resolveOrganization($request, $validated);

        $questionData = $this->processQuestionImages($validated['question_data'], $request);
        $questionData = $this->transformQuestionDataForValidation($questionData, $validated['question_type'], $request);

        $handler = QuestionTypeRegistry::getHandler($validated['question_type']);
        if (!$handler || !$handler::validate($questionData)) {
            return $this->error('Invalid question data structure for this question type.', [], 422);
        }

        $question = Question::create([
            'organization_id' => $organizationId,
            'title' => $validated['title'],
            'category' => $validated['category'] ?? null,
            'subcategory' => $validated['subcategory'] ?? null,
            'grade' => $validated['grade'],
            'question_type' => $validated['question_type'],
            'question_data' => $questionData,
            'answer_schema' => $validated['answer_schema'],
            'difficulty_level' => $validated['difficulty_level'] ?? 5,
            'estimated_time_minutes' => $validated['estimated_time_minutes'] ?? null,
            'marks' => $validated['marks'] ?? 1,
            'ai_metadata' => $validated['ai_metadata'] ?? [],
            'image_descriptions' => $validated['image_descriptions'] ?? [],
            'hints' => $validated['hints'] ?? [],
            'solutions' => $validated['solutions'] ?? [],
            'tags' => $validated['tags'] ?? [],
            'status' => $validated['status'] ?? 'active',
            'created_by' => $request->user()?->id,
        ]);

        $question->load(['creator:id,name', 'organization:id,name']);
        $data = (new QuestionResource($question))->resolve();

        return $this->success(['question' => $data], status: 201);
    }

    public function update(Request $request, Question $question): JsonResponse
    {
        if ($response = $this->ensureQuestionScope($request, $question)) {
            return $response;
        }

        $validated = $request->validate((new QuestionUpdateRequest())->rules());
        $organizationId = $this->resolveOrganization($request, $validated, $question);

        $questionData = $this->processQuestionImages($validated['question_data'], $request);
        $questionData = $this->transformQuestionDataForValidation($questionData, $validated['question_type'], $request);

        $handler = QuestionTypeRegistry::getHandler($validated['question_type']);
        if (!$handler || !$handler::validate($questionData)) {
            return $this->error('Invalid question data structure for this question type.', [], 422);
        }

        $question->update([
            'organization_id' => $organizationId,
            'title' => $validated['title'],
            'category' => $validated['category'] ?? null,
            'subcategory' => $validated['subcategory'] ?? null,
            'question_type' => $validated['question_type'],
            'question_data' => $questionData,
            'answer_schema' => $validated['answer_schema'],
            'difficulty_level' => $validated['difficulty_level'] ?? $question->difficulty_level,
            'estimated_time_minutes' => $validated['estimated_time_minutes'] ?? $question->estimated_time_minutes,
            'marks' => $validated['marks'] ?? $question->marks,
            'ai_metadata' => $validated['ai_metadata'] ?? $question->ai_metadata,
            'image_descriptions' => $validated['image_descriptions'] ?? [],
            'hints' => $validated['hints'] ?? [],
            'solutions' => $validated['solutions'] ?? [],
            'tags' => $validated['tags'] ?? [],
            'status' => $validated['status'] ?? $question->status,
        ]);

        $question->load(['creator:id,name', 'organization:id,name']);
        $data = (new QuestionResource($question))->resolve();

        return $this->success(['question' => $data]);
    }

    public function destroy(Question $question): JsonResponse
    {
        $request = request();
        if ($response = $this->ensureQuestionScope($request, $question)) {
            return $response;
        }

        $question->delete();

        return $this->success(['message' => 'Question deleted successfully.']);
    }

    public function quickCreate(QuestionQuickCreateRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $organizationId = $this->resolveOrganization($request, $validated);

        $questionPayload = $request->all();
        $questionPayload['organization_id'] = $organizationId;
        $questionPayload['created_by'] = $request->user()?->id;
        $questionPayload['status'] = $questionPayload['status'] ?? 'active';
        $questionPayload['difficulty_level'] = $questionPayload['difficulty_level'] ?? 5;
        $questionPayload['estimated_time_minutes'] = $questionPayload['estimated_time_minutes'] ?? 5;
        $questionPayload['tags'] = $questionPayload['tags'] ?? [];
        $questionPayload['answer_schema'] = $questionPayload['answer_schema'] ?? [];

        $questionPayload['question_data'] = $this->processQuestionImages($questionPayload['question_data'], $request);
        $questionPayload['question_data'] = $this->transformQuestionDataForValidation(
            $questionPayload['question_data'],
            $questionPayload['question_type'],
            $request
        );

        $handler = QuestionTypeRegistry::getHandler($questionPayload['question_type']);
        if (!$handler || !$handler::validate($questionPayload['question_data'])) {
            return $this->error('Invalid question data structure for this question type.', [], 422);
        }

        $question = Question::create($questionPayload);
        $question->load(['creator:id,name', 'organization:id,name']);

        $data = (new QuestionResource($question))->resolve();

        return $this->success([
            'question' => $data,
            'message' => 'Question created successfully and added to question bank!',
        ], status: 201);
    }

    public function typeDefaults(Request $request): JsonResponse
    {
        $validated = $request->validate(['type' => 'required|string']);
        $type = $validated['type'];

        if (!QuestionTypeRegistry::isValidType($type)) {
            return $this->error('Invalid question type.', [], 400);
        }

        return $this->success([
            'question_data' => QuestionTypeRegistry::getDefaultQuestionData($type),
            'answer_schema' => QuestionTypeRegistry::getDefaultAnswerSchema($type),
            'definition' => QuestionTypeRegistry::getTypeDefinition($type),
        ]);
    }

    private function ensureQuestionScope(Request $request, Question $question): ?JsonResponse
    {
        $user = $request->user();
        if ($user?->isSuperAdmin()) {
            return null;
        }

        $orgId = $request->attributes->get('organization_id');
        if ((int) $question->organization_id === (int) $orgId) {
            return null;
        }

        return $this->error('Not found.', [], 404);
    }

    private function resolveOrganization(Request $request, array $validated, ?Question $question = null): ?int
    {
        $user = $request->user();
        if ($user?->isSuperAdmin()) {
            $organizationId = $validated['organization_id'] ?? $question?->organization_id;
            if (!$organizationId) {
                throw ValidationException::withMessages([
                    'organization_id' => 'Organization is required for question bank entries.',
                ]);
            }
            return (int) $organizationId;
        }

        return (int) $request->attributes->get('organization_id');
    }
}
