<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\TeacherTasks\TeacherTaskStatusRequest;
use App\Http\Resources\AdminTaskResource;
use App\Models\AdminTask;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $teacher = $request->user();
        if (!$teacher) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id');
        $query = AdminTask::with('assignedUser:id,name,email')
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->where('task_type', '!=', 'Parent Concern')
            ->where(function ($q) use ($teacher) {
                $q->whereNull('assigned_to')
                    ->orWhere('assigned_to', $teacher->id);
            });

        ApiQuery::applyFilters($query, $request, [
            'status' => true,
            'priority' => true,
        ]);

        ApiQuery::applySort($query, $request, ['created_at', 'priority', 'status'], '-created_at');

        $tasks = $query->paginate(ApiPagination::perPage($request, 20));
        $data = AdminTaskResource::collection($tasks->items())->resolve();

        return $this->paginated($tasks, $data);
    }

    public function show(Request $request, AdminTask $task): JsonResponse
    {
        if ($response = $this->ensureScope($request, $task)) {
            return $response;
        }

        $task->load('assignedUser:id,name,email');
        $data = (new AdminTaskResource($task))->resolve();

        return $this->success($data);
    }

    public function updateStatus(TeacherTaskStatusRequest $request, AdminTask $task): JsonResponse
    {
        if ($response = $this->ensureScope($request, $task)) {
            return $response;
        }

        $payload = $request->validated();
        $task->update($payload);

        if ($payload['status'] === 'Completed' && !$task->completed_at) {
            $task->update(['completed_at' => now()]);
        }

        $task->load('assignedUser:id,name,email');
        $data = (new AdminTaskResource($task))->resolve();

        return $this->success(['task' => $data]);
    }

    public function pendingCount(Request $request): JsonResponse
    {
        $teacher = $request->user();
        if (!$teacher) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id');
        $count = AdminTask::when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->where(function ($q) use ($teacher) {
                $q->whereNull('assigned_to')
                    ->orWhere('assigned_to', $teacher->id);
            })
            ->where('status', 'Pending')
            ->count();

        return $this->success(['count' => $count]);
    }

    private function ensureScope(Request $request, AdminTask $task): ?JsonResponse
    {
        $teacher = $request->user();
        if (!$teacher) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id');
        if ($orgId && (int) $task->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        if (!is_null($task->assigned_to) && (int) $task->assigned_to !== (int) $teacher->id) {
            return $this->error('Unauthorized access.', [], 403);
        }

        return null;
    }
}
