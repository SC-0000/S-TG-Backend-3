<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Homework\HomeworkAssignmentStoreRequest;
use App\Http\Requests\Api\Homework\HomeworkAssignmentUpdateRequest;
use App\Http\Resources\HomeworkAssignmentResource;
use App\Models\HomeworkAssignment;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class HomeworkAssignmentController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');

        $query = HomeworkAssignment::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId));

        ApiQuery::applyFilters($query, $request, [
            'subject' => true,
            'created_by' => true,
            'due_from' => fn ($q, $value) => $q->where('due_date', '>=', $value),
            'due_to' => fn ($q, $value) => $q->where('due_date', '<=', $value),
        ]);

        ApiQuery::applySort($query, $request, ['created_at', 'due_date', 'title'], '-created_at');

        $assignments = $query->paginate(ApiPagination::perPage($request, 20));
        $data = HomeworkAssignmentResource::collection($assignments->items())->resolve();

        return $this->paginated($assignments, $data);
    }

    public function show(Request $request, HomeworkAssignment $homework): JsonResponse
    {
        if ($response = $this->ensureScope($request, $homework)) {
            return $response;
        }

        $data = (new HomeworkAssignmentResource($homework))->resolve();

        return $this->success($data);
    }

    public function store(HomeworkAssignmentStoreRequest $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $payload = $request->validated();
        $payload['created_by'] = $user->id;
        $orgId = $request->attributes->get('organization_id');
        if ($orgId) {
            $payload['organization_id'] = $orgId;
        }

        $attachments = $this->storeAttachments($request, 'homework_attachments');
        if (!empty($attachments)) {
            $payload['attachments'] = $attachments;
        }

        $assignment = HomeworkAssignment::create($payload);
        $data = (new HomeworkAssignmentResource($assignment))->resolve();

        return $this->success(['assignment' => $data], [], 201);
    }

    public function update(HomeworkAssignmentUpdateRequest $request, HomeworkAssignment $homework): JsonResponse
    {
        if ($response = $this->ensureScope($request, $homework)) {
            return $response;
        }

        $payload = $request->validated();
        $attachments = $this->storeAttachments($request, 'homework_attachments');
        if (!empty($attachments)) {
            $payload['attachments'] = array_values(array_merge($homework->attachments ?? [], $attachments));
        }

        $homework->update($payload);
        $data = (new HomeworkAssignmentResource($homework))->resolve();

        return $this->success(['assignment' => $data]);
    }

    public function destroy(Request $request, HomeworkAssignment $homework): JsonResponse
    {
        if ($response = $this->ensureScope($request, $homework)) {
            return $response;
        }

        $homework->delete();

        return $this->success(['message' => 'Homework assignment deleted successfully.']);
    }

    private function ensureScope(Request $request, HomeworkAssignment $homework): ?JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');
        if ($orgId && (int) $homework->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        return null;
    }

    private function storeAttachments(Request $request, string $directory): array
    {
        $stored = [];

        if (!$request->hasFile('attachments')) {
            return $stored;
        }

        $files = $request->file('attachments');
        if ($files instanceof UploadedFile) {
            $files = [$files];
        }

        foreach ($files as $file) {
            $stored[] = $file->store($directory, 'public');
        }

        return $stored;
    }
}
