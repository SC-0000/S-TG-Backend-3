<?php

namespace App\Services\Communications;

use App\Models\CommunicationMessage;
use App\Models\Conversation;
use App\Models\Organization;
use App\Models\User;

class ConversationService
{
    /**
     * Find an existing conversation or create a new one for the contact.
     */
    public function findOrCreateConversation(
        Organization $org,
        ?int $contactUserId = null,
        ?string $contactAddress = null,
        ?string $channel = null,
    ): Conversation {
        $query = Conversation::where('organization_id', $org->id)
            ->where('status', '!=', Conversation::STATUS_ARCHIVED);

        if ($contactUserId) {
            $query->where('contact_user_id', $contactUserId);
        } elseif ($contactAddress) {
            // Match by phone or email depending on channel
            if (in_array($channel, ['sms', 'whatsapp'])) {
                $query->where('contact_phone', $contactAddress);
            } else {
                $query->where('contact_email', $contactAddress);
            }
        }

        $conversation = $query->first();

        if ($conversation) {
            return $conversation;
        }

        // Build contact details for the new conversation
        $contactName = null;
        $contactPhone = null;
        $contactEmail = null;

        if ($contactUserId) {
            $user = User::find($contactUserId);
            if ($user) {
                $contactName = $user->name;
                $contactEmail = $user->email;
                $contactPhone = $user->notificationPreference?->phone_number ?? $user->mobile_number ?? null;
            }
        }

        if (!$contactPhone && in_array($channel, ['sms', 'whatsapp'])) {
            $contactPhone = $contactAddress;
        }
        if (!$contactEmail && $channel === 'email') {
            $contactEmail = $contactAddress;
        }

        return Conversation::create([
            'organization_id' => $org->id,
            'contact_user_id' => $contactUserId,
            'contact_phone' => $contactPhone,
            'contact_email' => $contactEmail,
            'contact_name' => $contactName ?? $contactAddress,
            'status' => Conversation::STATUS_OPEN,
            'last_message_at' => now(),
            'unread_count' => 0,
        ]);
    }

    /**
     * Update conversation after a message is sent or received.
     */
    public function onMessageSent(Conversation $conversation, CommunicationMessage $message): void
    {
        $updates = ['last_message_at' => now()];

        if ($message->direction === CommunicationMessage::DIRECTION_INBOUND) {
            $updates['unread_count'] = $conversation->unread_count + 1;
            $updates['status'] = Conversation::STATUS_OPEN;
        }

        $conversation->update($updates);
    }

    /**
     * Get conversation history with messages, paginated.
     */
    public function getHistory(int $conversationId, int $perPage = 50)
    {
        return CommunicationMessage::where('conversation_id', $conversationId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Mark all messages in a conversation as read and reset unread count.
     */
    public function markAsRead(Conversation $conversation): void
    {
        $conversation->update(['unread_count' => 0]);

        CommunicationMessage::where('conversation_id', $conversation->id)
            ->where('direction', CommunicationMessage::DIRECTION_INBOUND)
            ->where('status', '!=', CommunicationMessage::STATUS_READ)
            ->update([
                'status' => CommunicationMessage::STATUS_READ,
                'read_at' => now(),
            ]);
    }

    /**
     * Assign a conversation to a user (for human handoff).
     */
    public function assign(Conversation $conversation, ?int $userId): void
    {
        $conversation->update(['assigned_to' => $userId]);
    }

    /**
     * Find a conversation by an inbound phone number.
     */
    public function findByPhone(int $organizationId, string $phone): ?Conversation
    {
        return Conversation::where('organization_id', $organizationId)
            ->where('contact_phone', $phone)
            ->where('status', '!=', Conversation::STATUS_ARCHIVED)
            ->first();
    }

    /**
     * Resolve a User from a phone number within an organization.
     */
    public function resolveUserByPhone(int $organizationId, string $phone): ?User
    {
        // Check notification preferences first
        $preference = \App\Models\NotificationPreference::where('phone_number', $phone)->first();
        if ($preference) {
            $user = User::find($preference->user_id);
            if ($user && $user->current_organization_id == $organizationId) {
                return $user;
            }
        }

        // Fallback: check user mobile_number field
        return User::where('current_organization_id', $organizationId)
            ->where('mobile_number', $phone)
            ->first();
    }
}
