<?php

namespace App\Services\Communications;

use App\Models\CallLog;
use App\Models\CommunicationMessage;
use App\Models\Conversation;
use App\Models\Organization;
use App\Models\TelnyxPhoneNumber;
use App\Models\User;
use App\Services\AI\TokenBillingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CallService
{
    public function __construct(
        protected ConversationService $conversationService,
        protected TokenBillingService $tokenBillingService,
    ) {}

    /**
     * Initiate an outbound call to a parent.
     */
    public function initiateCall(
        Organization $org,
        User $initiator,
        string $toNumber,
        ?int $recipientUserId = null,
        ?int $conversationId = null,
    ): CallLog {
        $apiKey = $this->resolveApiKey($org);
        $fromNumber = $this->resolveFromNumber($org);
        $callFlow = $this->getCallFlow($org);

        // Find or create conversation
        $conversation = $conversationId
            ? Conversation::find($conversationId)
            : $this->conversationService->findOrCreateConversation($org, $recipientUserId, $toNumber, 'voice');

        // Create call log
        $callLog = CallLog::create([
            'organization_id' => $org->id,
            'conversation_id' => $conversation?->id,
            'from_number' => $fromNumber,
            'to_number' => $toNumber,
            'direction' => 'outbound',
            'initiated_by' => $initiator->id,
            'recipient_user_id' => $recipientUserId,
            'status' => CallLog::STATUS_INITIATING,
            'started_at' => now(),
            'metadata' => [
                'call_flow' => $callFlow,
                'record' => $callFlow['record_calls'] ?? true,
            ],
        ]);

        // Initiate via Telnyx Call Control API
        try {
            $response = Http::withToken($apiKey)
                ->post('https://api.telnyx.com/v2/calls', [
                    'connection_id' => $this->resolveConnectionId($org),
                    'to' => $toNumber,
                    'from' => $fromNumber,
                    'webhook_url' => config('app.url') . '/api/v1/webhooks/telnyx',
                    'client_state' => base64_encode(json_encode([
                        'call_log_id' => $callLog->id,
                        'org_id' => $org->id,
                    ])),
                ]);

            if (!$response->successful()) {
                $error = $response->json('errors.0.detail') ?? $response->body();
                $callLog->markFailed($error);
                throw new \RuntimeException("Telnyx call failed: {$error}");
            }

            $callControlId = $response->json('data.call_control_id');
            $callLegId = $response->json('data.call_leg_id');

            $callLog->update([
                'telnyx_call_control_id' => $callControlId,
                'telnyx_call_leg_id' => $callLegId,
                'status' => CallLog::STATUS_RINGING,
            ]);

            Log::info('[CallService] Call initiated', [
                'call_log_id' => $callLog->id,
                'to' => $toNumber,
                'call_control_id' => $callControlId,
            ]);
        } catch (\Throwable $e) {
            if ($callLog->status === CallLog::STATUS_INITIATING) {
                $callLog->markFailed($e->getMessage());
            }
            throw $e;
        }

        return $callLog;
    }

    /**
     * Answer a call and optionally start recording.
     */
    public function answerCall(CallLog $callLog): void
    {
        $apiKey = $this->resolveApiKey($callLog->organization);

        Http::withToken($apiKey)
            ->post("https://api.telnyx.com/v2/calls/{$callLog->telnyx_call_control_id}/actions/answer", [
                'client_state' => base64_encode(json_encode(['call_log_id' => $callLog->id])),
            ]);

        $callLog->markAnswered();

        // Start recording if enabled
        $callFlow = $callLog->metadata['call_flow'] ?? [];
        if ($callFlow['record_calls'] ?? true) {
            $this->startRecording($callLog);
        }
    }

    /**
     * Start recording the call.
     */
    public function startRecording(CallLog $callLog): void
    {
        $apiKey = $this->resolveApiKey($callLog->organization);

        try {
            Http::withToken($apiKey)
                ->post("https://api.telnyx.com/v2/calls/{$callLog->telnyx_call_control_id}/actions/record_start", [
                    'format' => 'mp3',
                    'channels' => 'dual',
                    'client_state' => base64_encode(json_encode(['call_log_id' => $callLog->id])),
                ]);

            $callLog->update([
                'status' => CallLog::STATUS_RECORDING,
                'recording_status' => 'recording',
            ]);
        } catch (\Throwable $e) {
            Log::warning('[CallService] Failed to start recording', [
                'call_log_id' => $callLog->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Hangup a call.
     */
    public function hangupCall(CallLog $callLog): void
    {
        if (!$callLog->telnyx_call_control_id) return;

        $apiKey = $this->resolveApiKey($callLog->organization);

        try {
            Http::withToken($apiKey)
                ->post("https://api.telnyx.com/v2/calls/{$callLog->telnyx_call_control_id}/actions/hangup");
        } catch (\Throwable $e) {
            Log::warning('[CallService] Hangup failed', ['error' => $e->getMessage()]);
        }

        $callLog->markCompleted();

        // Log in conversation as system message
        if ($callLog->conversation_id) {
            $duration = $callLog->duration_seconds;
            $mins = intdiv($duration, 60);
            $secs = $duration % 60;

            CommunicationMessage::create([
                'organization_id' => $callLog->organization_id,
                'conversation_id' => $callLog->conversation_id,
                'channel' => 'voice',
                'direction' => $callLog->direction,
                'sender_type' => 'system',
                'sender_id' => $callLog->initiated_by,
                'recipient_user_id' => $callLog->recipient_user_id,
                'body_text' => "Call {$callLog->direction} ({$mins}m {$secs}s) — {$callLog->status}",
                'status' => CommunicationMessage::STATUS_DELIVERED,
                'delivered_at' => now(),
                'metadata' => [
                    'is_system_log' => true,
                    'type' => 'call',
                    'call_log_id' => $callLog->id,
                    'duration_seconds' => $duration,
                    'recording_url' => $callLog->recording_url,
                ],
            ]);
        }

        // Bill tokens
        $this->billCall($callLog);

        // Update conversation check-in
        if ($callLog->conversation_id) {
            Conversation::where('id', $callLog->conversation_id)->update([
                'last_check_in_at' => now(),
                'last_check_in_type' => 'call',
                'last_check_in_by' => $callLog->initiated_by,
            ]);
        }
    }

    /**
     * Play a greeting/hold message on an inbound call.
     */
    public function playGreeting(CallLog $callLog, string $message): void
    {
        $apiKey = $this->resolveApiKey($callLog->organization);

        Http::withToken($apiKey)
            ->post("https://api.telnyx.com/v2/calls/{$callLog->telnyx_call_control_id}/actions/speak", [
                'payload' => $message,
                'voice' => 'female',
                'language' => 'en-GB',
                'client_state' => base64_encode(json_encode([
                    'call_log_id' => $callLog->id,
                    'action' => 'greeting_played',
                ])),
            ]);
    }

    /**
     * Transfer/bridge a call to another number (routing).
     */
    public function transferCall(CallLog $callLog, string $toNumber): void
    {
        $apiKey = $this->resolveApiKey($callLog->organization);

        Http::withToken($apiKey)
            ->post("https://api.telnyx.com/v2/calls/{$callLog->telnyx_call_control_id}/actions/transfer", [
                'to' => $toNumber,
                'client_state' => base64_encode(json_encode([
                    'call_log_id' => $callLog->id,
                    'action' => 'transferred',
                ])),
            ]);

        $callLog->update(['status' => CallLog::STATUS_BRIDGING]);
    }

    /**
     * Get the call flow configuration for an organization.
     */
    public function getCallFlow(Organization $org): array
    {
        return $org->getSetting('call_flow', [
            'enabled' => false,
            'greeting_message' => 'Thank you for calling. Please hold while we connect you to the right person.',
            'no_answer_message' => 'Sorry, no one is available right now. We will call you back as soon as possible.',
            'voicemail_enabled' => true,
            'ring_timeout_seconds' => 30,
            'record_calls' => true,
            'auto_transcribe' => true,
            'routing' => [],
        ]);
    }

    /**
     * Process inbound call routing based on the org's call flow.
     */
    public function processInboundCallFlow(CallLog $callLog): void
    {
        $callFlow = $this->getCallFlow($callLog->organization);

        // Play greeting
        if ($greeting = ($callFlow['greeting_message'] ?? null)) {
            $this->playGreeting($callLog, $greeting);
        }

        // Try routing in priority order
        $routing = $callFlow['routing'] ?? [];
        usort($routing, fn ($a, $b) => ($a['priority'] ?? 99) - ($b['priority'] ?? 99));

        foreach ($routing as $route) {
            if ($route['type'] === 'user') {
                $user = User::find($route['user_id'] ?? 0);
                if ($user) {
                    $phone = $user->notificationPreference?->phone_number ?? $user->mobile_number;
                    if ($phone) {
                        $this->transferCall($callLog, $phone);
                        return;
                    }
                }
            } elseif ($route['type'] === 'phone' && !empty($route['phone'])) {
                $this->transferCall($callLog, $route['phone']);
                return;
            }
        }

        // No one available — play voicemail message
        $noAnswerMsg = $callFlow['no_answer_message'] ?? 'We are currently unavailable. Please try again later.';
        $this->playGreeting($callLog, $noAnswerMsg);

        // Hangup after message
        $callLog->update(['status' => CallLog::STATUS_NO_ANSWER]);
    }

    protected function billCall(CallLog $callLog): void
    {
        $org = $callLog->organization;
        $minutes = max(1, (int) ceil($callLog->duration_seconds / 60));

        $pricing = \App\Models\AgentTokenPricing::getActivePricing('telnyx', 'voice_minute');
        $tokensPerMinute = $pricing?->platform_tokens_flat ?? 5;
        $totalTokens = $minutes * $tokensPerMinute;

        if ($totalTokens > 0 && $this->tokenBillingService->hasBalance($org, $totalTokens)) {
            $this->tokenBillingService->deduct(
                $org,
                $totalTokens,
                'voice',
                $callLog->id,
                "Voice call ({$minutes} min)",
                ['call_log_id' => $callLog->id, 'minutes' => $minutes]
            );
            $callLog->update(['cost_tokens' => $totalTokens]);
        }
    }

    protected function resolveApiKey(Organization $org): string
    {
        return $org->getApiKey('telnyx') ?? config('telnyx.api_key')
            ?? throw new \RuntimeException('No Telnyx API key configured');
    }

    protected function resolveFromNumber(Organization $org): string
    {
        $phoneRecord = TelnyxPhoneNumber::getDefaultForOrg($org->id);
        return $phoneRecord?->phone_number
            ?? $org->getSetting('telnyx.phone_number')
            ?? config('telnyx.default_phone_number')
            ?? throw new \RuntimeException('No Telnyx phone number configured');
    }

    protected function resolveConnectionId(Organization $org): string
    {
        return $org->getSetting('telnyx.connection_id')
            ?? config('telnyx.connection_id')
            ?? throw new \RuntimeException('No Telnyx connection ID configured');
    }
}
