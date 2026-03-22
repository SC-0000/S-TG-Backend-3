<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\SuperAdmin\Subscriptions\SubscriptionStoreRequest;
use App\Http\Requests\Api\SuperAdmin\Subscriptions\SubscriptionGrantRequest;
use App\Http\Resources\SubscriptionResource;
use App\Models\Organization;
use App\Models\OrganizationPlan;
use App\Models\PlatformPricing;
use App\Models\Subscription;
use App\Models\User;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Subscription::platform()->withCount('users');

        ApiQuery::applyFilters($query, $request, [
            'slug' => true,
            'name' => true,
            'is_active' => true,
        ]);

        ApiQuery::applySort($query, $request, ['created_at', 'name', 'price', 'sort_order'], '-created_at');

        $subscriptions = $query->paginate(ApiPagination::perPage($request, 20));
        $data = SubscriptionResource::collection($subscriptions->items())->resolve();

        return $this->paginated($subscriptions, $data);
    }

    public function store(SubscriptionStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['owner_type'] = 'platform';
        $validated['organization_id'] = null;

        $subscription = Subscription::create($validated);
        $subscription->loadCount('users');

        return $this->success([
            'subscription' => (new SubscriptionResource($subscription))->resolve(),
        ], [], 201);
    }

    public function show(Request $request, Subscription $subscription): JsonResponse
    {
        if (!$subscription->isPlatform()) {
            return $this->error('Not found.', [], 404);
        }

        $subscription->loadCount('users');

        $recentSubscribers = DB::table('user_subscriptions as us')
            ->join('users', 'users.id', '=', 'us.user_id')
            ->where('us.subscription_id', $subscription->id)
            ->select('us.id as pivot_id', 'users.id as user_id', 'users.name', 'users.email', 'us.status', 'us.starts_at', 'us.ends_at', 'us.child_id')
            ->orderByDesc('us.created_at')
            ->limit(10)
            ->get();

        return $this->success([
            'subscription' => (new SubscriptionResource($subscription))->resolve(),
            'recent_subscribers' => $recentSubscribers,
        ]);
    }

    public function update(SubscriptionStoreRequest $request, Subscription $subscription): JsonResponse
    {
        if (!$subscription->isPlatform()) {
            return $this->error('Not found.', [], 404);
        }

        $validated = $request->validated();
        unset($validated['slug']); // Don't allow slug changes on update

        $subscription->update($validated);
        $subscription->loadCount('users');

        return $this->success([
            'subscription' => (new SubscriptionResource($subscription))->resolve(),
        ]);
    }

    public function destroy(Request $request, Subscription $subscription): JsonResponse
    {
        if (!$subscription->isPlatform()) {
            return $this->error('Not found.', [], 404);
        }

        $subscription->update(['is_active' => false]);

        return $this->success(['message' => 'Subscription deactivated.']);
    }

    public function subscribers(Request $request): JsonResponse
    {
        $platformIds = Subscription::platform()->pluck('id');

        $query = DB::table('user_subscriptions as us')
            ->join('users', 'users.id', '=', 'us.user_id')
            ->join('subscriptions', 'subscriptions.id', '=', 'us.subscription_id')
            ->whereIn('us.subscription_id', $platformIds)
            ->select(
                'us.id as pivot_id',
                'us.user_id',
                'users.name as user_name',
                'users.email',
                'users.current_organization_id',
                'subscriptions.id as subscription_id',
                'subscriptions.name as plan_name',
                'subscriptions.slug',
                'us.status',
                'us.starts_at',
                'us.ends_at',
                'us.child_id',
                'us.source',
                'us.created_at'
            );

        if ($request->filled('plan')) {
            $query->where('subscriptions.slug', $request->plan);
        }

        if ($request->filled('status')) {
            $query->where('us.status', $request->status);
        }

        if ($request->filled('organization_id')) {
            $query->where('users.current_organization_id', $request->integer('organization_id'));
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('users.name', 'like', "%{$search}%")
                  ->orWhere('users.email', 'like', "%{$search}%");
            });
        }

        $rows = $query->orderByDesc('us.created_at')
            ->paginate(ApiPagination::perPage($request, 25));

        $data = collect($rows->items())->map(fn ($row) => [
            'pivot_id' => $row->pivot_id,
            'user' => [
                'id' => $row->user_id,
                'name' => $row->user_name,
                'email' => $row->email,
                'organization_id' => $row->current_organization_id,
            ],
            'subscription' => [
                'id' => $row->subscription_id,
                'name' => $row->plan_name,
                'slug' => $row->slug,
            ],
            'status' => $row->status,
            'starts_at' => $row->starts_at,
            'ends_at' => $row->ends_at,
            'child_id' => $row->child_id,
            'source' => $row->source,
            'created_at' => $row->created_at,
        ])->all();

        $plans = Subscription::platform()->select('id', 'name', 'slug')->orderBy('name')->get();

        return $this->paginated($rows, $data, [
            'filters' => $request->only(['plan', 'status', 'organization_id', 'search']),
            'plans' => $plans,
        ]);
    }

    public function grant(SubscriptionGrantRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $subscription = Subscription::where('id', $validated['subscription_id'])->platform()->firstOrFail();
        $user = User::findOrFail($validated['user_id']);

        if ($user->subscriptions()->where('subscriptions.id', $subscription->id)->exists()) {
            return $this->error('User already has this subscription.', [], 422);
        }

        $days = (int) ($validated['days'] ?? 0);

        $user->subscriptions()->attach($subscription->id, [
            'starts_at' => now(),
            'ends_at' => $days > 0 ? now()->addDays($days) : null,
            'status' => 'active',
            'source' => 'manual',
            'child_id' => $validated['child_id'] ?? null,
        ]);

        return $this->success([
            'message' => 'Subscription granted.',
            'subscription' => [
                'id' => $subscription->id,
                'name' => $subscription->name,
                'slug' => $subscription->slug,
            ],
        ], [], 201);
    }

    public function revoke(Request $request, int $pivotId): JsonResponse
    {
        $platformIds = Subscription::platform()->pluck('id');

        $row = DB::table('user_subscriptions')
            ->where('id', $pivotId)
            ->whereIn('subscription_id', $platformIds)
            ->first();

        if (!$row) {
            return $this->error('Not found.', [], 404);
        }

        DB::table('user_subscriptions')->where('id', $pivotId)->update([
            'status' => 'canceled',
        ]);

        return $this->success(['message' => 'Subscription revoked.']);
    }

    /**
     * GET /superadmin/subscriptions/org-ai-plans
     * Lists all active AI workspace plans across all organisations.
     */
    public function orgAiPlans(Request $request): JsonResponse
    {
        $query = OrganizationPlan::where('category', 'ai_workspace')
            ->with('organization:id,name');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        } else {
            $query->where('status', 'active');
        }

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->integer('organization_id'));
        }

        $plans = $query->orderByDesc('created_at')
            ->paginate(ApiPagination::perPage($request, 50));

        $data = $plans->getCollection()->map(function ($plan) {
            $pricing = PlatformPricing::where('category', $plan->category)
                ->where('item_key', $plan->item_key)
                ->first();

            return [
                'id' => $plan->id,
                'organization' => $plan->organization ? [
                    'id' => $plan->organization->id,
                    'name' => $plan->organization->name,
                ] : null,
                'item_key' => $plan->item_key,
                'label' => $pricing?->label ?? $plan->item_key,
                'tier' => $pricing?->tier,
                'status' => $plan->status,
                'quantity' => $plan->quantity,
                'price_override' => $plan->price_override,
                'effective_price' => $plan->getEffectivePrice(),
                'monthly_cost' => round(($plan->quantity ?? 1) * $plan->getEffectivePrice(), 2),
                'ai_actions_limit' => $plan->ai_actions_limit,
                'ai_actions_used' => $plan->ai_actions_used,
                'started_at' => $plan->started_at?->toISOString(),
                'expires_at' => $plan->expires_at?->toISOString(),
                'created_at' => $plan->created_at?->toISOString(),
            ];
        })->all();

        return $this->paginated($plans, $data);
    }
}
