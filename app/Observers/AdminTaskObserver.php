<?php

namespace App\Observers;

use App\Mail\AdminTaskCreated;
use App\Models\AdminTask;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class AdminTaskObserver
{
    public function created(AdminTask $task): void
    {
        Log::info('AdminTaskObserver fired', [
            'task_id' => $task->id,
            'task_type' => $task->task_type,
            'organization_id' => $task->organization_id,
        ]);

        $org = $this->resolveOrganization($task);
        if (!$org) {
            Log::warning('AdminTaskObserver: no organization resolved', ['task_id' => $task->id]);
            return;
        }

        if (!$this->isEmailEnabled($org, $task)) {
            Log::info('AdminTaskObserver: email disabled for task', [
                'task_id' => $task->id,
                'task_type' => $task->task_type,
                'org_id' => $org->id,
            ]);
            return;
        }

        $recipients = $this->resolveRecipients($org, $task);
        if ($recipients->isEmpty()) {
            Log::warning('AdminTaskObserver: no recipients resolved', [
                'task_id' => $task->id,
                'task_type' => $task->task_type,
                'org_id' => $org->id,
            ]);
            return;
        }

        Log::info('AdminTaskObserver: sending email', [
            'task_id' => $task->id,
            'task_type' => $task->task_type,
            'org_id' => $org->id,
            'recipients' => $recipients->pluck('email')->filter()->unique()->values()->all(),
        ]);

        $emails = $recipients->pluck('email')->filter()->unique()->values()->all();
        Mail::mailer(config('mail.default'))
            ->to($emails)
            ->send(new AdminTaskCreated($task, $org));
    }

    private function resolveOrganization(AdminTask $task): ?Organization
    {
        if ($task->organization) {
            return $task->organization;
        }

        if ($task->organization_id) {
            return Organization::find($task->organization_id);
        }

        return null;
    }

    private function isEmailEnabled(Organization $org, AdminTask $task): bool
    {
        $globalEnabled = (bool) $org->getSetting('email.admin_task_notifications.enabled', true);
        if (!$globalEnabled) {
            return false;
        }

        $taskKey = $this->taskTypeKey($task->task_type);
        if (!$taskKey) {
            return true;
        }

        return (bool) $org->getSetting("email.admin_task_notifications.tasks.{$taskKey}", true);
    }

    private function taskTypeKey(?string $taskType): ?string
    {
        if (!$taskType) {
            return null;
        }

        $value = strtolower(trim($taskType));

        return match (true) {
            $value === 'parent concern' => 'parent_concern',
            $value === 'application review' => 'application_review',
            $value === 'teacher_approval' => 'teacher_approval',
            $value === 'grade assessment submission' => 'grade_assessment_submission',
            str_starts_with($value, 'lesson assigned to') => 'lesson_assigned',
            $value === 'live session scheduled' => 'live_session_scheduled',
            $value === 'live session created by teacher' => 'live_session_created_by_teacher',
            $value === 'your upcoming live session' => 'your_upcoming_live_session',
            $value === 'new student assigned' => 'new_student_assigned',
            $value === 'flag_review' => 'flag_review',
            default => null,
        };
    }

    private function resolveRecipients(Organization $org, AdminTask $task)
    {
        $assigned = $task->assignedUser;
        if ($assigned && $this->isOrgAdmin($assigned, $org)) {
            return collect([$assigned]);
        }

        $pivotAdmins = $org->users()
            ->wherePivot('role', User::ROLE_ADMIN)
            ->wherePivot('status', 'active')
            ->get();

        $directAdmins = User::query()
            ->where('role', User::ROLE_ADMIN)
            ->where('current_organization_id', $org->id)
            ->get();

        return $pivotAdmins->merge($directAdmins)->unique('id')->values();
    }

    private function isOrgAdmin(User $user, Organization $org): bool
    {
        if ($user->isSuperAdmin() || $user->isAdmin()) {
            return true;
        }

        return $user->hasRoleInOrganization(User::ROLE_ADMIN, $org->id);
    }
}
