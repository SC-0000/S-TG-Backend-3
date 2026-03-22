<?php

namespace App\Services\AI\BackgroundAgents\Agents;

use App\Models\BackgroundAgentAction;
use App\Models\CommunicationMessage;
use App\Models\Conversation;
use App\Services\AI\BackgroundAgents\AbstractBackgroundAgent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp Communication Agent
 *
 * Monitors WhatsApp conversations and performs scheduled maintenance:
 * - Reviews unanswered inbound messages and generates AI responses
 * - Escalates stale conversations to admin
 * - Generates daily summaries of WhatsApp activity
 * - Checks for conversations needing human attention
 *
 * Real-time WhatsApp responses are handled by WhatsAppAgentService (event-driven).
 * This agent handles the scheduled sweep for anything missed.
 */
class WhatsAppAgent extends AbstractBackgroundAgent
{
    public static function getAgentType(): string
    {
        return 'whatsapp_agent';
    }

    public static function getDefaultSchedule(): string
    {
        return '*/30 * * * *'; // Every 30 minutes
    }

    public static function getDescription(): string
    {
        return 'Monitors WhatsApp conversations, responds to unanswered messages via AI, escalates stale conversations, and maintains conversation quality. Real-time responses are event-driven; this agent sweeps for missed messages.';
    }

    public static function getEstimatedTokensPerRun(): int
    {
        return 10;
    }

    public static function getEventTriggers(): array
    {
        return [];
    }

    protected function execute(): void
    {
        if (!$this->organization) {
            return;
        }

        $orgId = $this->organization->id;

        // 1. Find unanswered inbound WhatsApp messages (older than 2 minutes, no outbound reply)
        $this->processUnansweredMessages($orgId);

        // 2. Escalate stale conversations (open > 24h with no response)
        $this->escalateStaleConversations($orgId);

        // 3. Log activity summary
        $this->logActivitySummary($orgId);
    }

    /**
     * Find inbound WhatsApp messages that haven't received a reply and trigger AI response.
     */
    protected function processUnansweredMessages(int $orgId): void
    {
        $unanswered = DB::table('communication_messages as cm')
            ->join('conversations as c', 'c.id', '=', 'cm.conversation_id')
            ->where('cm.organization_id', $orgId)
            ->where('cm.channel', 'whatsapp')
            ->where('cm.direction', 'inbound')
            ->where('cm.created_at', '<=', now()->subMinutes(2))
            ->where('cm.created_at', '>=', now()->subHours(1))
            ->whereNull('c.assigned_to') // Not assigned to a human
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('communication_messages as reply')
                    ->whereColumn('reply.conversation_id', 'cm.conversation_id')
                    ->where('reply.direction', 'outbound')
                    ->whereColumn('reply.created_at', '>', 'cm.created_at');
            })
            ->select('cm.id', 'cm.conversation_id', 'cm.sender_id', 'cm.body_text')
            ->limit(10)
            ->get();

        foreach ($unanswered as $msg) {
            $this->incrementProcessed();

            try {
                $conversation = Conversation::find($msg->conversation_id);
                if (!$conversation) continue;

                $message = CommunicationMessage::find($msg->id);
                if (!$message) continue;

                $parent = $msg->sender_id ? \App\Models\User::find($msg->sender_id) : null;

                // Trigger the real-time WhatsApp agent service
                $agentService = app(\App\Services\Communications\WhatsAppAgentService::class);
                $agentService->handleInboundMessage($this->organization, $conversation, $message, $parent);

                $this->incrementAffected();

                $this->logAction(
                    BackgroundAgentAction::ACTION_SEND_WHATSAPP,
                    $message,
                    "AI response generated for unanswered WhatsApp message from {$conversation->contact_name}"
                );
            } catch (\Throwable $e) {
                Log::warning("[WhatsAppAgent] Failed to process message #{$msg->id}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Escalate conversations that have been open with no response for too long.
     */
    protected function escalateStaleConversations(int $orgId): void
    {
        $staleHours = (int) $this->getConfig('escalate_after_hours', 24);

        $staleConversations = Conversation::where('organization_id', $orgId)
            ->where('status', Conversation::STATUS_OPEN)
            ->where('unread_count', '>', 0)
            ->whereNull('assigned_to')
            ->where('last_message_at', '<=', now()->subHours($staleHours))
            ->limit(5)
            ->get();

        foreach ($staleConversations as $conversation) {
            $this->incrementProcessed();

            // Notify org admins about stale conversation
            $admins = $this->organization->users()
                ->wherePivot('role', 'org_admin')
                ->wherePivot('status', 'active')
                ->limit(3)
                ->get();

            foreach ($admins as $admin) {
                \App\Models\AppNotification::create([
                    'user_id' => $admin->id,
                    'title' => 'Stale WhatsApp Conversation',
                    'message' => "Conversation with {$conversation->contact_name} has been unresolved for {$staleHours}+ hours. Please review.",
                    'type' => 'alert',
                    'status' => 'unread',
                    'channel' => 'in_app',
                ]);
            }

            $this->incrementAffected();

            $this->logAction(
                BackgroundAgentAction::ACTION_CHECK,
                $conversation,
                "Escalated stale conversation with {$conversation->contact_name} ({$staleHours}h+ unresolved)"
            );
        }
    }

    /**
     * Log a summary of recent WhatsApp activity.
     */
    protected function logActivitySummary(int $orgId): void
    {
        $since = now()->subMinutes(30);

        $inbound = CommunicationMessage::where('organization_id', $orgId)
            ->where('channel', 'whatsapp')
            ->where('direction', 'inbound')
            ->where('created_at', '>=', $since)
            ->count();

        $outbound = CommunicationMessage::where('organization_id', $orgId)
            ->where('channel', 'whatsapp')
            ->where('direction', 'outbound')
            ->where('created_at', '>=', $since)
            ->count();

        $aiResponses = CommunicationMessage::where('organization_id', $orgId)
            ->where('channel', 'whatsapp')
            ->where('direction', 'outbound')
            ->where('sender_type', 'agent')
            ->where('created_at', '>=', $since)
            ->count();

        $openConversations = Conversation::where('organization_id', $orgId)
            ->where('status', 'open')
            ->where('unread_count', '>', 0)
            ->count();

        if ($inbound > 0 || $outbound > 0) {
            $this->logAction(
                BackgroundAgentAction::ACTION_CHECK,
                null,
                "WhatsApp activity (last 30m): {$inbound} inbound, {$outbound} outbound ({$aiResponses} AI), {$openConversations} open conversations"
            );
        }
    }
}
