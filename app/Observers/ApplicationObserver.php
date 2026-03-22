<?php

namespace App\Observers;

use App\Models\Application;
use App\Services\ApplicationActivityService;
use App\Services\Tasks\TaskResolutionService;

class ApplicationObserver
{
    /**
     * When an application status changes to Approved or Rejected,
     * resolve any application_review tasks linked to it.
     */
    public function updated(Application $application): void
    {
        // Resolve admin tasks on final status
        if ($application->isDirty('application_status')
            && in_array($application->application_status, ['Approved', 'Rejected'])) {
            TaskResolutionService::resolve(
                Application::class,
                $application->application_id,
                'application_processed'
            );
        }

        // Sync pipeline_status when application_status changes (if not already synced)
        if ($application->isDirty('application_status') && !$application->isDirty('pipeline_status')) {
            $statusMap = [
                'Approved' => Application::PIPELINE_APPROVED,
                'Rejected' => Application::PIPELINE_REJECTED,
            ];

            $newPipeline = $statusMap[$application->application_status] ?? null;
            if ($newPipeline && $application->pipeline_status !== $newPipeline) {
                $application->updateQuietly([
                    'pipeline_status'            => $newPipeline,
                    'pipeline_status_changed_at' => now(),
                ]);
            }
        }

        // Sync application_status when pipeline_status changes to a terminal state
        if ($application->isDirty('pipeline_status') && !$application->isDirty('application_status')) {
            $pipelineMap = [
                Application::PIPELINE_APPROVED => 'Approved',
                Application::PIPELINE_REJECTED => 'Rejected',
            ];

            $newAppStatus = $pipelineMap[$application->pipeline_status] ?? null;
            if ($newAppStatus && $application->application_status !== $newAppStatus) {
                $application->updateQuietly([
                    'application_status' => $newAppStatus,
                ]);
            }
        }

        // Log pipeline status changes as activities
        if ($application->isDirty('pipeline_status')) {
            $from = $application->getOriginal('pipeline_status') ?? 'unknown';
            $to   = $application->pipeline_status;

            ApplicationActivityService::logStatusChange(
                $application,
                $from,
                $to,
                auth()->id()
            );
        }
    }

    /**
     * Log creation as a system event.
     */
    public function created(Application $application): void
    {
        ApplicationActivityService::logSystemEvent(
            $application,
            'Application submitted',
            "New {$application->application_type} application from {$application->applicant_name}"
        );
    }
}
