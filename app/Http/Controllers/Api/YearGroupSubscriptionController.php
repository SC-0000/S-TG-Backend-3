<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Subscription;
use App\Services\YearGroupSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class YearGroupSubscriptionController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $subscriptions = $user->subscriptions()
            ->get()
            ->filter(function ($subscription) {
                $filters = $subscription->content_filters ?? [];
                return ($filters['type'] ?? null) === 'year_group';
            })
            ->values()
            ->map(function ($subscription) {
                return [
                    'id' => $subscription->id,
                    'name' => $subscription->name,
                    'slug' => $subscription->slug,
                    'features' => $subscription->features ?? [],
                    'content_filters' => $subscription->content_filters ?? [],
                    'child_id' => $subscription->pivot?->child_id,
                    'starts_at' => $subscription->pivot?->starts_at,
                    'ends_at' => $subscription->pivot?->ends_at,
                    'status' => $subscription->pivot?->status,
                ];
            });

        return $this->success(['subscriptions' => $subscriptions]);
    }

    public function assign(Request $request, YearGroupSubscriptionService $service): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $validated = $request->validate([
            'subscription_id' => ['required', 'exists:subscriptions,id'],
            'child_id' => ['required', 'exists:children,id'],
        ]);

        if (!$user->children()->where('id', $validated['child_id'])->exists()) {
            return $this->error('Invalid child selection.', [], 422);
        }

        if (!$user->subscriptions()->where('subscriptions.id', $validated['subscription_id'])->exists()) {
            return $this->error('Invalid subscription.', [], 422);
        }

        $user->subscriptions()->updateExistingPivot(
            $validated['subscription_id'],
            ['child_id' => $validated['child_id']]
        );

        $subscription = Subscription::findOrFail($validated['subscription_id']);
        $child = $user->children()->where('id', $validated['child_id'])->firstOrFail();

        $service->grantAccess($user, $subscription, $child);

        return $this->success([
            'message' => 'Subscription assigned successfully.',
            'subscription_id' => $subscription->id,
            'child_id' => $child->id,
        ]);
    }
}
