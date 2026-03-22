<?php

namespace App\Services\Communications;

use App\DTOs\SendMessageDTO;
use App\Models\CommunicationMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\AI\TokenBillingService;
use App\Services\Communications\Drivers\EmailDriver;
use App\Services\Communications\Drivers\InAppDriver;
use App\Services\Communications\Drivers\SmsDriver;
use App\Services\Communications\Drivers\WhatsAppDriver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ChannelDispatcher
{
    public function __construct(
        protected ConversationService $conversationService,
        protected PreferenceService $preferenceService,
        protected TokenBillingService $tokenBillingService,
        protected EmailDriver $emailDriver,
        protected SmsDriver $smsDriver,
        protected WhatsAppDriver $whatsAppDriver,
        protected InAppDriver $inAppDriver,
    ) {}

    /**
     * Send a single message through the specified channel.
     */
    public function send(Organization $org, SendMessageDTO $dto): CommunicationMessage
    {
        $recipientAddress = $this->resolveRecipientAddress($dto);

        // Find or create conversation
        $conversation = $this->conversationService->findOrCreateConversation(
            $org,
            $dto->recipientUserId,
            $recipientAddress,
            $dto->channel,
        );

        // Create the message record (queued status)
        $message = CommunicationMessage::create([
            'organization_id' => $org->id,
            'conversation_id' => $conversation->id,
            'channel' => $dto->channel,
            'direction' => $dto->direction,
            'sender_type' => $dto->senderType,
            'sender_id' => $dto->senderId,
            'recipient_user_id' => $dto->recipientUserId,
            'recipient_address' => $recipientAddress,
            'subject' => $dto->subject,
            'body_text' => $dto->bodyText,
            'body_html' => $dto->bodyHtml,
            'template_id' => $dto->templateId,
            'status' => CommunicationMessage::STATUS_QUEUED,
            'metadata' => $dto->metadata,
        ]);

        // Pre-check token balance for paid channels
        $tokenCost = $this->getTokenCost($dto->channel, $dto->senderType);
        if ($tokenCost > 0 && !$this->tokenBillingService->hasBalance($org, $tokenCost)) {
            $message->markFailed('Insufficient token balance');
            Log::warning('[ChannelDispatcher] Insufficient tokens', [
                'organization_id' => $org->id,
                'channel' => $dto->channel,
                'cost' => $tokenCost,
            ]);
            $this->conversationService->onMessageSent($conversation, $message);
            return $message;
        }

        // Dispatch to the channel driver
        try {
            $externalId = $this->dispatchToDriver($org, $message, $dto);
            $message->markSent($externalId);

            // Bill tokens after successful send
            if ($tokenCost > 0) {
                $this->tokenBillingService->deduct(
                    $org,
                    $tokenCost,
                    $dto->channel,
                    $message->id,
                    "Communication: {$dto->channel} message",
                    ['message_id' => $message->id, 'channel' => $dto->channel]
                );
                $message->update(['cost_tokens' => $tokenCost]);
            }
        } catch (\Throwable $e) {
            $message->markFailed($e->getMessage());

            Log::error('[ChannelDispatcher] Send failed', [
                'channel' => $dto->channel,
                'organization_id' => $org->id,
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Update conversation
        $this->conversationService->onMessageSent($conversation, $message);

        return $message;
    }

    /**
     * Send messages in bulk.
     */
    public function sendBulk(Organization $org, array $dtos): Collection
    {
        return collect($dtos)->map(fn (SendMessageDTO $dto) => $this->send($org, $dto));
    }

    /**
     * Send a message via the user's preferred channels for a notification type.
     */
    public function sendViaPreferred(
        Organization $org,
        int $recipientUserId,
        string $notificationType,
        string $bodyText,
        ?string $subject = null,
        ?string $bodyHtml = null,
        string $senderType = 'system',
        ?int $senderId = null,
        array $metadata = [],
    ): Collection {
        $user = User::find($recipientUserId);
        if (!$user) {
            return collect();
        }

        $channels = $this->preferenceService->getPreferredChannels($user, $notificationType);

        return collect($channels)->map(function (string $channel) use (
            $org, $recipientUserId, $bodyText, $subject, $bodyHtml, $senderType, $senderId, $metadata
        ) {
            $dto = new SendMessageDTO(
                channel: $channel,
                bodyText: $bodyText,
                recipientUserId: $recipientUserId,
                subject: $subject,
                bodyHtml: $bodyHtml,
                senderType: $senderType,
                senderId: $senderId,
                metadata: $metadata,
            );

            return $this->send($org, $dto);
        });
    }

    protected function dispatchToDriver(Organization $org, CommunicationMessage $message, SendMessageDTO $dto): ?string
    {
        return match ($dto->channel) {
            'email' => $this->emailDriver->send($org, $message, $dto),
            'sms' => $this->smsDriver->send($org, $message, $dto),
            'whatsapp' => $this->whatsAppDriver->send($org, $message, $dto),
            'in_app' => $this->inAppDriver->send($org, $message, $dto),
            default => throw new \InvalidArgumentException("Unsupported channel: {$dto->channel}"),
        };
    }

    protected function resolveRecipientAddress(SendMessageDTO $dto): ?string
    {
        if ($dto->recipientAddress) {
            return $dto->recipientAddress;
        }

        if (!$dto->recipientUserId) {
            return null;
        }

        $user = User::find($dto->recipientUserId);
        if (!$user) {
            return null;
        }

        return match ($dto->channel) {
            'email' => $user->email,
            'sms', 'whatsapp' => $user->notificationPreference?->phone_number ?? $user->mobile_number ?? null,
            'in_app', 'push' => null,
            default => $user->email,
        };
    }

    protected function getTokenCost(string $channel, string $senderType): int
    {
        if ($channel === 'in_app' || $channel === 'email') {
            return 0; // Email and in-app are free
        }

        $operationType = match ($channel) {
            'sms' => 'sms_send',
            'whatsapp' => $senderType === 'agent' ? 'whatsapp_ai_response' : 'whatsapp_send',
            default => null,
        };

        if (!$operationType) {
            return 0;
        }

        $pricing = \App\Models\AgentTokenPricing::getActivePricing('telnyx', $operationType);

        return $pricing?->platform_tokens_flat ?? 0;
    }
}
