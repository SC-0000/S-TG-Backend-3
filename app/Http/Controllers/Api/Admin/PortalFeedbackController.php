<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\ParentFeedbacks\ParentFeedbackUpdateRequest;
use App\Http\Resources\ParentFeedbackResource;
use App\Models\ParentFeedbacks;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalFeedbackController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = ParentFeedbacks::with('user');

        $this->applyOrgScope($request, $query);

        ApiQuery::applyFilters($query, $request, [
            'status' => true,
            'category' => true,
            'user_id' => true,
        ]);

        ApiQuery::applySort($query, $request, ['submitted_at', 'created_at', 'status'], '-submitted_at');

        $feedbacks = $query->paginate(ApiPagination::perPage($request, 20));
        $data = ParentFeedbackResource::collection($feedbacks->items())->resolve();

        return $this->paginated($feedbacks, $data);
    }

    public function show(Request $request, ParentFeedbacks $feedback): JsonResponse
    {
        if ($response = $this->ensureScope($request, $feedback)) {
            return $response;
        }

        $feedback->load('user');
        $data = (new ParentFeedbackResource($feedback))->resolve();

        return $this->success($data);
    }

    public function update(ParentFeedbackUpdateRequest $request, ParentFeedbacks $feedback): JsonResponse
    {
        if ($response = $this->ensureScope($request, $feedback)) {
            return $response;
        }

        $feedback->update($request->validated());
        $feedback->load('user');

        $data = (new ParentFeedbackResource($feedback))->resolve();

        return $this->success([
            'feedback' => $data,
            'message' => 'Feedback updated successfully.',
        ]);
    }

    private function applyOrgScope(Request $request, $query): void
    {
        $user = $request->user();
        if (!$user) {
            return;
        }

        $orgId = $request->attributes->get('organization_id');
        if ($user->isSuperAdmin() && $request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
        }

        if ($orgId) {
            $query->where('organization_id', $orgId);
        }
    }

    private function ensureScope(Request $request, ParentFeedbacks $feedback): ?JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id');
        if ($user->isSuperAdmin() && $request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
        }

        if ($orgId && (int) $feedback->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        return null;
    }
}
