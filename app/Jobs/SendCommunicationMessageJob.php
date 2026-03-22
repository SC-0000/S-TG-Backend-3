<?php

namespace App\Jobs;

use App\DTOs\SendMessageDTO;
use App\Models\Organization;
use App\Services\Communications\ChannelDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendCommunicationMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        protected int $organizationId,
        protected string $channel,
        protected string $bodyText,
        protected ?int $recipientUserId = null,
        protected ?string $recipientAddress = null,
        protected ?string $subject = null,
        protected ?string $bodyHtml = null,
        protected ?int $templateId = null,
        protected string $senderType = 'system',
        protected ?int $senderId = null,
        protected array $metadata = [],
    ) {}

    public function handle(ChannelDispatcher $dispatcher): void
    {
        $org = Organization::findOrFail($this->organizationId);

        $dto = new SendMessageDTO(
            channel: $this->channel,
            bodyText: $this->bodyText,
            recipientUserId: $this->recipientUserId,
            recipientAddress: $this->recipientAddress,
            subject: $this->subject,
            bodyHtml: $this->bodyHtml,
            templateId: $this->templateId,
            senderType: $this->senderType,
            senderId: $this->senderId,
            metadata: $this->metadata,
        );

        $dispatcher->send($org, $dto);
    }

    /**
     * Convenience factory to dispatch from anywhere.
     */
    public static function dispatch_message(
        Organization $org,
        SendMessageDTO $dto,
    ): void {
        self::dispatch(
            organizationId: $org->id,
            channel: $dto->channel,
            bodyText: $dto->bodyText,
            recipientUserId: $dto->recipientUserId,
            recipientAddress: $dto->recipientAddress,
            subject: $dto->subject,
            bodyHtml: $dto->bodyHtml,
            templateId: $dto->templateId,
            senderType: $dto->senderType,
            senderId: $dto->senderId,
            metadata: $dto->metadata,
        );
    }
}
