<?php

namespace App\Constants;

class AdminSettingsFields
{
    /**
     * Fields that org admins are allowed to read and update.
     * Excludes SMTP credentials, mail provider secrets, API keys, and Telnyx config.
     */
    public const ALLOWED_FIELDS = [
        // Branding
        'branding.organization_name',
        'branding.tagline',
        'branding.description',
        'branding.year_groups',

        // Theme colors — primary
        'theme.colors.primary',
        'theme.colors.primary_50',
        'theme.colors.primary_100',
        'theme.colors.primary_200',
        'theme.colors.primary_300',
        'theme.colors.primary_400',
        'theme.colors.primary_500',
        'theme.colors.primary_600',
        'theme.colors.primary_700',
        'theme.colors.primary_800',
        'theme.colors.primary_900',
        'theme.colors.primary_950',

        // Theme colors — accent
        'theme.colors.accent',
        'theme.colors.accent_50',
        'theme.colors.accent_100',
        'theme.colors.accent_200',
        'theme.colors.accent_300',
        'theme.colors.accent_400',
        'theme.colors.accent_500',
        'theme.colors.accent_600',
        'theme.colors.accent_700',
        'theme.colors.accent_800',
        'theme.colors.accent_900',
        'theme.colors.accent_950',

        // Theme colors — accent soft
        'theme.colors.accent_soft',
        'theme.colors.accent_soft_50',
        'theme.colors.accent_soft_100',
        'theme.colors.accent_soft_200',
        'theme.colors.accent_soft_300',
        'theme.colors.accent_soft_400',
        'theme.colors.accent_soft_500',
        'theme.colors.accent_soft_600',
        'theme.colors.accent_soft_700',
        'theme.colors.accent_soft_800',
        'theme.colors.accent_soft_900',

        // Theme colors — other
        'theme.colors.secondary',
        'theme.colors.heavy',

        // Custom CSS
        'theme.custom_css',

        // Contact
        'contact.phone',
        'contact.email',
        'contact.address.line1',
        'contact.address.city',
        'contact.address.country',
        'contact.address.postal_code',
        'contact.business_hours',

        // Social media
        'social_media.facebook',
        'social_media.twitter',
        'social_media.instagram',
        'social_media.linkedin',
        'social_media.youtube',

        // Email appearance (NOT credentials)
        'email.from_name',
        'email.from_email',
        'email.reply_to_email',
        'email.header_color',
        'email.button_color',
        'email.footer_text',
        'email.footer_disclaimer',

        // Email admin task notifications
        'email.admin_task_notifications.enabled',
        'email.admin_task_notifications.tasks.parent_concern',
        'email.admin_task_notifications.tasks.application_review',
        'email.admin_task_notifications.tasks.teacher_approval',
        'email.admin_task_notifications.tasks.grade_assessment_submission',
        'email.admin_task_notifications.tasks.lesson_assigned',
        'email.admin_task_notifications.tasks.live_session_scheduled',
        'email.admin_task_notifications.tasks.live_session_created_by_teacher',
        'email.admin_task_notifications.tasks.your_upcoming_live_session',
        'email.admin_task_notifications.tasks.new_student_assigned',
        'email.admin_task_notifications.tasks.flag_review',
    ];

    /**
     * Top-level setting groups that are safe for admin read access.
     * Used to filter the full settings JSON for the index endpoint.
     */
    public const SAFE_READ_GROUPS = [
        'branding',
        'theme',
        'contact',
        'social_media',
        'email.from_name',
        'email.from_email',
        'email.reply_to_email',
        'email.header_color',
        'email.button_color',
        'email.footer_text',
        'email.footer_disclaimer',
        'email.admin_task_notifications',
    ];
}
