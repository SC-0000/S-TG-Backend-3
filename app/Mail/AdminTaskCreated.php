<?php

namespace App\Mail;

use App\Models\AdminTask;
use App\Models\Organization;

class AdminTaskCreated extends BrandedMailable
{
    public AdminTask $task;

    public function __construct(AdminTask $task, ?Organization $organization = null)
    {
        $this->task = $task;
        $this->organization = $organization;
    }

    public function build()
    {
        $org = $this->organization ?? $this->resolveOrganization($this->task->organization_id);
        $this->setOrganization($org);

        $subject = 'New Admin Task: ' . ($this->task->title ?: $this->task->task_type);

        return $this->subject($subject)
            ->view('emails.admin_task_created', $this->brandingData([
                'task' => $this->task,
                'organization' => $org,
            ]));
    }
}
