<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\CallLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\Communications\CallService;
use App\Services\Communications\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CallController extends ApiController
{
    public function __construct(
        protected CallService $callService,
        protected ConversationService $conversationService,
    ) {}

    /**
     * Initiate an outbound call.
     */
    public function initiate(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;

        $validated = $request->validate([
            'to_number' => 'required|string|max:20',
            'recipient_user_id' => 'nullable|exists:users,id',
            'conversation_id' => 'nullable|exists:conversations,id',
        ]);

        $org = Organization::findOrFail($orgId);

        try {
            $callLog = $this->callService->initiateCall(
                $org,
                $user,
                $validated['to_number'],
                $validated['recipient_user_id'] ?? null,
                $validated['conversation_id'] ?? null,
            );

            return $this->success($callLog, [], 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), [], 422);
        }
    }

    /**
     * Hangup an active call.
     */
    public function hangup(Request $request, CallLog $callLog): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;

        if ($callLog->organization_id !== (int) $orgId) {
            return $this->error('Call not found.', [], 404);
        }

        $this->callService->hangupCall($callLog);

        return $this->success($callLog->fresh());
    }

    /**
     * Get active call status.
     */
    public function status(Request $request, CallLog $callLog): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;

        if ($callLog->organization_id !== (int) $orgId) {
            return $this->error('Call not found.', [], 404);
        }

        $callLog->load(['initiator:id,name', 'recipient:id,name', 'conversation:id,contact_name']);

        return $this->success($callLog);
    }

    /**
     * Get call history for the organization.
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;

        $query = CallLog::forOrganization($orgId)
            ->with(['initiator:id,name', 'recipient:id,name', 'conversation:id,contact_name'])
            ->orderByDesc('created_at');

        if ($request->filled('conversation_id')) {
            $query->where('conversation_id', $request->input('conversation_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $paginator = $query->paginate($request->input('per_page', 20));

        return $this->paginated($paginator, $paginator->items());
    }

    /**
     * Get the organization's call flow settings.
     */
    public function getCallFlow(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;
        $org = Organization::findOrFail($orgId);

        return $this->success($this->callService->getCallFlow($org));
    }

    /**
     * Update the organization's call flow settings.
     */
    public function updateCallFlow(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;
        $org = Organization::findOrFail($orgId);

        $validated = $request->validate([
            'enabled' => 'boolean',
            'greeting_message' => 'nullable|string|max:500',
            'no_answer_message' => 'nullable|string|max:500',
            'voicemail_enabled' => 'boolean',
            'ring_timeout_seconds' => 'integer|min:10|max:120',
            'record_calls' => 'boolean',
            'auto_transcribe' => 'boolean',
            'routing' => 'nullable|array',
            'routing.*.type' => 'required|in:user,phone',
            'routing.*.user_id' => 'nullable|exists:users,id',
            'routing.*.phone' => 'nullable|string|max:20',
            'routing.*.label' => 'nullable|string|max:100',
            'routing.*.priority' => 'integer|min:1',
        ]);

        $org->setSetting('call_flow', $validated);

        return $this->success($validated);
    }

    /**
     * Get an active call for the current user (for floating widget polling).
     */
    public function activeCall(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;

        $call = CallLog::forOrganization($orgId)
            ->active()
            ->where(function ($q) use ($user) {
                $q->where('initiated_by', $user->id);
            })
            ->with(['recipient:id,name', 'conversation:id,contact_name,contact_phone'])
            ->first();

        return $this->success($call);
    }
}
