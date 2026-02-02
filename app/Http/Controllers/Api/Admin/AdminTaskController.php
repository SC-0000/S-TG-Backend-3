<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\AdminTasks\AdminTaskStoreRequest;
use App\Http\Requests\Api\AdminTasks\AdminTaskUpdateRequest;
use App\Http\Resources\AdminTaskResource;
use App\Models\AdminTask;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminTaskController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $query = AdminTask::query()->with('assignedUser:id,name,email');

        $orgId = $request->attributes->get('organization_id');
        if ($user->isSuperAdmin()) {
            if ($request->filled('organization_id')) {
                $query->where('organization_id', $request->integer('organization_id'));
            }
        } elseif ($orgId) {
            $query->where('organization_id', $orgId);
        }

        if (!$request->boolean('all')) {
            $query->where(function ($q) use ($user) {
                $q->whereNull('assigned_to')
                    ->orWhere('assigned_to', $user->id);
            });
        }

        ApiQuery::applyFilters($query, $request, [
            'status' => true,
            'priority' => true,
            'assigned_to' => true,
        ]);

        ApiQuery::applySort($query, $request, ['created_at', 'priority', 'status'], '-created_at');

        $tasks = $query->paginate(ApiPagination::perPage($request, 20));
        $data = AdminTaskResource::collection($tasks->items())->resolve();

        return $this->paginated($tasks, $data);
    }

    public function show(Request $request, AdminTask $task): JsonResponse
    {
        if ($response = $this->ensureOrgScope($request, $task)) {
            return $response;
        }

        $task->load('assignedUser:id,name,email');
        $data = (new AdminTaskResource($task))->resolve();

        return $this->success($data);
    }

    public function store(AdminTaskStoreRequest $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $payload = $request->validated();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;

        if ($user->isSuperAdmin() && $request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
        }

        $payload['organization_id'] = $orgId;

        $task = AdminTask::create($payload);
        $task->load('assignedUser:id,name,email');

        $data = (new AdminTaskResource($task))->resolve();

        return $this->success(['task' => $data], status: 201);
    }

    public function update(AdminTaskUpdateRequest $request, AdminTask $task): JsonResponse
    {
        if ($response = $this->ensureOrgScope($request, $task)) {
            return $response;
        }

        $payload = $request->validated();
        $payload['assigned_to'] = $request->user()?->id;

        $task->update($payload);
        $task->load('assignedUser:id,name,email');

        $data = (new AdminTaskResource($task))->resolve();

        return $this->success(['task' => $data]);
    }

    public function destroy(Request $request, AdminTask $task): JsonResponse
    {
        if ($response = $this->ensureOrgScope($request, $task)) {
            return $response;
        }

        $task->delete();

        return $this->success(['message' => 'Task deleted successfully.']);
    }

    private function ensureOrgScope(Request $request, AdminTask $task): ?JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id');
        if (!$user->isSuperAdmin() && $orgId && (int) $task->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        if ($user->isSuperAdmin() && $request->filled('organization_id')) {
            if ((int) $task->organization_id !== (int) $request->integer('organization_id')) {
                return $this->error('Not found.', [], 404);
            }
        }

        return null;
    }
}
