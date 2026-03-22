<?php

namespace App\Services\Communications;

use App\Models\NotificationPreference;
use App\Models\User;

class PreferenceService
{
    /**
     * Default channel preferences when no specific preference is set.
     */
    protected const DEFAULT_CHANNELS = ['email', 'in_app'];

    /**
     * All notification types and their default channels.
     */
    protected const NOTIFICATION_TYPE_DEFAULTS = [
        'lesson_reminder' => ['email', 'in_app'],
        'payment_reminder' => ['email', 'in_app'],
        'payment_overdue' => ['email', 'sms', 'in_app'],
        'assessment_ready' => ['email', 'in_app'],
        'homework_assigned' => ['email', 'in_app'],
        'homework_deadline' => ['email', 'in_app'],
        'progress_update' => ['email'],
        'general' => ['email', 'in_app'],
        'marketing' => ['email'],
    ];

    /**
     * Get the preferred channels for a user for a given notification type.
     */
    public function getPreferredChannels(User $user, string $notificationType): array
    {
        $preference = $this->getPreference($user);

        // Check if user has per-type preferences
        $perTypePrefs = $preference?->preferred_channels ?? [];
        if (isset($perTypePrefs[$notificationType])) {
            return $this->filterEnabledChannels($preference, $perTypePrefs[$notificationType]);
        }

        // Fall back to notification type defaults
        $defaults = self::NOTIFICATION_TYPE_DEFAULTS[$notificationType] ?? self::DEFAULT_CHANNELS;

        return $this->filterEnabledChannels($preference, $defaults);
    }

    /**
     * Check if a user should receive a message via a specific channel for a type.
     */
    public function shouldSendVia(User $user, string $channel, string $notificationType): bool
    {
        return in_array($channel, $this->getPreferredChannels($user, $notificationType));
    }

    /**
     * Get all available notification types with their labels.
     */
    public static function getNotificationTypes(): array
    {
        return [
            'lesson_reminder' => 'Lesson Reminders',
            'payment_reminder' => 'Payment Reminders',
            'payment_overdue' => 'Overdue Payment Alerts',
            'assessment_ready' => 'Assessment Ready',
            'homework_assigned' => 'Homework Assigned',
            'homework_deadline' => 'Homework Deadlines',
            'progress_update' => 'Progress Updates',
            'general' => 'General Notifications',
            'marketing' => 'Marketing & Promotions',
        ];
    }

    /**
     * Filter channels based on the user's global enable/disable toggles.
     */
    protected function filterEnabledChannels(?NotificationPreference $preference, array $channels): array
    {
        if (!$preference) {
            // No preference record — only allow email and in_app
            return array_values(array_intersect($channels, ['email', 'in_app']));
        }

        return array_values(array_filter($channels, function (string $channel) use ($preference) {
            return match ($channel) {
                'email' => $preference->email_enabled,
                'sms' => $preference->sms_enabled,
                'whatsapp' => $preference->whatsapp_enabled && $preference->whatsapp_opted_in,
                'in_app' => $preference->in_app_enabled,
                'push' => $preference->push_enabled,
                default => false,
            };
        }));
    }

    protected function getPreference(User $user): ?NotificationPreference
    {
        return NotificationPreference::where('user_id', $user->id)->first();
    }
}
