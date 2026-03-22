<?php

namespace App\Services\Communications;

use App\DTOs\SendMessageDTO;
use App\Models\AIAgentSession;
use App\Models\AppNotification;
use App\Models\CommunicationMessage;
use App\Models\Conversation;
use App\Models\Organization;
use App\Models\User;
use App\Services\AI\TokenBillingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppAgentService
{
    public function __construct(
        protected ConversationService $conversationService,
        protected ChannelDispatcher $channelDispatcher,
        protected ParentContextBuilder $contextBuilder,
        protected TokenBillingService $tokenBillingService,
    ) {}

    /**
     * Handle an inbound WhatsApp message from a parent.
     * Called from TelnyxWebhookController after message is recorded.
     */
    public function handleInboundMessage(
        Organization $org,
        Conversation $conversation,
        CommunicationMessage $inboundMessage,
        ?User $parent = null,
    ): void {
        // Don't auto-respond if conversation is assigned to a human
        if ($conversation->isAssignedToHuman()) {
            Log::info('[WhatsAppAgent] Conversation assigned to human, skipping AI response', [
                'conversation_id' => $conversation->id,
            ]);
            return;
        }

        // Check token balance
        if (!$this->tokenBillingService->hasBalance($org, 3)) {
            Log::warning('[WhatsAppAgent] Insufficient token balance', ['org_id' => $org->id]);
            return;
        }

        try {
            $response = $this->generateResponse($org, $conversation, $inboundMessage, $parent);

            if ($response) {
                // Send the AI response via WhatsApp
                $dto = SendMessageDTO::whatsapp(
                    bodyText: $response,
                    recipientUserId: $parent?->id,
                    recipientAddress: $conversation->contact_phone,
                    senderType: 'agent',
                );

                $this->channelDispatcher->send($org, $dto);
            }
        } catch (\Throwable $e) {
            Log::error('[WhatsAppAgent] Failed to generate response', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Generate an AI response using the parent's context.
     */
    protected function generateResponse(
        Organization $org,
        Conversation $conversation,
        CommunicationMessage $inboundMessage,
        ?User $parent,
    ): ?string {
        // Build parent context
        $context = $parent
            ? $this->contextBuilder->build($parent, $org)
            : ['parent' => ['name' => $conversation->contact_name ?? 'Unknown'], 'children' => []];

        $systemPrompt = $parent
            ? $this->contextBuilder->toSystemPrompt($context, $org)
            : $this->getUnknownContactPrompt($org);

        // Get or create AI session
        $session = $this->getOrCreateSession($conversation, $parent);

        // Build conversation history from recent messages
        $recentMessages = CommunicationMessage::where('conversation_id', $conversation->id)
            ->whereIn('channel', ['whatsapp', 'sms'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->reverse()
            ->map(fn ($m) => [
                'role' => $m->direction === 'inbound' ? 'user' : 'assistant',
                'content' => $m->body_text,
            ])
            ->values()
            ->toArray();

        // Call AI API
        $response = $this->callAI($systemPrompt, $recentMessages, $org, $context);

        // Update session
        $this->updateSession($session, $inboundMessage->body_text, $response);

        return $response;
    }

    /**
     * Call the AI API (OpenAI-compatible) with tool definitions.
     */
    protected function callAI(string $systemPrompt, array $messages, Organization $org, array $context): ?string
    {
        $apiKey = config('services.openai.key');
        if (!$apiKey) {
            Log::warning('[WhatsAppAgent] No OpenAI API key configured');
            return 'Sorry, I\'m unable to respond right now. A team member will get back to you shortly.';
        }

        $tools = WhatsAppAgentTools::getToolDefinitions();

        $aiMessages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $messages
        );

        $payload = [
            'model' => config('services.openai.model', 'gpt-4o-mini'),
            'messages' => $aiMessages,
            'tools' => array_map(fn ($t) => ['type' => 'function', 'function' => $t], $tools),
            'max_tokens' => 500,
            'temperature' => 0.7,
        ];

        $response = Http::withToken($apiKey)
            ->timeout(30)
            ->post('https://api.openai.com/v1/chat/completions', $payload);

        if (!$response->successful()) {
            Log::error('[WhatsAppAgent] AI API failed', ['status' => $response->status()]);
            return null;
        }

        $choice = $response->json('choices.0');
        $message = $choice['message'] ?? [];

        // Handle tool calls
        if (!empty($message['tool_calls'])) {
            return $this->handleToolCalls($message['tool_calls'], $aiMessages, $org, $context);
        }

        return $message['content'] ?? null;
    }

    /**
     * Execute tool calls and get a follow-up response from the AI.
     */
    protected function handleToolCalls(array $toolCalls, array $messages, Organization $org, array $context): ?string
    {
        $toolResults = [];

        foreach ($toolCalls as $toolCall) {
            $functionName = $toolCall['function']['name'] ?? '';
            $args = json_decode($toolCall['function']['arguments'] ?? '{}', true);

            $result = WhatsAppAgentTools::execute($functionName, $args, $org);

            // Handle escalation
            if ($functionName === 'escalate_to_human' && ($result['action'] ?? '') === 'escalate') {
                // The conversation will be assigned to a human by the caller
                return $result['message'];
            }

            $toolResults[] = [
                'role' => 'tool',
                'tool_call_id' => $toolCall['id'],
                'content' => json_encode($result),
            ];
        }

        // Call AI again with tool results
        $followUpMessages = array_merge(
            $messages,
            [['role' => 'assistant', 'content' => null, 'tool_calls' => $toolCalls]],
            $toolResults
        );

        $apiKey = config('services.openai.key');
        $response = Http::withToken($apiKey)
            ->timeout(30)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('services.openai.model', 'gpt-4o-mini'),
                'messages' => $followUpMessages,
                'max_tokens' => 500,
                'temperature' => 0.7,
            ]);

        return $response->json('choices.0.message.content');
    }

    protected function getOrCreateSession(Conversation $conversation, ?User $parent): AIAgentSession
    {
        // Use conversation_id in session_metadata to uniquely identify WhatsApp sessions
        $existing = AIAgentSession::where('agent_type', 'whatsapp_parent')
            ->where('is_active', true)
            ->whereJsonContains('session_metadata->conversation_id', $conversation->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        return AIAgentSession::create([
            'child_id' => $parent?->children()?->first()?->id ?? 0,
            'agent_type' => 'whatsapp_parent',
            'is_active' => true,
            'session_data' => ['conversation_id' => $conversation->id],
            'session_metadata' => [
                'conversation_id' => $conversation->id,
                'parent_user_id' => $parent?->id,
                'organization_id' => $conversation->organization_id,
            ],
            'last_interaction' => now(),
        ]);
    }

    protected function updateSession(AIAgentSession $session, string $userMessage, ?string $aiResponse): void
    {
        $data = $session->session_data ?? [];
        $history = $data['history'] ?? [];
        $history[] = ['user' => $userMessage, 'assistant' => $aiResponse, 'at' => now()->toISOString()];

        // Keep last 20 interactions
        if (count($history) > 20) {
            $history = array_slice($history, -20);
        }

        $data['history'] = $history;
        $session->update([
            'session_data' => $data,
            'last_interaction' => now(),
        ]);
    }

    protected function getUnknownContactPrompt(Organization $org): string
    {
        $orgName = $org->getSetting('branding.organization_name') ?? $org->name;

        return "You are a helpful assistant for {$orgName}. "
            . "Someone has messaged on WhatsApp but we don't recognise their phone number. "
            . "Politely ask them to identify themselves (name and which child they are enquiring about). "
            . "If they need help with something complex, offer to connect them with a team member.";
    }
}
