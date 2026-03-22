<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\AdminTasks\AdminTaskStoreRequest;
use App\Http\Requests\Api\AdminTasks\AdminTaskUpdateRequest;
use App\Http\Resources\AdminTaskResource;
use App\Models\AdminTask;
use App\Models\User;
use App\Services\Tasks\TaskTypeRegistry;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'task_type' => true,
            'category' => true,
            'source' => true,
        ]);

        // Overdue filter
        if ($request->boolean('overdue')) {
            $query->overdue();
        }

        ApiQuery::applySort($query, $request, ['created_at', 'priority', 'status', 'due_at'], '-created_at');

        $tasks = $query->paginate(ApiPagination::perPage($request, 20));
        $data = AdminTaskResource::collection($tasks->items())->resolve();

        // Include summary metrics when requested
        $extra = [];
        if ($request->boolean('include_metrics')) {
            $metricsBase = AdminTask::query()
                ->when($orgId ?? null, fn ($q) => $q->where('organization_id', $orgId));
            $extra['metrics'] = [
                'total_pending'   => (clone $metricsBase)->where('status', 'Pending')->count(),
                'total_overdue'   => (clone $metricsBase)->overdue()->count(),
                'total_completed' => (clone $metricsBase)->where('status', 'Completed')->count(),
            ];
        }

        return $this->paginated($tasks, $data, $extra);
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
        $payload['source'] = 'manual';
        if (isset($payload['assigned_to'])) {
            $payload['assigned_at'] = now();
        }

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

        // Track assignment changes
        if (array_key_exists('assigned_to', $payload) && $payload['assigned_to'] !== $task->assigned_to) {
            $payload['assigned_at'] = $payload['assigned_to'] ? now() : null;
        }

        // Set completed_at when status changes to Completed
        if (isset($payload['status']) && $payload['status'] === 'Completed' && !$task->completed_at) {
            $payload['completed_at'] = now();
        }

        $task->update($payload);
        $task->load('assignedUser:id,name,email');

        $data = (new AdminTaskResource($task))->resolve();

        return $this->success(['task' => $data]);
    }

    /**
     * GET /api/v1/admin/tasks/types
     * Task type definitions for dropdown population.
     */
    public function types(): JsonResponse
    {
        $types = collect(TaskTypeRegistry::all())->map(fn ($def, $key) => [
            'key'              => $key,
            'label'            => $def['label'],
            'description'      => $def['description'],
            'default_priority' => $def['default_priority'],
            'default_assignee' => $def['default_assignee'],
            'category'         => $def['category'],
            'due_in_hours'     => $def['due_in_hours'],
            'auto_resolves'    => !empty($def['auto_resolve_event']),
        ])->values();

        return $this->success(['types' => $types]);
    }

    public function destroy(Request $request, AdminTask $task): JsonResponse
    {
        if ($response = $this->ensureOrgScope($request, $task)) {
            return $response;
        }

        // System-created tasks cannot be deleted — they must be resolved by completing the underlying action
        if ($task->is_system_task) {
            return $this->error('System tasks cannot be deleted. Complete the underlying action to resolve this task.', [], 422);
        }

        $task->delete();

        return $this->success(['message' => 'Task deleted successfully.']);
    }

    /**
     * GET /api/v1/admin/tasks/oversight
     * Per-teacher task health summary for admin oversight.
     */
    public function oversight(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id')
            ?: $user->current_organization_id;

        // Get all teachers who have tasks assigned
        $teachers = AdminTask::query()
            ->whereNotNull('assigned_to')
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->select('assigned_to')
            ->distinct()
            ->pluck('assigned_to');

        $data = User::whereIn('id', $teachers)
            ->select('id', 'name', 'email', 'avatar_path', 'role')
            ->get()
            ->map(function ($teacher) use ($orgId) {
                $base = AdminTask::where('assigned_to', $teacher->id)
                    ->when($orgId, fn ($q) => $q->where('organization_id', $orgId));

                $pending = (clone $base)->where('status', 'Pending')->count();
                $overdue = (clone $base)->overdue()->count();
                $inProgress = (clone $base)->where('status', 'In Progress')->count();
                $completedTotal = (clone $base)->where('status', 'Completed')
                    ->where('completed_at', '>=', now()->subDays(30))->count();

                // Avg completion time (last 30 days)
                $avgHours = (clone $base)
                    ->where('status', 'Completed')
                    ->whereNotNull('assigned_at')
                    ->whereNotNull('completed_at')
                    ->where('completed_at', '>=', now()->subDays(30))
                    ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, assigned_at, completed_at)) as avg_hours')
                    ->value('avg_hours');

                // Oldest open task
                $oldestOpen = (clone $base)
                    ->whereIn('status', ['Pending', 'In Progress'])
                    ->orderBy('created_at')
                    ->value('created_at');
                $oldestDays = $oldestOpen ? (int) now()->diffInDays($oldestOpen) : null;

                // Task breakdown by category
                $byCategory = (clone $base)->whereNotIn('status', ['Completed'])
                    ->selectRaw('category, COUNT(*) as count')
                    ->groupBy('category')
                    ->pluck('count', 'category')
                    ->toArray();

                // Health status
                $health = 'on_track';
                if ($overdue > 0) {
                    $health = 'behind';
                } elseif ($pending > 5 || ($oldestDays && $oldestDays > 3)) {
                    $health = 'at_risk';
                }

                // Resolve avatar: check Teacher model first, then User model
                $avatarUrl = null;
                $teacherRecord = \App\Models\Teacher::where('user_id', $teacher->id)->select('image_path')->first();
                if ($teacherRecord?->image_path) {
                    $avatarUrl = '/storage/' . $teacherRecord->image_path;
                } elseif ($teacher->avatar_path) {
                    $avatarUrl = '/storage/' . $teacher->avatar_path;
                }

                return [
                    'id'               => $teacher->id,
                    'name'             => $teacher->name,
                    'email'            => $teacher->email,
                    'role'             => $teacher->role,
                    'avatar'           => $avatarUrl,
                    'pending'          => $pending,
                    'in_progress'      => $inProgress,
                    'overdue'          => $overdue,
                    'completed_30d'    => $completedTotal,
                    'avg_completion_hours' => $avgHours ? round((float) $avgHours, 1) : null,
                    'oldest_open_days' => $oldestDays,
                    'health'           => $health,
                    'by_category'      => $byCategory,
                ];
            })
            ->sortByDesc('overdue')
            ->values();

        return $this->success(['teachers' => $data]);
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
