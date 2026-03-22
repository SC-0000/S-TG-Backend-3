<?php

namespace App\Http\Controllers\Api\Admin;

use App\DTOs\SendMessageDTO;
use App\Http\Controllers\Api\ApiController;
use App\Models\AdminTask;
use App\Models\Conversation;
use App\Services\Communications\ChannelDispatcher;
use App\Services\Communications\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends ApiController
{
    public function __construct(
        protected ConversationService $conversationService,
        protected ChannelDispatcher $channelDispatcher,
    ) {}

    /**
     * List conversations for the current organization.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;

        $query = Conversation::forOrganization($orgId)
            ->with([
                'contactUser:id,name,email,role,mobile_number',
                'contactUser.notificationPreference:user_id,phone_number,whatsapp_enabled,sms_enabled,email_enabled',
                'contactUser.children' => fn ($q) => $q->where('organization_id', $orgId)->select('id', 'child_name', 'user_id', 'year_group'),
                'assignedUser:id,name',
                'latestMessage',
            ])
            ->withCount('messages')
            ->orderByDesc('last_message_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->input('assigned_to'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('contact_name', 'like', "%{$search}%")
                    ->orWhere('contact_phone', 'like', "%{$search}%")
                    ->orWhere('contact_email', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('unread_only')) {
            $query->withUnread();
        }

        $paginator = $query->paginate($request->input('per_page', 20));

        // Enrich each conversation with channels, AI stats, response metrics
        $conversationIds = collect($paginator->items())->pluck('id');

        $channelsByConversation = \App\Models\CommunicationMessage::whereIn('conversation_id', $conversationIds)
            ->selectRaw('conversation_id, GROUP_CONCAT(DISTINCT channel) as channels')
            ->groupBy('conversation_id')
            ->pluck('channels', 'conversation_id')
            ->map(fn ($ch) => explode(',', $ch));

        $aiMessageCounts = \App\Models\CommunicationMessage::whereIn('conversation_id', $conversationIds)
            ->where('sender_type', 'agent')
            ->selectRaw('conversation_id, COUNT(*) as cnt')
            ->groupBy('conversation_id')
            ->pluck('cnt', 'conversation_id');

        // Last inbound time per conversation (for response time calc)
        $lastInbound = \App\Models\CommunicationMessage::whereIn('conversation_id', $conversationIds)
            ->where('direction', 'inbound')
            ->selectRaw('conversation_id, MAX(created_at) as last_inbound_at')
            ->groupBy('conversation_id')
            ->pluck('last_inbound_at', 'conversation_id');

        $items = collect($paginator->items())->map(function ($conv) use ($channelsByConversation, $aiMessageCounts, $lastInbound) {
            $conv->channels_used = $channelsByConversation[$conv->id] ?? [];
            $conv->ai_message_count = $aiMessageCounts[$conv->id] ?? 0;
            $conv->last_inbound_at = $lastInbound[$conv->id] ?? null;

            // Calculate wait time if there's an unanswered inbound
            if ($conv->unread_count > 0 && $conv->last_inbound_at) {
                $conv->waiting_minutes = (int) now()->diffInMinutes($conv->last_inbound_at);
            } else {
                $conv->waiting_minutes = null;
            }

            return $conv;
        })->all();

        return $this->paginated($paginator, $items);
    }

    /**
     * Show a single conversation with its messages.
     */
    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;
        if ($conversation->organization_id !== (int) $orgId) {
            return $this->error('Conversation not found.', [], 404);
        }

        $conversation->load(['contactUser:id,name,email,role,mobile_number', 'assignedUser:id,name']);

        $messages = $this->conversationService->getHistory($conversation->id, $request->input('per_page', 50));

        // Mark as read when admin views the conversation
        $this->conversationService->markAsRead($conversation);

        // Build rich parent context for sidebar
        $parentContext = null;
        if ($conversation->contact_user_id) {
            $parent = \App\Models\User::with('notificationPreference')->find($conversation->contact_user_id);
            $org = \App\Models\Organization::find($orgId);
            if ($parent && $org) {
                $contextBuilder = app(\App\Services\Communications\ParentContextBuilder::class);
                $parentContext = $contextBuilder->build($parent, $org);
                $parentContext['notification_preferences'] = $parent->notificationPreference;
            }
        }

        // Get channels used in this conversation
        $channelsUsed = \App\Models\CommunicationMessage::where('conversation_id', $conversation->id)
            ->selectRaw('DISTINCT channel')
            ->pluck('channel')
            ->toArray();

        // Get AI session activity for this conversation
        $aiSession = \App\Models\AIAgentSession::where('agent_type', 'whatsapp_parent')
            ->where('is_active', true)
            ->whereJsonContains('session_metadata->conversation_id', $conversation->id)
            ->first();

        return $this->success([
            'conversation' => $conversation,
            'messages' => $messages->items(),
            'pagination' => [
                'total' => $messages->total(),
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
            ],
            'parent_context' => $parentContext,
            'channels_used' => $channelsUsed,
            'ai_session' => $aiSession ? [
                'id' => $aiSession->id,
                'is_active' => $aiSession->is_active,
                'last_interaction' => $aiSession->last_interaction,
                'interactions_count' => count($aiSession->session_data['history'] ?? []),
            ] : null,
        ]);
    }

    /**
     * Send a reply within a conversation.
     */
    public function reply(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;

        if ($conversation->organization_id !== (int) $orgId) {
            return $this->error('Conversation not found.', [], 404);
        }

        $validated = $request->validate([
            'channel' => 'required|in:email,sms,whatsapp,in_app',
            'body_text' => 'required|string|max:5000',
            'subject' => 'nullable|string|max:255',
            'body_html' => 'nullable|string',
        ]);

        $recipientUserId = $conversation->contact_user_id;
        $recipientAddress = match ($validated['channel']) {
            'sms', 'whatsapp' => $conversation->contact_phone,
            'email' => $conversation->contact_email,
            default => null,
        };

        $dto = new SendMessageDTO(
            channel: $validated['channel'],
            bodyText: $validated['body_text'],
            recipientUserId: $recipientUserId,
            recipientAddress: $recipientAddress,
            subject: $validated['subject'] ?? null,
            bodyHtml: $validated['body_html'] ?? null,
            senderType: $user->role === 'teacher' ? 'teacher' : 'admin',
            senderId: $user->id,
        );

        $org = \App\Models\Organization::findOrFail($orgId);
        $message = $this->channelDispatcher->send($org, $dto);

        return $this->success($message, [], 201);
    }

    /**
     * Assign a conversation to a user (human handoff).
     */
    public function assign(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'nullable|exists:users,id',
        ]);

        $this->conversationService->assign($conversation, $validated['user_id']);

        return $this->success($conversation->fresh());
    }

    /**
     * Create an AdminTask from a conversation.
     */
    public function createTask(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'priority' => 'nullable|in:low,medium,high,urgent',
        ]);

        $title = $validated['title']
            ?? "Follow up: conversation with {$conversation->contact_name}";

        $task = AdminTask::create([
            'organization_id' => $conversation->organization_id,
            'title' => $title,
            'description' => "Created from conversation #{$conversation->id} with {$conversation->contact_name}",
            'task_type' => 'communication_followup',
            'priority' => $validated['priority'] ?? 'medium',
            'status' => 'open',
            'source' => 'conversation',
            'source_model_type' => Conversation::class,
            'source_model_id' => $conversation->id,
            'created_by' => $user->id,
        ]);

        return $this->success($task, [], 201);
    }

    /**
     * Record a check-in (call/meeting) for a conversation's parent.
     */
    public function recordCheckIn(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'type' => 'required|in:call,meeting,message',
            'notes' => 'nullable|string|max:1000',
        ]);

        $conversation->update([
            'last_check_in_at' => now(),
            'last_check_in_type' => $validated['type'],
            'last_check_in_by' => $user->id,
        ]);

        // Log as a system message in the conversation
        \App\Models\CommunicationMessage::create([
            'organization_id' => $conversation->organization_id,
            'conversation_id' => $conversation->id,
            'channel' => 'in_app',
            'direction' => 'outbound',
            'sender_type' => 'system',
            'sender_id' => $user->id,
            'body_text' => ucfirst($validated['type']) . ' check-in recorded by ' . $user->name . ($validated['notes'] ? ': ' . $validated['notes'] : ''),
            'status' => 'delivered',
            'delivered_at' => now(),
            'metadata' => ['is_system_log' => true, 'type' => 'check_in', 'check_in_type' => $validated['type']],
        ]);

        return $this->success($conversation->fresh());
    }

    /**
     * Get pending/action-required conversations summary for dashboard widgets.
     */
    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;

        // Conversations with unread inbound messages (needs response)
        $needsResponse = Conversation::forOrganization($orgId)
            ->open()
            ->withUnread()
            ->whereNull('assigned_to') // Not yet assigned
            ->with(['contactUser:id,name,email', 'latestMessage'])
            ->orderByDesc('last_message_at')
            ->limit(5)
            ->get();

        // Conversations assigned to humans (human handling)
        $humanHandled = Conversation::forOrganization($orgId)
            ->open()
            ->whereNotNull('assigned_to')
            ->with(['contactUser:id,name,email', 'assignedUser:id,name', 'latestMessage'])
            ->orderByDesc('last_message_at')
            ->limit(5)
            ->get();

        // Recent AI responses (last 24h)
        $aiResponseCount = CommunicationMessage::forOrganization($orgId)
            ->where('sender_type', 'agent')
            ->where('channel', 'whatsapp')
            ->where('created_at', '>=', now()->subDay())
            ->count();

        // Total open conversations
        $totalOpen = Conversation::forOrganization($orgId)->open()->count();
        $totalUnread = Conversation::forOrganization($orgId)->open()->withUnread()->count();

        return $this->success([
            'needs_response' => $needsResponse,
            'human_handled' => $humanHandled,
            'stats' => [
                'total_open' => $totalOpen,
                'total_unread' => $totalUnread,
                'ai_responses_24h' => $aiResponseCount,
                'human_handled_count' => $humanHandled->count(),
            ],
        ]);
    }

    /**
     * Search users for the compose/send-to feature.
     */
    public function searchRecipients(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;
        $search = $request->input('search', '');
        $role = $request->input('role');

        $query = \App\Models\User::where('current_organization_id', $orgId);

        if ($role) {
            $query->where('role', $role);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('mobile_number', 'like', "%{$search}%");
            });
        }

        $users = $query->select('id', 'name', 'email', 'role', 'mobile_number')
            ->with(['notificationPreference:user_id,phone_number,whatsapp_enabled,sms_enabled,email_enabled'])
            ->limit(20)
            ->get();

        return $this->success($users);
    }

    /**
     * Send a message to a specific parent (used by QuickMessageButton).
     */
    public function sendToParent(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;

        $validated = $request->validate([
            'parent_id' => 'required|exists:users,id',
            'channel' => 'required|in:email,sms,whatsapp,in_app',
            'body_text' => 'required|string|max:5000',
            'subject' => 'nullable|string|max:255',
        ]);

        $dto = new SendMessageDTO(
            channel: $validated['channel'],
            bodyText: $validated['body_text'],
            recipientUserId: $validated['parent_id'],
            subject: $validated['subject'] ?? null,
            senderType: $user->role === 'teacher' ? 'teacher' : 'admin',
            senderId: $user->id,
        );

        $org = \App\Models\Organization::findOrFail($orgId);
        $message = $this->channelDispatcher->send($org, $dto);

        return $this->success($message, [], 201);
    }
}
