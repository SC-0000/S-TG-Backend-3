<?php

namespace App\DTOs;

class SendMessageDTO
{
    public function __construct(
        public readonly string $channel,
        public readonly string $bodyText,
        public readonly ?int $recipientUserId = null,
        public readonly ?string $recipientAddress = null,
        public readonly ?string $subject = null,
        public readonly ?string $bodyHtml = null,
        public readonly ?int $templateId = null,
        public readonly string $senderType = 'system',
        public readonly ?int $senderId = null,
        public readonly string $direction = 'outbound',
        public readonly array $metadata = [],
    ) {}

    public static function email(
        string $bodyText,
        ?string $subject = null,
        ?string $bodyHtml = null,
        ?int $recipientUserId = null,
        ?string $recipientAddress = null,
        string $senderType = 'system',
        ?int $senderId = null,
        array $metadata = [],
    ): self {
        return new self(
            channel: 'email',
            bodyText: $bodyText,
            recipientUserId: $recipientUserId,
            recipientAddress: $recipientAddress,
            subject: $subject,
            bodyHtml: $bodyHtml,
            senderType: $senderType,
            senderId: $senderId,
            metadata: $metadata,
        );
    }

    public static function sms(
        string $bodyText,
        ?int $recipientUserId = null,
        ?string $recipientAddress = null,
        string $senderType = 'system',
        ?int $senderId = null,
        array $metadata = [],
    ): self {
        return new self(
            channel: 'sms',
            bodyText: $bodyText,
            recipientUserId: $recipientUserId,
            recipientAddress: $recipientAddress,
            senderType: $senderType,
            senderId: $senderId,
            metadata: $metadata,
        );
    }

    public static function whatsapp(
        string $bodyText,
        ?int $recipientUserId = null,
        ?string $recipientAddress = null,
        string $senderType = 'system',
        ?int $senderId = null,
        array $metadata = [],
    ): self {
        return new self(
            channel: 'whatsapp',
            bodyText: $bodyText,
            recipientUserId: $recipientUserId,
            recipientAddress: $recipientAddress,
            senderType: $senderType,
            senderId: $senderId,
            metadata: $metadata,
        );
    }

    public static function inApp(
        string $bodyText,
        ?int $recipientUserId = null,
        ?string $subject = null,
        string $senderType = 'system',
        ?int $senderId = null,
        array $metadata = [],
    ): self {
        return new self(
            channel: 'in_app',
            bodyText: $bodyText,
            recipientUserId: $recipientUserId,
            subject: $subject,
            senderType: $senderType,
            senderId: $senderId,
            metadata: $metadata,
        );
    }

    public static function inbound(
        string $channel,
        string $bodyText,
        ?string $recipientAddress = null,
        ?int $recipientUserId = null,
        array $metadata = [],
    ): self {
        return new self(
            channel: $channel,
            bodyText: $bodyText,
            recipientUserId: $recipientUserId,
            recipientAddress: $recipientAddress,
            senderType: 'external',
            direction: 'inbound',
            metadata: $metadata,
        );
    }
}
