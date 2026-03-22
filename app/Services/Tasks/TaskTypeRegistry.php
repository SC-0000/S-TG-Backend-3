<?php

namespace App\Services\Tasks;

class TaskTypeRegistry
{
    protected static array $types = [
        'grade_assessment' => [
            'label' => 'Grade Assessment',
            'description' => 'Grade a student assessment submission',
            'default_priority' => 'Medium',
            'default_assignee' => 'teacher',
            'auto_resolve_event' => 'submission_graded',
            'due_in_hours' => 48,
            'category' => 'grading',
            'action_url_pattern' => '/teacher/assessment-submissions/{source_model_id}',
        ],
        'create_student_report' => [
            'label' => 'Create Student Report',
            'description' => 'Write a progress report for a student',
            'default_priority' => 'Medium',
            'default_assignee' => 'teacher',
            'auto_resolve_event' => 'report_submitted',
            'due_in_hours' => 168, // 7 days
            'category' => 'content',
            'action_url_pattern' => '/teacher/students/{context.child_id}/report',
        ],
        'parent_followup' => [
            'label' => 'Parent Follow-up',
            'description' => 'Provide regular feedback to a parent',
            'default_priority' => 'Medium',
            'default_assignee' => 'teacher',
            'auto_resolve_event' => 'feedback_submitted',
            'due_in_hours' => 72,
            'category' => 'communication',
            'action_url_pattern' => '/teacher/students/{context.child_id}',
        ],
        'sales_call' => [
            'label' => 'Sales Call',
            'description' => 'Call a prospective or existing parent',
            'default_priority' => 'High',
            'default_assignee' => 'teacher',
            'auto_resolve_event' => 'call_logged',
            'due_in_hours' => 24,
            'category' => 'communication',
            'action_url_pattern' => null,
        ],
        'mark_attendance' => [
            'label' => 'Mark Attendance',
            'description' => 'Mark attendance for a completed lesson',
            'default_priority' => 'High',
            'default_assignee' => 'teacher',
            'auto_resolve_event' => 'attendance_marked',
            'due_in_hours' => 24,
            'category' => 'attendance',
            'action_url_pattern' => '/teacher/lessons/{source_model_id}',
        ],
        'create_content' => [
            'label' => 'Create Content',
            'description' => 'Create lesson content or materials',
            'default_priority' => 'Low',
            'default_assignee' => 'teacher',
            'auto_resolve_event' => 'content_published',
            'due_in_hours' => 168, // 7 days
            'category' => 'content',
            'action_url_pattern' => '/teacher/lessons',
        ],
        'custom_task' => [
            'label' => 'Custom Task',
            'description' => 'A manually assigned task',
            'default_priority' => 'Medium',
            'default_assignee' => null,
            'auto_resolve_event' => null,
            'due_in_hours' => null,
            'category' => 'admin',
            'action_url_pattern' => null,
        ],
        'application_review' => [
            'label' => 'Review Application',
            'description' => 'Review and process a new application',
            'default_priority' => 'High',
            'default_assignee' => 'admin',
            'auto_resolve_event' => 'application_processed',
            'due_in_hours' => 48,
            'category' => 'admin',
            'action_url_pattern' => '/admin/applications/{source_model_id}',
        ],
        'application_follow_up' => [
            'label' => 'Application Follow-up',
            'description' => 'Follow up with an applicant',
            'default_priority' => 'Medium',
            'default_assignee' => 'admin',
            'auto_resolve_event' => 'application_processed',
            'due_in_hours' => null, // set from request
            'category' => 'admin',
            'action_url_pattern' => '/admin/applications/{source_model_id}',
        ],
        'teacher_approval' => [
            'label' => 'Teacher Approval',
            'description' => 'Review and approve a teacher registration',
            'default_priority' => 'High',
            'default_assignee' => 'admin',
            'auto_resolve_event' => 'teacher_approved',
            'due_in_hours' => 48,
            'category' => 'admin',
            'action_url_pattern' => '/admin/teachers/{source_model_id}',
        ],
        'payment_followup' => [
            'label' => 'Payment Follow-up',
            'description' => 'Follow up on an overdue payment',
            'default_priority' => 'High',
            'default_assignee' => 'admin',
            'auto_resolve_event' => 'payment_resolved',
            'due_in_hours' => 72,
            'category' => 'billing',
            'action_url_pattern' => '/admin/transactions/{source_model_id}',
        ],
        'flag_review' => [
            'label' => 'Review Flag',
            'description' => 'Review flagged content or issue',
            'default_priority' => 'High',
            'default_assignee' => 'admin',
            'auto_resolve_event' => 'flag_resolved',
            'due_in_hours' => 24,
            'category' => 'admin',
            'action_url_pattern' => '/admin/flags/{source_model_id}',
        ],
        'new_student_assigned' => [
            'label' => 'New Student Assigned',
            'description' => 'A new student has been assigned to you',
            'default_priority' => 'Low',
            'default_assignee' => 'teacher',
            'auto_resolve_event' => null,
            'due_in_hours' => null,
            'category' => 'admin',
            'action_url_pattern' => '/teacher/students/{source_model_id}',
        ],
        'lesson_assigned' => [
            'label' => 'Lesson Assigned',
            'description' => 'You have been assigned to teach a lesson',
            'default_priority' => 'Medium',
            'default_assignee' => 'teacher',
            'auto_resolve_event' => 'lesson_completed',
            'due_in_hours' => null,
            'category' => 'admin',
            'action_url_pattern' => '/teacher/lessons/{source_model_id}',
        ],
        'live_session_scheduled' => [
            'label' => 'Live Session Scheduled',
            'description' => 'A live session has been scheduled',
            'default_priority' => 'Medium',
            'default_assignee' => 'teacher',
            'auto_resolve_event' => 'session_completed',
            'due_in_hours' => null,
            'category' => 'admin',
            'action_url_pattern' => '/teacher/live-sessions/{source_model_id}',
        ],
        'parent_concern' => [
            'label' => 'Parent Concern',
            'description' => 'A parent has raised a concern that needs attention',
            'default_priority' => 'High',
            'default_assignee' => 'admin',
            'auto_resolve_event' => 'concern_resolved',
            'due_in_hours' => 24,
            'category' => 'communication',
            'action_url_pattern' => '/admin/portal-feedbacks/{source_model_id}',
        ],
        'ai_access_request' => [
            'label' => 'AI Workspace Access Request',
            'description' => 'A teacher has requested AI workspace access for content generation and grading',
            'default_priority' => 'Medium',
            'default_assignee' => 'admin',
            'auto_resolve_event' => null,
            'due_in_hours' => 48,
            'category' => 'admin',
            'action_url_pattern' => '/admin/settings/plans',
        ],

        /* ─── Client Relationship ─── */
        'client_reengagement' => [
            'label' => 'Re-engage Inactive Client',
            'description' => 'Parent has been inactive for 30+ days — reach out to re-engage',
            'default_priority' => 'High',
            'default_assignee' => 'admin',
            'auto_resolve_event' => 'client_reengaged',
            'due_in_hours' => 48,
            'category' => 'client_relationship',
            'action_url_pattern' => '/admin/clients/{context.user_id}',
        ],
        'client_payment_followup' => [
            'label' => 'Follow Up Failed Payment',
            'description' => 'A payment has failed — follow up with the parent',
            'default_priority' => 'High',
            'default_assignee' => 'admin',
            'auto_resolve_event' => 'payment_resolved',
            'due_in_hours' => 24,
            'category' => 'client_relationship',
            'action_url_pattern' => '/admin/clients/{context.user_id}',
        ],
        'client_booking_nudge' => [
            'label' => 'Suggest Booking',
            'description' => 'Client has no upcoming bookings — suggest scheduling a session',
            'default_priority' => 'Medium',
            'default_assignee' => 'admin',
            'auto_resolve_event' => 'booking_created',
            'due_in_hours' => 72,
            'category' => 'client_relationship',
            'action_url_pattern' => '/admin/clients/{context.user_id}',
        ],
        'client_credit_expiring' => [
            'label' => 'Credits Expiring Soon',
            'description' => 'Service credits are expiring within 7 days — notify parent or book sessions',
            'default_priority' => 'Medium',
            'default_assignee' => 'admin',
            'auto_resolve_event' => null,
            'due_in_hours' => 48,
            'category' => 'client_relationship',
            'action_url_pattern' => '/admin/clients/{context.user_id}',
        ],
    ];

