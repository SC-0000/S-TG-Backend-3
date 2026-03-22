<?php

namespace App\Services\Communications;

use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Verifies that inbound phone numbers belong to known, verified contacts.
 * Ensures communications are authenticated and linked to the correct parent profile.
 */
class PhoneVerificationService
{
    /**
     * Verify an inbound phone number against the organization's known contacts.
     *
     * Returns the matched User if verified, null if unknown.
     * Also returns verification status details.
     */
    public function verify(string $phoneNumber, int $organizationId): array
    {
        $normalized = $this->normalizePhone($phoneNumber);

        // 1. Check notification preferences (primary — opted-in WhatsApp numbers)
        $preference = NotificationPreference::join('users', 'users.id', '=', 'notification_preferences.user_id')
            ->where('users.current_organization_id', $organizationId)
            ->where(function ($q) use ($normalized, $phoneNumber) {
                $q->where('notification_preferences.phone_number', $normalized)
                    ->orWhere('notification_preferences.phone_number', $phoneNumber);
            })
            ->select('notification_preferences.*')
            ->first();

        if ($preference) {
            $user = User::find($preference->user_id);
            if ($user) {
                return [
                    'verified' => true,
                    'user' => $user,
                    'method' => 'notification_preference',
                    'whatsapp_opted_in' => (bool) $preference->whatsapp_opted_in,
                ];
            }
        }

        // 2. Check user mobile_number field
        $user = User::where('current_organization_id', $organizationId)
            ->where(function ($q) use ($normalized, $phoneNumber) {
                $q->where('mobile_number', $normalized)
                    ->orWhere('mobile_number', $phoneNumber);
            })
            ->first();

        if ($user) {
            return [
                'verified' => true,
                'user' => $user,
                'method' => 'mobile_number',
                'whatsapp_opted_in' => false, // Not explicitly opted in via preferences
            ];
        }

        // 3. Check emergency contacts on children (for identification, not full auth)
        $childMatch = DB::table('children')
            ->where('organization_id', $organizationId)
            ->where(function ($q) use ($normalized, $phoneNumber) {
                $q->where('emergency_contact_phone', $normalized)
                    ->orWhere('emergency_contact_phone', $phoneNumber);
            })
            ->whereNotNull('user_id')
            ->first();

        if ($childMatch) {
            $user = User::find($childMatch->user_id);
            if ($user) {
                return [
                    'verified' => true,
                    'user' => $user,
                    'method' => 'emergency_contact',
                    'whatsapp_opted_in' => false,
                    'note' => 'Matched via emergency contact — verify identity manually',
                ];
            }
        }

        // 4. Check existing conversations (previously verified contact)
        $existingConv = \App\Models\Conversation::where('organization_id', $organizationId)
            ->where(function ($q) use ($normalized, $phoneNumber) {
                $q->where('contact_phone', $normalized)
                    ->orWhere('contact_phone', $phoneNumber);
            })
            ->whereNotNull('contact_user_id')
            ->first();

        if ($existingConv) {
            $user = User::find($existingConv->contact_user_id);
            if ($user) {
                return [
                    'verified' => true,
                    'user' => $user,
                    'method' => 'existing_conversation',
                    'whatsapp_opted_in' => false,
                ];
            }
        }

        // Unknown number
        return [
            'verified' => false,
            'user' => null,
            'method' => null,
            'whatsapp_opted_in' => false,
        ];
    }

    /**
     * Normalize a phone number for consistent matching.
     * Strips spaces, dashes, and ensures + prefix for international.
     */
    public function normalizePhone(string $phone): string
    {
        // Remove all non-digit characters except leading +
        $cleaned = preg_replace('/[^\d+]/', '', $phone);

        // Ensure + prefix
        if (!str_starts_with($cleaned, '+')) {
            // If starts with 0, assume UK and convert to +44
            if (str_starts_with($cleaned, '0')) {
                $cleaned = '+44' . substr($cleaned, 1);
            } else {
                $cleaned = '+' . $cleaned;
            }
        }

        return $cleaned;
    }

    /**
     * Log a security event for unverified inbound messages.
     */
    public function logUnverifiedAttempt(string $phoneNumber, int $organizationId, string $channel): void
    {
        Log::warning('[PhoneVerification] Unverified inbound message', [
            'phone' => $phoneNumber,
            'organization_id' => $organizationId,
            'channel' => $channel,
        ]);

        // Create an in-app notification for admins
        $org = Organization::find($organizationId);
        if (!$org) return;

        $admins = $org->users()
            ->wherePivot('role', 'org_admin')
            ->wherePivot('status', 'active')
            ->limit(2)
            ->get();

        foreach ($admins as $admin) {
            \App\Models\AppNotification::create([
                'user_id' => $admin->id,
                'title' => 'Unknown Number Contacted',
                'message' => "An unrecognised number ({$phoneNumber}) sent a {$channel} message. Review in Communications Hub.",
                'type' => 'alert',
                'status' => 'unread',
                'channel' => 'in_app',
            ]);
        }
    }
}
