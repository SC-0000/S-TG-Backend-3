<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\TeacherApplicationResource;
use App\Mail\TeacherApproved;
use App\Mail\TeacherRejected;
use App\Models\AdminTask;
use App\Models\Organization;
use App\Models\User;
use App\Support\ApiPagination;
use App\Support\MailContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class TeacherApplicationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $status = $request->query('status');

        $query = AdminTask::where('task_type', 'teacher_approval')
            ->when($status, fn($q) => $q->where('status', $status))
            ->when(!$status, fn($q) => $q->whereIn('status', ['pending', 'Pending']))
            ->orderBy('created_at', 'desc');

        $tasks = $query->get();

        $userIds = $tasks->map(fn($task) => $task->metadata['user_id'] ?? null)
            ->filter()
            ->unique()
            ->values();

        $applicantUsers = User::whereIn('id', $userIds)->get()->keyBy('id');

        $orgId = null;
        if ($user->isSuperAdmin() && $request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
        } elseif (!$user->isSuperAdmin()) {
            $orgId = $user->current_organization_id;
        }

        $tasks = $tasks->filter(function ($task) use ($applicantUsers, $orgId) {
            $userId = $task->metadata['user_id'] ?? null;
            $applicant = $userId ? $applicantUsers->get($userId) : null;
            if (!$applicant) {
                return false;
            }
            if ($orgId && (int) $applicant->current_organization_id !== (int) $orgId) {
                return false;
            }
            return true;
        })->values();

        $tasks->each(function ($task) use ($applicantUsers) {
            $userId = $task->metadata['user_id'] ?? null;
            if ($userId && $applicantUsers->has($userId)) {
                $task->setRelation('applicant', $applicantUsers->get($userId));
            }
        });

        $page = (int) $request->query('page', 1);
        $perPage = ApiPagination::perPage($request, 20);
        $pagedItems = $tasks->slice(($page - 1) * $perPage, $perPage)->values();
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $pagedItems,
            $tasks->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $data = TeacherApplicationResource::collection($paginator->items())->resolve();

        $organizations = null;
        if ($user->isSuperAdmin()) {
            $organizations = Organization::orderBy('name')->get(['id', 'name']);
        }

        return $this->paginated($paginator, $data, [
            'organizations' => $organizations,
        ]);
    }

    public function approve(Request $request, AdminTask $task): JsonResponse
    {
        if ($response = $this->ensureTaskScope($request, $task)) {
            return $response;
        }

        $userId = $task->metadata['user_id'] ?? null;
        if (!$userId) {
            return $this->error('User ID not found.', [], 404);
        }

        $applicant = User::find($userId);
        if (!$applicant) {
            return $this->error('User not found.', [], 404);
        }

        $metadata = $applicant->metadata ?? [];
        $metadata['status'] = 'approved';
        $metadata['approved_at'] = now()->toISOString();
        $metadata['approved_by'] = $request->user()?->id;
        $applicant->metadata = $metadata;
        $applicant->save();

        $task->status = 'completed';
        $task->completed_at = now();
        $task->save();

        $organization = MailContext::resolveOrganization($applicant->current_organization_id, $applicant);
        Mail::to($applicant->email)->send(new TeacherApproved($applicant, $organization));

        $task->setRelation('applicant', $applicant);

        return $this->success([
            'application' => (new TeacherApplicationResource($task))->resolve(),
            'message' => 'Teacher approved successfully.',
        ]);
    }

    public function reject(Request $request, AdminTask $task): JsonResponse
    {
        if ($response = $this->ensureTaskScope($request, $task)) {
            return $response;
        }

        $userId = $task->metadata['user_id'] ?? null;
        if (!$userId) {
            return $this->error('User ID not found.', [], 404);
        }

        $applicant = User::find($userId);
        if (!$applicant) {
            return $this->error('User not found.', [], 404);
        }

        $metadata = $applicant->metadata ?? [];
        $metadata['status'] = 'rejected';
        $metadata['rejected_at'] = now()->toISOString();
        $metadata['rejected_by'] = $request->user()?->id;
        $applicant->metadata = $metadata;
        $applicant->save();

        $task->status = 'cancelled';
        $task->completed_at = now();
        $task->save();

        $organization = MailContext::resolveOrganization($applicant->current_organization_id, $applicant);
        Mail::to($applicant->email)->send(new TeacherRejected($applicant->name, $organization));

        $task->setRelation('applicant', $applicant);

        return $this->success([
            'application' => (new TeacherApplicationResource($task))->resolve(),
            'message' => 'Teacher application rejected.',
        ]);
    }

    private function ensureTaskScope(Request $request, AdminTask $task): ?JsonResponse
    {
        if ($task->task_type !== 'teacher_approval') {
            return $this->error('Invalid task type.', [], 422);
        }

        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $userId = $task->metadata['user_id'] ?? null;
        $applicant = $userId ? User::find($userId) : null;
        if (!$applicant) {
            return $this->error('User not found.', [], 404);
        }

        $orgId = null;
        if ($user->isSuperAdmin() && $request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
        } elseif (!$user->isSuperAdmin()) {
            $orgId = $user->current_organization_id;
        }

        if ($orgId && (int) $applicant->current_organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $task->setRelation('applicant', $applicant);

        return null;
    }
}
