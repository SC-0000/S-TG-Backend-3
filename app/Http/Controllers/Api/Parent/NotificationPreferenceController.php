<?php

namespace App\Http\Controllers\Api\Parent;

use App\Http\Controllers\Api\ApiController;
use App\Models\NotificationPreference;
use App\Services\Communications\PreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferenceController extends ApiController
{
    /**
     * Get the current user's notification preferences.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $preference = NotificationPreference::firstOrCreate(
            ['user_id' => $user->id],
            [
                'email_enabled' => true,
                'sms_enabled' => false,
                'whatsapp_enabled' => false,
                'whatsapp_opted_in' => false,
                'in_app_enabled' => true,
                'push_enabled' => true,
            ]
        );

        return $this->success([
            'preferences' => $preference,
            'notification_types' => PreferenceService::getNotificationTypes(),
        ]);
    }

    /**
     * Update the current user's notification preferences.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'email_enabled' => 'boolean',
            'sms_enabled' => 'boolean',
            'whatsapp_enabled' => 'boolean',
            'whatsapp_opted_in' => 'boolean',
            'in_app_enabled' => 'boolean',
            'push_enabled' => 'boolean',
            'phone_number' => 'nullable|string|max:20',
            'preferred_channels' => 'nullable|array',
            'preferred_channels.*' => 'nullable|array',
            'preferred_channels.*.*' => 'nullable|string|in:email,sms,whatsapp,in_app,push',
        ]);

        $preference = NotificationPreference::firstOrCreate(
            ['user_id' => $user->id],
            [
                'email_enabled' => true,
                'sms_enabled' => false,
                'whatsapp_enabled' => false,
                'whatsapp_opted_in' => false,
                'in_app_enabled' => true,
                'push_enabled' => true,
            ]
        );

        $preference->update($validated);

        return $this->success($preference->fresh());
    }
}
