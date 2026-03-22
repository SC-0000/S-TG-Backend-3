<?php

namespace App\Services\AI\BackgroundAgents\Agents;

use App\Models\AdminTask;
use App\Models\BackgroundAgentAction;
use App\Models\Lesson;
use App\Services\AI\BackgroundAgents\AbstractBackgroundAgent;
use App\Services\Tasks\TaskService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TaskGeneratorAgent extends AbstractBackgroundAgent
{
    public static function getAgentType(): string
    {
        return 'task_generator';
    }

    public static function getDefaultSchedule(): string
    {
        return '0 6 * * *'; // Daily at 6 AM
    }

    public static function getDescription(): string
    {
        return 'Generates recurring tasks (attendance, parent follow-ups) and escalates overdue task priorities.';
    }

    public static function getEstimatedTokensPerRun(): int
    {
        return 0; // No AI calls — pure database operations
    }

    /**
     * Stub flag — this agent does not require AI tokens.
     */
    public static function isStub(): bool
    {
        return false;
    }

    protected function execute(): void
    {
        if (!$this->organization) {
            return;
        }

        $this->createAttendanceTasks();
        $this->escalateOverdueTasks();
        $this->cleanupStaleTasks();
    }

    /**
     * Create mark_attendance tasks for completed lessons that have no attendance records.
     */
    protected function createAttendanceTasks(): void
    {
        $orgId = $this->organization->id;

        // Find lessons that ended in the last 48 hours with no attendance records
        $lessonsWithoutAttendance = Lesson::where('organization_id', $orgId)
            ->where('status', 'ended')
            ->where('end_time', '>=', now()->subHours(48))
            ->where('end_time', '<=', now())
            ->whereNotNull('instructor_id')
            ->whereDoesntHave('attendance')
            ->get();

        foreach ($lessonsWithoutAttendance as $lesson) {
            $task = TaskService::createFromEvent('mark_attendance', [
                'source_model'    => $lesson,
                'assigned_to'     => $lesson->instructor_id,
                'organization_id' => $orgId,
                'title'           => "Mark attendance: {$lesson->title}",
            ]);

            if ($task) {
                $this->incrementAffected();
                $this->logAction(
                    BackgroundAgentAction::ACTION_CREATE_RECORD,
                    $task,
                    "Created attendance task for lesson #{$lesson->id}"
                );
            }
        }
    }

    /**
     * Escalate priority of overdue tasks: Medium → High, High → Critical.
     */
    protected function escalateOverdueTasks(): void
    {
        $orgId = $this->organization->id;

        $escalations = [
            'Medium' => 'High',
            'High'   => 'Critical',
        ];

        foreach ($escalations as $from => $to) {
            $escalated = AdminTask::where('organization_id', $orgId)
                ->where('status', '!=', 'Completed')
                ->where('priority', $from)
                ->whereNotNull('due_at')
                ->where('due_at', '<', now())
                ->update(['priority' => $to]);

            if ($escalated > 0) {
                Log::info("TaskGeneratorAgent: Escalated {$escalated} tasks from {$from} to {$to} for org #{$orgId}");
                $this->incrementAffected();
            }
        }
    }

    /**
     * Clean up old informational/completed tasks older than 90 days.
     */
    protected function cleanupStaleTasks(): void
    {
        $orgId = $this->organization->id;

        $deleted = AdminTask::where('organization_id', $orgId)
            ->where('status', 'Completed')
            ->where('completed_at', '<', now()->subDays(90))
            ->delete();

        if ($deleted > 0) {
            Log::info("TaskGeneratorAgent: Cleaned up {$deleted} stale completed tasks for org #{$orgId}");
        }
    }
}
