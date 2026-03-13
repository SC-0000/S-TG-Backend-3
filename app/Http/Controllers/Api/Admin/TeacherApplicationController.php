<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\TeacherApplicationResource;
use App\Mail\TeacherApproved;
use App\Mail\TeacherRejected;
use App\Models\AdminTask;
use App\Models\Organization;
use App\Models\Teacher;
use App\Models\User;
use App\Support\ApiPagination;
use App\Support\MailContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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

        $applicantUsers = $userIds->isNotEmpty()
            ? User::whereIn('id', $userIds)->get()->keyBy('id')
            : collect();

        $orgId = null;
        if ($user->isSuperAdmin() && $request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
        } elseif (!$user->isSuperAdmin()) {
            $orgId = $user->current_organization_id;
        }

        $tasks = $tasks->filter(function ($task) use ($applicantUsers, $orgId) {
            $metadata = $task->metadata ?? [];
            $userId = $metadata['user_id'] ?? null;
            $applicant = $userId ? $applicantUsers->get($userId) : null;
            $taskOrgId = $task->organization_id ?? ($metadata['organization_id'] ?? null);
            $applicantOrgId = $applicant?->current_organization_id;

            if ($orgId) {
                if ($taskOrgId && (int) $taskOrgId !== (int) $orgId) {
                    return false;
                }
                if (!$taskOrgId && $applicantOrgId && (int) $applicantOrgId !== (int) $orgId) {
                    return false;
                }
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

        $taskMetadata = $task->metadata ?? [];
        $userId = $taskMetadata['user_id'] ?? null;
        $applicant = $userId ? User::find($userId) : null;
        $organizationId = $task->organization_id ?? ($taskMetadata['organization_id'] ?? null);

        if (!$applicant) {
            $email = $taskMetadata['email'] ?? null;
            if (!$email) {
                return $this->error('Applicant email not found.', [], 404);
            }
            if (User::where('email', $email)->exists()) {
                return $this->error('A user with this email already exists.', [], 422);
            }

            $passwordHash = $taskMetadata['password_hash'] ?? null;
            $fallbackPassword = Str::random(12);

            $applicant = User::create([
                'name' => $taskMetadata['name'] ?? 'Teacher',
                'email' => $email,
                'password' => $passwordHash ?: Hash::make($fallbackPassword),
                'role' => User::ROLE_TEACHER,
                'mobile_number' => $taskMetadata['mobile_number'] ?? null,
                'current_organization_id' => $organizationId,
                'metadata' => [
                    'status' => 'approved',
                    'approved_at' => now()->toISOString(),
                    'approved_by' => $request->user()?->id,
                    'qualifications' => $taskMetadata['qualifications'] ?? null,
                    'experience' => $taskMetadata['experience'] ?? null,
                    'specialization' => $taskMetadata['specialization'] ?? null,
                    'applied_at' => $taskMetadata['applied_at'] ?? null,
                ],
            ]);

            if ($organizationId) {
                $applicant->organizations()->attach($organizationId, [
                    'role' => 'teacher',
                    'status' => 'active',
                    'invited_by' => $request->user()?->id,
                    'joined_at' => now(),
                ]);
            }

            Teacher::create([
                'user_id' => $applicant->id,
                'name' => $applicant->name,
                'title' => $taskMetadata['specialization'] ?? 'Teacher',
                'role' => 'Teacher',
                'bio' => $taskMetadata['experience']
                    ? 'Experience: ' . $taskMetadata['experience']
                    : 'Profile pending completion.',
                'category' => $taskMetadata['specialization'] ?? null,
                'metadata' => array_filter([
                    'phone' => $taskMetadata['mobile_number'] ?? null,
                    'email' => $applicant->email,
                ]),
                'specialties' => $taskMetadata['specialization'] ? [$taskMetadata['specialization']] : [],
            ]);

            $taskMetadata['user_id'] = $applicant->id;
            $task->metadata = $taskMetadata;
        } else {
            $metadata = $applicant->metadata ?? [];
            $metadata['status'] = 'approved';
            $metadata['approved_at'] = now()->toISOString();
            $metadata['approved_by'] = $request->user()?->id;
            $applicant->metadata = $metadata;
            $applicant->save();
        }

        $task->status = 'completed';
        $task->completed_at = now();
        $task->save();

        $organization = MailContext::resolveOrganization($applicant->current_organization_id, $applicant);
        MailContext::sendMailable($applicant->email, new TeacherApproved($applicant, $organization));

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

        $taskMetadata = $task->metadata ?? [];
        $userId = $taskMetadata['user_id'] ?? null;
        $applicant = $userId ? User::find($userId) : null;

        if ($applicant) {
            $metadata = $applicant->metadata ?? [];
            $metadata['status'] = 'rejected';
            $metadata['rejected_at'] = now()->toISOString();
            $metadata['rejected_by'] = $request->user()?->id;
            $applicant->metadata = $metadata;
            $applicant->save();
        }

        $task->status = 'cancelled';
        $task->completed_at = now();
        $task->save();

        $recipientEmail = $applicant?->email ?? ($taskMetadata['email'] ?? null);
        $recipientName = $applicant?->name ?? ($taskMetadata['name'] ?? 'Teacher');
        if ($recipientEmail) {
            $organization = MailContext::resolveOrganization(
                $applicant?->current_organization_id ?? ($task->organization_id ?? ($taskMetadata['organization_id'] ?? null)),
                $applicant
            );
            MailContext::sendMailable($recipientEmail, new TeacherRejected($recipientName, $organization));
        }

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

        $metadata = $task->metadata ?? [];
        $userId = $metadata['user_id'] ?? null;
        $applicant = $userId ? User::find($userId) : null;

        $orgId = null;
        if ($user->isSuperAdmin() && $request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
        } elseif (!$user->isSuperAdmin()) {
            $orgId = $user->current_organization_id;
        }

        $taskOrgId = $task->organization_id ?? ($metadata['organization_id'] ?? null);
        $applicantOrgId = $applicant?->current_organization_id;
        if ($orgId) {
            if ($taskOrgId && (int) $taskOrgId !== (int) $orgId) {
                return $this->error('Not found.', [], 404);
            }
            if (!$taskOrgId && $applicantOrgId && (int) $applicantOrgId !== (int) $orgId) {
                return $this->error('Not found.', [], 404);
            }
        }

        if ($applicant) {
            $task->setRelation('applicant', $applicant);
        }

        return null;
    }
}
