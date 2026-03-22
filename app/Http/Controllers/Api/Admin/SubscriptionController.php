<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Subscriptions\SubscriptionStoreRequest;
use App\Http\Requests\Api\Subscriptions\SubscriptionUpdateRequest;
use App\Http\Resources\SubscriptionResource;
use App\Models\Subscription;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends ApiController
{
    private function orgId(Request $request): ?int
    {
        return $request->attributes->get('organization_id');
    }

    public function index(Request $request): JsonResponse
    {
        $orgId = $this->orgId($request);
        $query = Subscription::query()
            ->when($orgId, fn ($q) => $q->forOrganization($orgId))
            ->withCount('users');

        ApiQuery::applyFilters($query, $request, [
            'slug' => true,
            'name' => true,
            'is_active' => true,
        ]);

        ApiQuery::applySort($query, $request, ['created_at', 'name', 'slug', 'price', 'sort_order'], '-created_at');

        $subscriptions = $query->paginate(ApiPagination::perPage($request, 20));
        $data = SubscriptionResource::collection($subscriptions->items())->resolve();

        return $this->paginated($subscriptions, $data);
    }

    public function show(Request $request, Subscription $subscription): JsonResponse
    {
        $orgId = $this->orgId($request);
        if ($orgId && (int) $subscription->organization_id !== $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $subscription->loadCount('users');
        $data = (new SubscriptionResource($subscription))->resolve();

        return $this->success($data);
    }

    public function store(SubscriptionStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $orgId = $this->orgId($request);

        if ($orgId) {
            $validated['owner_type'] = 'organization';
            $validated['organization_id'] = $orgId;
            // Strip AI features — these are platform-only products
            if (isset($validated['features'])) {
                $validated['features'] = Subscription::stripPlatformFeatures($validated['features']);
            }
        }

        $subscription = Subscription::create($validated);
        $subscription->loadCount('users');

        $data = (new SubscriptionResource($subscription))->resolve();

        return $this->success(['subscription' => $data], [], 201);
    }

    public function update(SubscriptionUpdateRequest $request, Subscription $subscription): JsonResponse
    {
        $orgId = $this->orgId($request);
        if ($orgId && (int) $subscription->organization_id !== $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $validated = $request->validated();

        // Strip AI features from org subscriptions — platform-only
        if ($subscription->isOrganization() && isset($validated['features'])) {
            $validated['features'] = Subscription::stripPlatformFeatures($validated['features']);
        }

        $subscription->update($validated);
        $subscription->loadCount('users');

        $data = (new SubscriptionResource($subscription))->resolve();

        return $this->success(['subscription' => $data]);
    }

    public function destroy(Request $request, Subscription $subscription): JsonResponse
    {
        $orgId = $this->orgId($request);
        if ($orgId && (int) $subscription->organization_id !== $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $subscription->update(['is_active' => false]);

        return $this->success(['message' => 'Subscription deactivated.']);
    }

    public function toggleActive(Request $request, Subscription $subscription): JsonResponse
    {
        $orgId = $this->orgId($request);
        if ($orgId && (int) $subscription->organization_id !== $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $subscription->update(['is_active' => !$subscription->is_active]);

        return $this->success([
            'message' => $subscription->is_active ? 'Subscription activated.' : 'Subscription deactivated.',
            'is_active' => $subscription->is_active,
        ]);
    }
}
