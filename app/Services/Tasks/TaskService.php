<?php

namespace App\Services\Tasks;

use App\Models\AdminTask;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class TaskService
{
    /**
     * Create a task from a system event or manual trigger.
     *
     * @param string $taskType  Key from TaskTypeRegistry (e.g., 'grade_assessment')
     * @param array  $context   Contextual data:
     *   - organization_id (int, optional — falls back to auth user's org)
     *   - assigned_to     (int, optional — the user ID to assign)
     *   - title           (string, optional — overrides auto-generated title)
     *   - description     (string, optional)
     *   - priority        (string, optional — overrides registry default)
     *   - due_at          (Carbon|string, optional — overrides calculated due)
     *   - source_model    (Model, optional — the triggering model instance)
     *   - metadata        (array, optional — extra JSON data)
     *   - related_entity  (string, optional — legacy field)
     *   - source          (string, optional — defaults to 'system')
     *   - action_url      (string, optional — overrides registry pattern)
     *   + any key usable in action_url_pattern via {context.key}
     */
    public static function createFromEvent(string $taskType, array $context = []): ?AdminTask
    {
        $def = TaskTypeRegistry::get($taskType);
        if (!$def) {
            Log::warning("TaskService: Unknown task type '{$taskType}'");
            return null;
        }

        $sourceModel = $context['source_model'] ?? null;
        $sourceModelType = $sourceModel ? get_class($sourceModel) : null;
        $sourceModelId = $sourceModel?->getKey();

        // Duplicate prevention: don't create another open task for the same source
        if ($sourceModelType && $sourceModelId) {
            $exists = AdminTask::where('task_type', $taskType)
                ->where('source_model_type', $sourceModelType)
                ->where('source_model_id', $sourceModelId)
                ->whereNotIn('status', ['Completed'])
                ->exists();

            if ($exists) {
                Log::info("TaskService: Duplicate task prevented for {$taskType} on {$sourceModelType}#{$sourceModelId}");
                return null;
            }
        }

        // Resolve organization
        $orgId = $context['organization_id']
            ?? ($sourceModel?->organization_id ?? null)
            ?? (Auth::check() ? Auth::user()->current_organization_id : null);

        // Calculate due date
        $dueAt = $context['due_at'] ?? null;
        if (!$dueAt && !empty($def['due_in_hours'])) {
            $dueAt = now()->addHours($def['due_in_hours']);
        }

        // Build action URL
        $actionUrl = $context['action_url']
            ?? TaskTypeRegistry::buildActionUrl($taskType, $sourceModelId, $context);

        // Build title
        $title = $context['title'] ?? $def['label'];

        $task = AdminTask::create([
            'organization_id'   => $orgId,
            'task_type'         => $taskType,
            'assigned_to'       => $context['assigned_to'] ?? null,
            'status'            => 'Pending',
            'priority'          => $context['priority'] ?? $def['default_priority'],
            'title'             => $title,
            'description'       => $context['description'] ?? $def['description'],
            'metadata'          => $context['metadata'] ?? null,
            'related_entity'    => $context['related_entity'] ?? null,
            'due_at'            => $dueAt,
            'source'            => $context['source'] ?? 'system',
            'source_model_type' => $sourceModelType,
            'source_model_id'   => $sourceModelId,
            'auto_resolve_event'=> $def['auto_resolve_event'] ?? null,
            'assigned_at'       => isset($context['assigned_to']) ? now() : null,
            'category'          => $def['category'] ?? null,
            'action_url'        => $actionUrl,
        ]);

        return $task;
    }

    /**
     * Create a manual task (admin-created).
     */
    public static function createManual(array $data): AdminTask
    {
        $taskType = $data['task_type'] ?? 'custom_task';
        $def = TaskTypeRegistry::get($taskType);

        return AdminTask::create(array_merge([
            'source'    => 'manual',
            'category'  => $def['category'] ?? 'admin',
            'assigned_at' => isset($data['assigned_to']) ? now() : null,
        ], $data));
    }
}
