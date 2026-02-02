<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Tasks\TaskStoreRequest;
use App\Http\Requests\Api\Tasks\TaskUpdateRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $query = Task::query()
            ->where(function ($q) use ($user) {
                $q->where('assigned_to', $user->id)
                    ->orWhere('created_by', $user->id);
            });

        ApiQuery::applyFilters($query, $request, [
            'status' => true,
            'priority' => true,
        ]);

        ApiQuery::applySort($query, $request, ['created_at', 'due_date', 'priority', 'status'], '-created_at');

        $tasks = $query->paginate(ApiPagination::perPage($request, 20));
        $data = TaskResource::collection($tasks->items())->resolve();

        return $this->paginated($tasks, $data);
    }

    public function show(Request $request, Task $task): JsonResponse
    {
        if ($response = $this->ensureAccess($request, $task)) {
            return $response;
        }

        $data = (new TaskResource($task))->resolve();

        return $this->success($data);
    }

    public function store(TaskStoreRequest $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $payload = $request->validated();
        $payload['assigned_to'] = $user->id;
        $payload['created_by'] = $user->id;

        $task = Task::create($payload);
        $data = (new TaskResource($task))->resolve();

        return $this->success(['task' => $data], [], 201);
    }

    public function update(TaskUpdateRequest $request, Task $task): JsonResponse
    {
        if ($response = $this->ensureAccess($request, $task)) {
            return $response;
        }

        $task->update($request->validated());
        $data = (new TaskResource($task))->resolve();

        return $this->success(['task' => $data]);
    }

    public function destroy(Request $request, Task $task): JsonResponse
    {
        if ($response = $this->ensureAccess($request, $task)) {
            return $response;
        }

        $task->delete();

        return $this->success(['message' => 'Task deleted successfully.']);
    }

    private function ensureAccess(Request $request, Task $task): ?JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        if ($user->role !== 'super_admin'
            && (int) $task->assigned_to !== (int) $user->id
            && (int) $task->created_by !== (int) $user->id) {
            return $this->error('Not found.', [], 404);
        }

        return null;
    }
}
