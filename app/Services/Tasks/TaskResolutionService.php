<?php

namespace App\Services\Tasks;

use App\Models\AdminTask;
use Illuminate\Support\Facades\Log;

class TaskResolutionService
{
    /**
     * Resolve all open tasks that match the given source model and event.
     *
     * @param string $sourceModelType  The fully-qualified model class name
     * @param int    $sourceModelId    The model's primary key
     * @param string $resolveEvent     The event key (e.g., 'submission_graded')
     * @return int   Number of tasks resolved
     */
    public static function resolve(string $sourceModelType, int $sourceModelId, string $resolveEvent): int
    {
        $tasks = AdminTask::where('source_model_type', $sourceModelType)
            ->where('source_model_id', $sourceModelId)
            ->where('auto_resolve_event', $resolveEvent)
            ->whereNotIn('status', ['Completed'])
            ->get();

        if ($tasks->isEmpty()) {
            return 0;
        }

        $count = 0;
        foreach ($tasks as $task) {
            $task->update([
                'status'       => 'Completed',
                'completed_at' => now(),
            ]);
            $count++;
        }

        Log::info("TaskResolutionService: Resolved {$count} task(s) for {$sourceModelType}#{$sourceModelId} on event '{$resolveEvent}'");

        return $count;
    }

    /**
     * Resolve a specific task by ID (for manual resolution).
     */
    public static function resolveById(int $taskId): bool
    {
        $task = AdminTask::find($taskId);
        if (!$task || $task->status === 'Completed') {
            return false;
        }

        $task->update([
            'status'       => 'Completed',
            'completed_at' => now(),
        ]);

        return true;
    }

    /**
     * Resolve all open tasks matching a task type for a given source model.
     * Useful when you know the task type but not the specific resolve event.
     */
    public static function resolveByType(string $taskType, string $sourceModelType, int $sourceModelId): int
    {
        $count = AdminTask::where('task_type', $taskType)
            ->where('source_model_type', $sourceModelType)
            ->where('source_model_id', $sourceModelId)
            ->whereNotIn('status', ['Completed'])
            ->update([
                'status'       => 'Completed',
                'completed_at' => now(),
            ]);

        if ($count > 0) {
            Log::info("TaskResolutionService: Resolved {$count} '{$taskType}' task(s) for {$sourceModelType}#{$sourceModelId}");
        }

        return $count;
    }
}
