<?php

namespace App\Services\Communications\Drivers;

use App\DTOs\SendMessageDTO;
use App\Models\AppNotification;
use App\Models\CommunicationMessage;
use App\Models\Organization;

class InAppDriver
{
    /**
     * Create an in-app notification alongside the CommunicationMessage record.
     *
     * @return string|null The AppNotification ID as string
     */
    public function send(Organization $org, CommunicationMessage $message, SendMessageDTO $dto): ?string
    {
        if (!$dto->recipientUserId) {
            throw new \RuntimeException('In-app notifications require a recipient user ID');
        }

        $notification = AppNotification::create([
            'user_id' => $dto->recipientUserId,
            'title' => $dto->subject ?? 'Notification',
            'message' => $dto->bodyText,
            'type' => data_get($dto->metadata, 'notification_type', 'info'),
            'status' => 'unread',
            'channel' => 'in_app',
        ]);

        return (string) $notification->id;
    }
}