    /**
     * Get a task type definition by key.
     */
    public static function get(string $type): ?array
    {
        return static::$types[$type] ?? null;
    }

    /**
     * Get all registered task types.
     */
    public static function all(): array
    {
        return static::$types;
    }

    /**
     * Get all task type keys.
     */
    public static function keys(): array
    {
        return array_keys(static::$types);
    }

    /**
     * Get task types for a specific category.
     */
    public static function forCategory(string $category): array
    {
        return array_filter(static::$types, fn($t) => ($t['category'] ?? null) === $category);
    }

    /**
     * Get task types that have auto-resolution configured.
     */
    public static function autoResolvable(): array
    {
        return array_filter(static::$types, fn($t) => !empty($t['auto_resolve_event']));
    }

    /**
     * Build the action URL for a task, replacing placeholders with actual values.
     */
    public static function buildActionUrl(string $type, ?int $sourceModelId = null, array $context = []): ?string
    {
        $def = static::get($type);
        if (!$def || empty($def['action_url_pattern'])) {
            return null;
        }

        $url = $def['action_url_pattern'];
        $url = str_replace('{source_model_id}', (string) ($sourceModelId ?? ''), $url);

        // Replace {context.key} placeholders
        foreach ($context as $key => $value) {
            $url = str_replace("{context.{$key}}", (string) $value, $url);
        }

        return $url;
    }

    /**
     * Register a custom task type at runtime (for plugins/extensions).
     */
    public static function register(string $key, array $definition): void
    {
        static::$types[$key] = array_merge([
            'label' => $key,
            'description' => '',
            'default_priority' => 'Medium',
            'default_assignee' => null,
            'auto_resolve_event' => null,
            'due_in_hours' => null,
            'category' => 'admin',
            'action_url_pattern' => null,
        ], $definition);
    }
}
