<?php

namespace App\Http\Controllers\Api\Teacher;

use App\DTOs\SendMessageDTO;
use App\Http\Controllers\Api\ApiController;
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
     * List conversations for the teacher (only their assigned students' parents).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;

        // Get parent IDs linked to teacher's assigned students
        $parentIds = \App\Models\Child::where('organization_id', $orgId)
            ->whereHas('teacherAssignments', function ($q) use ($user) {
                $q->where('teacher_id', $user->id);
            })
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->unique();

        $conversations = Conversation::forOrganization($orgId)
            ->whereIn('contact_user_id', $parentIds)
            ->with(['contactUser:id,name,email', 'latestMessage'])
            ->orderByDesc('last_message_at')
            ->paginate($request->input('per_page', 20));

        return $this->paginated($conversations, $conversations->items());
    }

    /**
     * Send a message to a parent of one of the teacher's assigned students.
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

        // Verify parent belongs to one of the teacher's assigned students
        $allowedParentIds = \App\Models\Child::where('organization_id', $orgId)
            ->whereHas('teacherAssignments', fn ($q) => $q->where('teacher_id', $user->id))
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->unique();

        if (!$allowedParentIds->contains($validated['parent_id'])) {
            return $this->error('You can only message parents of your assigned students.', [], 403);
        }

        $dto = new SendMessageDTO(
            channel: $validated['channel'],
            bodyText: $validated['body_text'],
            recipientUserId: $validated['parent_id'],
            subject: $validated['subject'] ?? null,
            senderType: 'teacher',
            senderId: $user->id,
        );

        $org = \App\Models\Organization::findOrFail($orgId);
        $message = $this->channelDispatcher->send($org, $dto);

        return $this->success($message, [], 201);
    }
}
