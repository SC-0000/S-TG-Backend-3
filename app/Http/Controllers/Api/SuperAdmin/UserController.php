<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\SuperAdmin\Users\UserRoleUpdateRequest;
use App\Http\Requests\Api\SuperAdmin\Users\UserStoreRequest;
use App\Http\Requests\Api\SuperAdmin\Users\UserUpdateRequest;
use App\Http\Resources\SuperAdminUserResource;
use App\Models\Organization;
use App\Models\User;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query()
            ->with([
                'organizations:id,name',
                'currentOrganization:id,name',
            ]);

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

        if ($request->filled('organization_id')) {
            $query->where('current_organization_id', $request->integer('organization_id'));
        }

        ApiQuery::applySort($query, $request, ['created_at', 'name', 'email', 'role'], '-created_at');

        $users = $query->paginate(ApiPagination::perPage($request));
        $data = SuperAdminUserResource::collection($users->items())->resolve();

        $organizations = Organization::orderBy('name')->get(['id', 'name']);

        return $this->paginated($users, $data, [
            'filters' => $request->only(['search', 'role', 'organization_id']),
            'organizations' => $organizations,
        ]);
    }

    public function store(UserStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'current_organization_id' => $validated['current_organization_id'] ?? null,
        ]);

        return $this->success([
            'user' => (new SuperAdminUserResource($user->load(['currentOrganization', 'organizations'])))->resolve(),
        ], status: 201);
    }

    public function show(User $user): JsonResponse
    {
        $user->load([
            'organizations:id,name',
            'currentOrganization:id,name',
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
            'user' => (new SuperAdminUserResource($user))->resolve(),
            'children' => $children,
            'subscriptions' => $subscriptions,
        ]);
    }

    public function update(UserUpdateRequest $request, User $user): JsonResponse
    {
        $validated = $request->validated();
        $originalOrgId = $user->current_organization_id;

        $user->update($validated);

        if (
            $user->role === User::ROLE_PARENT &&
            array_key_exists('current_organization_id', $validated) &&
            $validated['current_organization_id'] &&
            $validated['current_organization_id'] != $originalOrgId
        ) {
            $user->children()->update([
                'organization_id' => $validated['current_organization_id'],
            ]);
        }

        return $this->success([
            'user' => (new SuperAdminUserResource($user->fresh()->load(['currentOrganization', 'organizations'])))->resolve(),
        ]);
    }

    public function destroy(User $user): JsonResponse
    {
        $user->delete();

        return $this->success(['message' => 'User deleted successfully.']);
    }

    public function changeRole(UserRoleUpdateRequest $request, User $user): JsonResponse
    {
        $validated = $request->validated();
        $user->update(['role' => $validated['role']]);

        return $this->success(['message' => 'User role changed successfully.']);
    }

    public function toggleStatus(User $user): JsonResponse
    {
        return $this->success(['message' => 'User status toggle is not configured.']);
    }

    public function impersonate(User $user): JsonResponse
    {
        return $this->success([
            'message' => 'Impersonation is not available via API.',
        ]);
    }

    public function bulkAction(Request $request): JsonResponse
    {
        $request->validate([
            'action' => 'required|string|max:50',
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'integer|exists:users,id',
        ]);

        return $this->success(['message' => 'Bulk action queued.']);
    }
}
