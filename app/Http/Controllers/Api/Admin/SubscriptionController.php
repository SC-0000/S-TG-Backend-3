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
    public function index(Request $request): JsonResponse
    {
        $query = Subscription::query()->withCount('users');

        ApiQuery::applyFilters($query, $request, [
            'slug' => true,
            'name' => true,
        ]);

        ApiQuery::applySort($query, $request, ['created_at', 'name', 'slug'], '-created_at');

        $subscriptions = $query->paginate(ApiPagination::perPage($request, 20));
        $data = SubscriptionResource::collection($subscriptions->items())->resolve();

        return $this->paginated($subscriptions, $data);
    }

    public function show(Request $request, Subscription $subscription): JsonResponse
    {
        $subscription->loadCount('users');
        $data = (new SubscriptionResource($subscription))->resolve();

        return $this->success($data);
    }

    public function store(SubscriptionStoreRequest $request): JsonResponse
    {
        $subscription = Subscription::create($request->validated());
        $subscription->loadCount('users');

        $data = (new SubscriptionResource($subscription))->resolve();

        return $this->success(['subscription' => $data], [], 201);
    }

    public function update(SubscriptionUpdateRequest $request, Subscription $subscription): JsonResponse
    {
        $subscription->update($request->validated());
        $subscription->loadCount('users');

        $data = (new SubscriptionResource($subscription))->resolve();

        return $this->success(['subscription' => $data]);
    }

    public function destroy(Request $request, Subscription $subscription): JsonResponse
    {
        $subscription->delete();

        return $this->success(['message' => 'Subscription deleted successfully.']);
    }
}
