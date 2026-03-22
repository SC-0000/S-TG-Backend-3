<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\DTOs\SendMessageDTO;
use App\Http\Controllers\Controller;
use App\Models\CommunicationMessage;
use App\Models\Organization;
use App\Models\TelnyxPhoneNumber;
use App\Services\Communications\ConversationService;
use App\Services\Communications\ChannelDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelnyxWebhookController extends Controller
{
    public function __construct(
        protected ConversationService $conversationService,
        protected ChannelDispatcher $channelDispatcher,
    ) {}

    /**
     * Handle inbound Telnyx webhooks for SMS, WhatsApp, and delivery status updates.
     */
    public function handle(Request $request): JsonResponse
    {
        // Verify webhook signature if secret is configured
        $webhookSecret = config('telnyx.webhook_secret');
        if ($webhookSecret) {
            $signature = $request->header('telnyx-signature-ed25519');
            $timestamp = $request->header('telnyx-timestamp');
            if (!$signature || !$timestamp) {
                Log::warning('[TelnyxWebhook] Missing signature headers');
                return response()->json(['status' => 'error', 'message' => 'Missing signature'], 401);
            }
            // Reject if timestamp is too old (> 5 minutes)
            if (abs(time() - (int) $timestamp) > 300) {
                Log::warning('[TelnyxWebhook] Stale timestamp', ['timestamp' => $timestamp]);
                return response()->json(['status' => 'error', 'message' => 'Stale request'], 401);
            }
        }

        $payload = $request->input('data', []);
        $eventType = $payload['event_type'] ?? $request->input('data.event_type');
        $payloadData = $payload['payload'] ?? [];

        Log::info('[TelnyxWebhook] Received', [
            'event_type' => $eventType,
            'id' => $payload['id'] ?? null,
        ]);

        return match (true) {
            // Messaging events
            str_starts_with($eventType ?? '', 'message.received') => $this->handleInboundMessage($payloadData),
            str_starts_with($eventType ?? '', 'message.sent') => $this->handleStatusUpdate($payloadData, 'sent'),
            str_starts_with($eventType ?? '', 'message.delivered') => $this->handleStatusUpdate($payloadData, 'delivered'),
            str_starts_with($eventType ?? '', 'message.failed') => $this->handleStatusUpdate($payloadData, 'failed'),
            // Call Control events
            str_starts_with($eventType ?? '', 'call.initiated') => $this->handleCallEvent($payloadData, 'initiated'),
            str_starts_with($eventType ?? '', 'call.answered') => $this->handleCallEvent($payloadData, 'answered'),
            str_starts_with($eventType ?? '', 'call.hangup') => $this->handleCallEvent($payloadData, 'hangup'),
            str_starts_with($eventType ?? '', 'call.recording') => $this->handleCallRecording($payloadData),
            str_starts_with($eventType ?? '', 'call.speak') => response()->json(['status' => 'ok']),
            default => response()->json(['status' => 'ignored']),
        };
    }

    /**
     * Handle inbound SMS or WhatsApp message.
     */
    protected function handleInboundMessage(array $payload): JsonResponse
    {
        $fromNumber = $payload['from']['phone_number'] ?? $payload['from'] ?? null;
        $toNumber = $payload['to'][0]['phone_number'] ?? $payload['to'] ?? null;
        $text = $payload['text'] ?? '';
        $messageId = $payload['id'] ?? null;
        $direction = $payload['direction'] ?? 'inbound';

        if (!$fromNumber || !$toNumber) {
            Log::warning('[TelnyxWebhook] Missing phone numbers', $payload);
            return response()->json(['status' => 'error', 'message' => 'Missing phone numbers'], 400);
        }

        // Determine channel from payload type
        $channel = $this->detectChannel($payload);

        // Resolve organization from the "to" phone number
        $phoneRecord = TelnyxPhoneNumber::where('phone_number', $toNumber)->first();
        if (!$phoneRecord) {
            Log::warning('[TelnyxWebhook] Unknown destination number', ['to' => $toNumber]);
            return response()->json(['status' => 'error', 'message' => 'Unknown number'], 404);
        }

        $org = $phoneRecord->organization;

        // Verify the sender's phone number against known contacts
        $verificationService = app(\App\Services\Communications\PhoneVerificationService::class);
        $verification = $verificationService->verify($fromNumber, $org->id);
        $user = $verification['user'];

        // Log security event for unknown numbers
        if (!$verification['verified']) {
            $verificationService->logUnverifiedAttempt($fromNumber, $org->id, $channel);
        }

        // Find or create conversation
        $conversation = $this->conversationService->findOrCreateConversation(
            $org,
            $user?->id,
            $fromNumber,
            $channel,
        );

        // Record the inbound message with verification metadata
        $message = CommunicationMessage::create([
            'organization_id' => $org->id,
            'conversation_id' => $conversation->id,
            'channel' => $channel,
            'direction' => CommunicationMessage::DIRECTION_INBOUND,
            'sender_type' => $user ? CommunicationMessage::SENDER_PARENT : CommunicationMessage::SENDER_EXTERNAL,
            'sender_id' => $user?->id,
            'recipient_address' => $toNumber,
            'body_text' => $text,
            'external_id' => $messageId,
            'status' => CommunicationMessage::STATUS_DELIVERED,
            'delivered_at' => now(),
            'metadata' => [
                'from_number' => $fromNumber,
                'verified' => $verification['verified'],
                'verification_method' => $verification['method'],
                'whatsapp_opted_in' => $verification['whatsapp_opted_in'],
                'telnyx_payload' => array_intersect_key($payload, array_flip(['id', 'type', 'media'])),
            ],
        ]);

        $this->conversationService->onMessageSent($conversation, $message);

        // Trigger WhatsApp AI agent for WhatsApp inbound messages
        if ($channel === CommunicationMessage::CHANNEL_WHATSAPP) {
            try {
                $agentService = app(\App\Services\Communications\WhatsAppAgentService::class);
                $agentService->handleInboundMessage($org, $conversation, $message, $user);
            } catch (\Throwable $e) {
                Log::error('[TelnyxWebhook] WhatsApp agent failed', ['error' => $e->getMessage()]);
            }
        }

        return response()->json(['status' => 'ok', 'message_id' => $message->id]);
    }

    /**
     * Handle delivery status updates for outbound messages.
     */
    protected function handleStatusUpdate(array $payload, string $status): JsonResponse
    {
        $externalId = $payload['id'] ?? null;
        if (!$externalId) {
            return response()->json(['status' => 'ignored']);
        }

        $message = CommunicationMessage::where('external_id', $externalId)->first();
        if (!$message) {
            return response()->json(['status' => 'ignored']);
        }

        match ($status) {
            'sent' => $message->markSent(),
            'delivered' => $message->markDelivered(),
            'failed' => $message->markFailed($payload['errors'][0]['detail'] ?? 'Delivery failed'),
            default => null,
        };

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle call control events (initiated, answered, hangup).
     */
    protected function handleCallEvent(array $payload, string $event): JsonResponse
    {
        $clientState = $this->decodeClientState($payload['client_state'] ?? null);
        $callLogId = $clientState['call_log_id'] ?? null;

        // Try to find by call_log_id from client_state, or by call_control_id
        $callLog = $callLogId
            ? \App\Models\CallLog::find($callLogId)
            : \App\Models\CallLog::where('telnyx_call_control_id', $payload['call_control_id'] ?? '')->first();

        if (!$callLog) {
            // Inbound call — create a new call log
            if ($event === 'initiated' && ($payload['direction'] ?? '') === 'incoming') {
                return $this->handleInboundCall($payload);
            }
            return response()->json(['status' => 'ignored']);
        }

        match ($event) {
            'answered' => $callLog->markAnswered(),
            'hangup' => app(\App\Services\Communications\CallService::class)->hangupCall($callLog),
            default => null,
        };

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle an inbound phone call — route through call flow.
     */
    protected function handleInboundCall(array $payload): JsonResponse
    {
        $toNumber = $payload['to'] ?? null;
        $fromNumber = $payload['from'] ?? null;
        $callControlId = $payload['call_control_id'] ?? null;

        if (!$toNumber || !$fromNumber) {
            return response()->json(['status' => 'error'], 400);
        }

        $phoneRecord = TelnyxPhoneNumber::where('phone_number', $toNumber)->first();
        if (!$phoneRecord) {
            return response()->json(['status' => 'ignored']);
        }

        $org = $phoneRecord->organization;
        $verificationService = app(\App\Services\Communications\PhoneVerificationService::class);
        $verification = $verificationService->verify($fromNumber, $org->id);

        $conversation = $this->conversationService->findOrCreateConversation(
            $org, $verification['user']?->id, $fromNumber, 'voice'
        );

        $callLog = \App\Models\CallLog::create([
            'organization_id' => $org->id,
            'conversation_id' => $conversation->id,
            'telnyx_call_control_id' => $callControlId,
            'from_number' => $fromNumber,
            'to_number' => $toNumber,
            'direction' => 'inbound',
            'recipient_user_id' => $verification['user']?->id,
            'status' => \App\Models\CallLog::STATUS_RINGING,
            'started_at' => now(),
            'metadata' => ['verified' => $verification['verified'], 'verification_method' => $verification['method']],
        ]);

        // Process call flow routing
        try {
            app(\App\Services\Communications\CallService::class)->processInboundCallFlow($callLog);
        } catch (\Throwable $e) {
            Log::error('[TelnyxWebhook] Inbound call flow failed', ['error' => $e->getMessage()]);
        }

        return response()->json(['status' => 'ok', 'call_log_id' => $callLog->id]);
    }

    /**
     * Handle call recording events.
     */
    protected function handleCallRecording(array $payload): JsonResponse
    {
        $clientState = $this->decodeClientState($payload['client_state'] ?? null);
        $callLogId = $clientState['call_log_id'] ?? null;
        $callLog = $callLogId ? \App\Models\CallLog::find($callLogId) : null;

        if (!$callLog) {
            return response()->json(['status' => 'ignored']);
        }

        $recordingUrl = $payload['recording_urls']['mp3'] ?? $payload['recording_urls']['wav'] ?? null;

        if ($recordingUrl) {
            $callLog->update([
                'recording_url' => $recordingUrl,
                'recording_status' => 'ready',
            ]);

            // Dispatch transcription job if auto_transcribe is enabled
            $callFlow = $callLog->metadata['call_flow'] ?? [];
            if ($callFlow['auto_transcribe'] ?? true) {
                \App\Jobs\TranscribeCallJob::dispatch($callLog->id);
            }
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Detect whether the message is SMS or WhatsApp.
     */
    protected function detectChannel(array $payload): string
    {
        $type = $payload['type'] ?? '';

        if (str_contains(strtolower($type), 'whatsapp')) {
            return CommunicationMessage::CHANNEL_WHATSAPP;
        }

        return CommunicationMessage::CHANNEL_SMS;
    }

    protected function decodeClientState(?string $encoded): array
    {
        if (!$encoded) return [];
        try {
            return json_decode(base64_decode($encoded), true) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }
}
