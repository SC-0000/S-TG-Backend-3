<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Thin, synchronous audit writer.
 *
 * Keeps each write to a single INSERT — no relationship loading, no N+1.
 * Set AuditLogger::$suppress = true in bulk-import / seeding contexts to
 * avoid flooding the log with noise.
 */
class AuditLogger
{
    /** Temporarily disable logging (e.g. seeders, background agents). */
    public static bool $suppress = false;

    /**
     * Fields that add no meaningful audit value.
     */
    private const SKIP_FIELDS = [
        'updated_at', 'created_at', 'uid', 'sequence',
        'last_login_at', 'remember_token',
    ];

    /**
     * Fields whose values are too large to store as diffs — record as '[changed]'.
     */
    private const LARGE_FIELDS = [
        'content', 'content_data', 'body', 'description',
        'prep_notes', 'notes', 'explanation',
    ];

    /** Map of model class → resource_type slug. */
    private const TYPE_MAP = [
        \App\Models\Question::class         => 'question',
        \App\Models\ContentLesson::class    => 'content_lesson',
        \App\Models\Assessment::class       => 'assessment',
        \App\Models\AssessmentSubmission::class => 'submission',
        \App\Models\Course::class           => 'course',
        \App\Models\Module::class           => 'module',
        \App\Models\Lesson::class           => 'lesson',
        \App\Models\JourneyCategory::class  => 'journey',
        \App\Models\Service::class          => 'service',
        \App\Models\User::class             => 'user',
        \App\Models\ScheduleAllocation::class => 'allocation',
        \App\Models\AdminTask::class        => 'admin_task',
    ];

    public static function log(string $action, Model $model, ?array $changes = null): void
    {
        if (static::$suppress) {
            return;
        }

        try {
            $user   = auth()->user();
            $orgId  = $model->organization_id ?? null;

            // request()->ip() is only valid in HTTP context — safe null in CLI/queue
            $ip = app()->runningInConsole() ? null : (request()->ip() ?? null);

            AuditLog::create([
                'organization_id' => $orgId,
                'user_id'         => $user?->id,
                'user_name'       => $user?->name,
                'user_role'       => static::resolveRole($user),
                'action'          => $action,
                'resource_type'   => static::resolveType($model),
                'resource_id'     => $model->getKey(),
                'resource_name'   => static::resolveName($model),
                'changes'         => $changes,
                'ip_address'      => $ip,
            ]);
        } catch (Throwable $e) {
            // Never let audit logging break the primary request.
            \Illuminate\Support\Facades\Log::warning('AuditLogger failed', [
                'error'  => $e->getMessage(),
                'action' => $action,
                'model'  => get_class($model),
                'id'     => $model->getKey(),
            ]);
        }
    }

    /**
     * Build a compact diff array for an updated model.
     * Returns null if no significant fields changed.
     */
    public static function buildDiff(Model $model): ?array
    {
        $dirty = array_diff_key(
            $model->getDirty(),
            array_flip(self::SKIP_FIELDS)
        );

        if (empty($dirty)) {
            return null;
        }

        $changes = [];
        foreach ($dirty as $field => $_) {
            if (in_array($field, self::LARGE_FIELDS, true)) {
                $changes[$field] = ['from' => '[changed]', 'to' => '[changed]'];
            } else {
                $changes[$field] = [
                    'from' => $model->getOriginal($field),
                    'to'   => $model->getAttribute($field),
                ];
            }
        }

        return $changes;
    }

    /**
     * Delete audit logs older than $days. Call from a scheduled command.
     */
    public static function cleanup(int $days = 90): int
    {
        return AuditLog::where('created_at', '<', now()->subDays($days))->delete();
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private static function resolveType(Model $model): string
    {
        $class = get_class($model);
        return self::TYPE_MAP[$class] ?? strtolower(class_basename($model));
    }

    private static function resolveName(Model $model): ?string
    {
        foreach (['title', 'name', 'question_text', 'subject', 'label'] as $field) {
            $val = $model->getAttribute($field);
            if (!empty($val) && is_string($val)) {
                return mb_strlen($val) > 120 ? mb_substr($val, 0, 120) . '…' : $val;
            }
        }
        return null;
    }

    private static function resolveRole(?object $user): ?string
    {
        if (!$user) return null;
        // Support both simple string role and relationship-based roles
        if (isset($user->role)) return $user->role;
        if (method_exists($user, 'roles')) {
            return $user->roles?->first()?->name;
        }
        return null;
    }
}
