<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\UserSubscriptions\UserSubscriptionGrantRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserSubscriptionController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');
        $query = DB::table('user_subscriptions as us')
            ->join('users', 'users.id', '=', 'us.user_id')
            ->join('subscriptions', 'subscriptions.id', '=', 'us.subscription_id')
            ->select(
                'us.id',
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
                'us.child_id'
            );

        if ($orgId) {
            $query->where('users.current_organization_id', $orgId);
        }

        ApiQuery::applyFilters($query, $request, [
            'plan' => 'subscriptions.slug',
            'status' => 'us.status',
            'user_id' => 'us.user_id',
        ]);

        $query->orderByDesc('us.id');

        $rows = $query->paginate(ApiPagination::perPage($request, 25));

        $data = collect($rows->items())->map(function ($row) {
            return [
                'id' => $row->id,
                'user' => [
                    'id' => $row->user_id,
                    'name' => $row->user_name,
                    'email' => $row->email,
                    'current_organization_id' => $row->current_organization_id,
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
            ];
        })->all();

        return $this->paginated($rows, $data);
    }

    public function store(UserSubscriptionGrantRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $orgId = $request->attributes->get('organization_id');

        $user = User::findOrFail($validated['user_id']);
        if ($orgId && (int) $user->current_organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        if ($user->subscriptions()->where('subscriptions.id', $validated['subscription_id'])->exists()) {
            return $this->error('User already has this subscription plan.', [], 422);
        }

        $days = (int) ($validated['days'] ?? 0);

        $user->subscriptions()->attach($validated['subscription_id'], [
            'starts_at' => now(),
            'ends_at' => $days > 0 ? now()->addDays($days) : null,
            'status' => 'active',
            'source' => 'manual',
            'child_id' => $validated['child_id'] ?? null,
        ]);

        $subscription = Subscription::find($validated['subscription_id']);

        return $this->success([
            'message' => 'Plan granted.',
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'name' => $subscription->name,
                'slug' => $subscription->slug,
            ] : null,
        ], [], 201);
    }

    public function destroy(Request $request, int $pivotId): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');
        $row = DB::table('user_subscriptions as us')
            ->join('users', 'users.id', '=', 'us.user_id')
            ->select('us.id', 'users.current_organization_id')
            ->where('us.id', $pivotId)
            ->first();

        if (!$row) {
            return $this->error('Not found.', [], 404);
        }

        if ($orgId && (int) $row->current_organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        DB::table('user_subscriptions')->where('id', $pivotId)->delete();

        return $this->success(['message' => 'Plan revoked.']);
    }
}
