<?php

namespace App\Http\Controllers\Api\Admin\Content;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Content\AlertRequest;
use App\Http\Resources\AlertResource;
use App\Models\Alert;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Alert::query();
        $orgId = $request->attributes->get('organization_id');
        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        ApiQuery::applyFilters($query, $request, [
            'type' => true,
            'priority' => true,
        ]);

        ApiQuery::applySort($query, $request, ['start_time', 'end_time', 'created_at', 'priority'], '-start_time');

        $alerts = $query->paginate(ApiPagination::perPage($request, 20));
        $data = AlertResource::collection($alerts->items())->resolve();

        return $this->paginated($alerts, $data);
    }

    public function show(Request $request, string $alertId): JsonResponse
    {
        $query = Alert::query();
        $orgId = $request->attributes->get('organization_id');
        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        $alert = $query->where('alert_id', $alertId)->firstOrFail();

        $data = (new AlertResource($alert))->resolve();

        return $this->success($data);
    }

    public function store(AlertRequest $request): JsonResponse
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

        $data['created_by'] = $user?->id;

        $alert = Alert::create($data);

        $payload = (new AlertResource($alert))->resolve();

        return $this->success(['alert' => $payload], [], 201);
    }

    public function update(AlertRequest $request, string $alertId): JsonResponse
    {
        $query = Alert::query();
        $orgId = $request->attributes->get('organization_id');
        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        $alert = $query->where('alert_id', $alertId)->firstOrFail();
        $data = $request->validated();

        if (!($request->user()?->isSuperAdmin())) {
            unset($data['organization_id']);
        }

        $alert->update($data);

        $payload = (new AlertResource($alert->fresh()))->resolve();

        return $this->success(['alert' => $payload]);
    }

    public function destroy(Request $request, string $alertId): JsonResponse
    {
        $query = Alert::query();
        $orgId = $request->attributes->get('organization_id');
        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        $alert = $query->where('alert_id', $alertId)->firstOrFail();
        $alert->delete();

        return $this->success(['message' => 'Alert deleted successfully.']);
    }
}
