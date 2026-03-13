<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\User;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');

        $query = User::query()->select([
            'id',
            'name',
            'email',
            'role',
            'current_organization_id',
            'created_at',
        ]);

        if ($orgId) {
            $query->where('current_organization_id', $orgId);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        ApiQuery::applyFilters($query, $request, [
            'role' => true,
            'current_organization_id' => true,
        ]);

        ApiQuery::applySort($query, $request, ['created_at', 'name', 'email', 'role'], '-created_at');

        $users = $query->paginate(ApiPagination::perPage($request, 200));
        $data = collect($users->items())->map(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'current_organization_id' => $user->current_organization_id,
            ];
        })->all();

        return $this->paginated($users, $data, [
            'filters' => $request->only(['search', 'role']),
        ]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');
        if ($orgId && (int) $user->current_organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $user->load([
            'children:id,user_id,child_name,organization_id,year_group',
            'subscriptions' => fn ($q) => $q->withPivot(['id', 'starts_at', 'ends_at', 'status']),
        ]);

        $children = $user->children->map(function ($child) {
            return [
                'id' => $child->id,
                'name' => $child->child_name,
                'organization_id' => $child->organization_id,
                'year_group' => $child->year_group,
            ];
        });

        $subscriptions = $user->subscriptions->map(function ($subscription) {
            return [
                'id' => $subscription->id,
                'name' => $subscription->name,
                'slug' => $subscription->slug,
                'starts_at' => $subscription->pivot?->starts_at,
                'ends_at' => $subscription->pivot?->ends_at,
                'status' => $subscription->pivot?->status,
            ];
        });

        return $this->success([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'current_organization_id' => $user->current_organization_id,
            ],
            'children' => $children,
            'subscriptions' => $subscriptions,
        ]);
    }
}
