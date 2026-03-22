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
            ->whereNotIn('task_type', ['Parent Concern', 'parent_concern', 'teacher_approval'])
            ->where(function ($q) use ($teacher) {
                $q->whereNull('assigned_to')
                    ->orWhere('assigned_to', $teacher->id);
            });

        ApiQuery::applyFilters($query, $request, [
            'status' => true,
            'priority' => true,
            'category' => true,
            'task_type' => true,
        ]);

        // Overdue filter
        if ($request->boolean('overdue')) {
            $query->overdue();
        }

        // Default sort: overdue first, then by due date, then by priority
        if (!$request->filled('sort')) {
            $query->orderByRaw("CASE WHEN due_at IS NOT NULL AND due_at < NOW() THEN 0 ELSE 1 END")
                  ->orderByRaw("CASE WHEN due_at IS NOT NULL THEN 0 ELSE 1 END")
                  ->orderBy('due_at')
                  ->orderByRaw("FIELD(priority, 'Critical', 'High', 'Medium', 'Low')");
        } else {
            ApiQuery::applySort($query, $request, ['created_at', 'priority', 'status', 'due_at'], '-created_at');
        }

        $tasks = $query->paginate(ApiPagination::perPage($request, 20));
        $data = AdminTaskResource::collection($tasks->items())->resolve();

        // Include summary for teacher dashboard
        $summary = [];
        if ($request->boolean('include_summary')) {
            $summaryBase = AdminTask::when($orgId, fn($q) => $q->where('organization_id', $orgId))
                ->where(function ($q) use ($teacher) {
                    $q->whereNull('assigned_to')->orWhere('assigned_to', $teacher->id);
                });
            $summary = [
                'overdue'   => (clone $summaryBase)->overdue()->count(),
                'due_today' => (clone $summaryBase)->pending()
                    ->whereDate('due_at', today())->count(),
                'due_this_week' => (clone $summaryBase)->pending()
                    ->whereBetween('due_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
                'completed_today' => (clone $summaryBase)->where('status', 'Completed')
                    ->whereDate('completed_at', today())->count(),
            ];
        }

        return $this->paginated($tasks, $data, $summary ? ['summary' => $summary] : []);
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
        $teacher = $request->user();

        // When teacher starts working on an unassigned task, claim it
        if (!$task->assigned_to && in_array($payload['status'], ['In Progress', 'Completed'])) {
            $payload['assigned_to'] = $teacher->id;
            $payload['assigned_at'] = now();
        }

        if ($payload['status'] === 'Completed' && !$task->completed_at) {
            $payload['completed_at'] = now();
        }

        $task->update($payload);

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
        $base = AdminTask::when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->where(function ($q) use ($teacher) {
                $q->whereNull('assigned_to')
                    ->orWhere('assigned_to', $teacher->id);
            });

        $pending = (clone $base)->where('status', 'Pending')->count();
        $overdue = (clone $base)->overdue()->count();

        return $this->success([
            'count'   => $pending,
            'overdue' => $overdue,
        ]);
    }

    /**
     * POST /api/teacher/request-ai-access
     * Creates an admin task requesting AI workspace access.
     */
    public function requestAiAccess(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;
        if (!$orgId) {
            $org = $user->organizations()->first();
            $orgId = $org?->id;
        }

        if (!$orgId) {
            return $this->error('Organization context required', [], 400);
        }

        // Check if there's already a pending AI request from this teacher
        $existing = AdminTask::where('organization_id', $orgId)
            ->where('task_type', 'ai_access_request')
            ->where('source_model_type', \App\Models\User::class)
            ->where('source_model_id', $user->id)
            ->whereIn('status', ['Pending', 'In Progress'])
            ->first();

        if ($existing) {
            return $this->success([
                'message' => 'Request already submitted',
                'task_id' => $existing->id,
                'already_requested' => true,
            ]);
        }

        // Find an org-level admin to assign to (never super_admin — they own the platform, not the org)
        $org = \App\Models\Organization::find($orgId);
        $assignTo = null;

        // First try: find an admin user in the organization
        $adminUser = \App\Models\User::where('role', 'admin')
            ->where('current_organization_id', $orgId)
            ->first();
        $assignTo = $adminUser?->id;

        // Fallback: org owner if they're an admin (not super_admin)
        if (!$assignTo && $org?->owner_id) {
            $owner = \App\Models\User::find($org->owner_id);
            if ($owner && $owner->role === 'admin') {
                $assignTo = $owner->id;
            }
        }

        $task = AdminTask::create([
            'organization_id' => $orgId,
            'task_type' => 'ai_access_request',
            'title' => 'AI Workspace Access Request',
            'description' => "{$user->name} has requested AI workspace access. Enable AI for your team to unlock content generation, automated grading, and AI-powered reports.",
            'status' => 'Pending',
            'priority' => 'Medium',
            'category' => 'Upgrade',
            'source' => 'system',
            'source_model_type' => \App\Models\User::class,
            'source_model_id' => $user->id,
            'assigned_to' => $assignTo,
            'assigned_at' => $assignTo ? now() : null,
            'action_url' => '/admin/settings/plans',
            'metadata' => [
                'requested_by_name' => $user->name,
                'requested_by_email' => $user->email,
                'requested_by_role' => 'teacher',
                'feature' => 'ai_workspace',
            ],
        ]);

        return $this->success([
            'message' => 'Request submitted successfully',
            'task_id' => $task->id,
            'already_requested' => false,
        ], [], 201);
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
