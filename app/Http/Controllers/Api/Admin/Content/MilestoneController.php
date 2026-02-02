<?php

namespace App\Http\Controllers\Api\Admin\Content;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Content\MilestoneRequest;
use App\Http\Resources\MilestoneResource;
use App\Models\Milestone;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MilestoneController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Milestone::query();
        $orgId = $request->attributes->get('organization_id');
        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        ApiQuery::applySort($query, $request, ['DisplayOrder', 'Date', 'MilestoneID'], 'DisplayOrder');

        $milestones = $query->paginate(ApiPagination::perPage($request, 20));
        $data = MilestoneResource::collection($milestones->items())->resolve();

        return $this->paginated($milestones, $data);
    }

    public function show(Request $request, int $milestone): JsonResponse
    {
        $query = Milestone::query();
        $orgId = $request->attributes->get('organization_id');
        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        $model = $query->where('MilestoneID', $milestone)->firstOrFail();

        $data = (new MilestoneResource($model))->resolve();

        return $this->success($data);
    }

    public function store(MilestoneRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        $orgId = $request->attributes->get('organization_id') ?? $user?->current_organization_id;
        if ($user?->isSuperAdmin() && isset($data['organization_id'])) {
            $orgId = (int) $data['organization_id'];
        }
        if ($orgId) {
            $data['organization_id'] = $orgId;
        }

        if ($request->hasFile('image')) {
            $data['Image'] = $request->file('image')->store('milestones', 'public');
        }

        $payload = [
            'organization_id' => $data['organization_id'] ?? null,
            'Title' => $data['title'],
            'Date' => $data['date'],
            'Description' => $data['description'],
            'Image' => $data['Image'] ?? null,
            'DisplayOrder' => $data['display_order'] ?? null,
        ];

        $milestone = Milestone::create($payload);

        $resource = (new MilestoneResource($milestone))->resolve();

        return $this->success(['milestone' => $resource], [], 201);
    }

    public function update(MilestoneRequest $request, int $milestone): JsonResponse
    {
        $query = Milestone::query();
        $orgId = $request->attributes->get('organization_id');
        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        $model = $query->where('MilestoneID', $milestone)->firstOrFail();
        $data = $request->validated();

        if ($request->hasFile('image')) {
            $data['Image'] = $request->file('image')->store('milestones', 'public');
        }

        $payload = [
            'Title' => $data['title'],
            'Date' => $data['date'],
            'Description' => $data['description'],
            'DisplayOrder' => $data['display_order'] ?? null,
        ];

        if (array_key_exists('Image', $data)) {
            $payload['Image'] = $data['Image'];
        }

        if ($request->user()?->isSuperAdmin() && isset($data['organization_id'])) {
            $payload['organization_id'] = (int) $data['organization_id'];
        }

        $model->update($payload);

        $resource = (new MilestoneResource($model->fresh()))->resolve();

        return $this->success(['milestone' => $resource]);
    }

    public function destroy(Request $request, int $milestone): JsonResponse
    {
        $query = Milestone::query();
        $orgId = $request->attributes->get('organization_id');
        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        $model = $query->where('MilestoneID', $milestone)->firstOrFail();
        $model->delete();

        return $this->success(['message' => 'Milestone deleted successfully.']);
    }
}
