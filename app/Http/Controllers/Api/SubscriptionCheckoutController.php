<?php

namespace App\Http\Controllers\Api;

use App\Models\Subscription;
use App\Services\SubscriptionAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SubscriptionCheckoutController extends ApiController
{
    public function catalog(Request $request): JsonResponse
    {
        $ownerType = $request->get('owner_type', 'platform');
        $orgId = $request->get('organization_id');

        $ttl = (int) config('api.public_cache_ttl', 60);
        $cacheKey = "subscription-catalog:{$ownerType}:{$orgId}";

        $payload = $ttl > 0
            ? Cache::remember($cacheKey, $ttl, fn () => $this->buildCatalog($ownerType, $orgId))
            : $this->buildCatalog($ownerType, $orgId);

        return $this->success($payload);
    }

    private function buildCatalog(string $ownerType, ?int $orgId): array
    {
        $query = Subscription::active();

        if ($ownerType === 'platform') {
            $query->platform();
        } elseif ($orgId) {
            $query->forOrganization($orgId);
        }

        $subscriptions = $query->orderBy('sort_order')->orderBy('name')->get();

        $data = $subscriptions->map(fn ($sub) => [
            'id' => $sub->id,
            'name' => $sub->name,
            'slug' => $sub->slug,
            'description' => $sub->description,
            'features' => $sub->features ?? [],
            'content_filters' => $sub->content_filters,
            'price' => $sub->price,
            'currency' => $sub->currency,
            'billing_interval' => $sub->billing_interval,
            'owner_type' => $sub->owner_type,
        ]);

        return ['subscriptions' => $data];
    }

    public function subscribe(Request $request): JsonResponse
    {
        $request->validate([
            'subscription_id' => 'required|exists:subscriptions,id',
            'child_id' => 'nullable|exists:children,id',
        ]);

        $user = $request->user();
        $subscription = Subscription::where('id', $request->subscription_id)
            ->active()
            ->firstOrFail();

        // Check not already subscribed
        if ($user->subscriptions()->where('subscriptions.id', $subscription->id)->exists()) {
            return $this->error('You already have this subscription.', [], 422);
        }

        // Validate child belongs to user if provided
        if ($request->child_id) {
            $childBelongsToUser = $user->children()->where('id', $request->child_id)->exists();
            if (!$childBelongsToUser) {
                return $this->error('Child not found.', [], 404);
            }
        }

        // Create pending subscription record
        $user->subscriptions()->attach($subscription->id, [
            'starts_at' => now(),
            'ends_at' => null, // Set when payment confirms or based on billing_interval
            'status' => $subscription->price > 0 ? 'pending' : 'active',
            'source' => $subscription->price > 0 ? 'stripe' : 'manual',
            'child_id' => $request->child_id,
        ]);

        // If free subscription, grant access immediately
        if (!$subscription->price || $subscription->price <= 0) {
            if ($request->child_id) {
                $child = $user->children()->find($request->child_id);
                if ($child) {
                    app(SubscriptionAccessService::class)->grantAccess($user, $subscription, $child);
                }
            }
        }

        return $this->success([
            'message' => $subscription->price > 0
                ? 'Subscription created. Please complete payment.'
                : 'Subscription activated.',
            'subscription' => [
                'id' => $subscription->id,
                'name' => $subscription->name,
                'slug' => $subscription->slug,
                'price' => $subscription->price,
                'currency' => $subscription->currency,
                'billing_interval' => $subscription->billing_interval,
                'owner_type' => $subscription->owner_type,
            ],
            'requires_payment' => $subscription->price > 0,
        ], [], 201);
    }

    public function mySubscriptions(Request $request): JsonResponse
    {
        $user = $request->user();

        $rows = DB::table('user_subscriptions as us')
            ->join('subscriptions', 'subscriptions.id', '=', 'us.subscription_id')
            ->where('us.user_id', $user->id)
            ->select(
                'us.id as pivot_id',
                'subscriptions.id as subscription_id',
                'subscriptions.name',
                'subscriptions.slug',
                'subscriptions.description',
                'subscriptions.features',
                'subscriptions.price',
                'subscriptions.currency',
                'subscriptions.billing_interval',
                'subscriptions.owner_type',
                'us.status',
                'us.starts_at',
                'us.ends_at',
                'us.child_id',
                'us.source'
            )
            ->orderByDesc('us.created_at')
            ->get();

        $data = $rows->map(fn ($row) => [
            'pivot_id' => $row->pivot_id,
            'subscription' => [
                'id' => $row->subscription_id,
                'name' => $row->name,
                'slug' => $row->slug,
                'description' => $row->description,
                'features' => json_decode($row->features, true) ?? [],
                'price' => $row->price,
                'currency' => $row->currency,
                'billing_interval' => $row->billing_interval,
                'owner_type' => $row->owner_type,
            ],
            'status' => $row->status,
            'starts_at' => $row->starts_at,
            'ends_at' => $row->ends_at,
            'child_id' => $row->child_id,
            'source' => $row->source,
        ]);

        return $this->success(['subscriptions' => $data]);
    }

    public function cancel(Request $request, int $pivotId): JsonResponse
    {
        $user = $request->user();

        $row = DB::table('user_subscriptions')
            ->where('id', $pivotId)
            ->where('user_id', $user->id)
            ->first();

        if (!$row) {
            return $this->error('Subscription not found.', [], 404);
        }

        DB::table('user_subscriptions')
            ->where('id', $pivotId)
            ->update(['status' => 'canceled']);

        // Revoke access if child was assigned
        if ($row->child_id) {
            $child = \App\Models\Child::find($row->child_id);
            if ($child) {
                app(SubscriptionAccessService::class)->revokeAccess($child, $row->subscription_id);
            }
        }

        return $this->success(['message' => 'Subscription cancelled.']);
    }
}
