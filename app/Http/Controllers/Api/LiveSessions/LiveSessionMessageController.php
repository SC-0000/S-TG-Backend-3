<?php

namespace App\Http\Controllers\Api\LiveSessions;

use App\Events\MessageSent;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\LiveSessionMessageResource;
use App\Models\Access;
use App\Models\LiveLessonSession;
use App\Models\LiveSessionMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiveSessionMessageController extends ApiController
{
    public function index(Request $request, LiveLessonSession $session): JsonResponse
    {
        if ($response = $this->ensureSessionScope($request, $session)) {
            return $response;
        }

        $messages = LiveSessionMessage::where('live_session_id', $session->id)
            ->with(['child', 'answeredBy'])
            ->orderBy('created_at')
            ->get();

        $data = LiveSessionMessageResource::collection($messages)->resolve();

        return $this->success(['messages' => $data]);
    }

    public function store(Request $request, LiveLessonSession $session): JsonResponse
    {
        if ($response = $this->ensureSessionScope($request, $session)) {
            return $response;
        }

        $validated = $request->validate([
            'message' => 'required|string|max:1000',
            'type' => 'nullable|in:question,comment',
            'child_id' => 'nullable|integer',
        ]);

        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        if (!$user->isSuperAdmin() && !$user->isAdmin() && !$user->isTeacher()) {
            $children = $user->children ?? collect();
            if ($children->isEmpty()) {
                return $this->error('No child profile found.', [], 400);
            }

            if (!empty($validated['child_id'])) {
                $child = $children->firstWhere('id', (int) $validated['child_id']);
                if (!$child) {
                    return $this->error('Invalid child selection.', [], 422);
                }
            } elseif ($children->count() > 1) {
                return $this->error('child_id is required when multiple children exist.', [], 422);
            } else {
                $child = $children->first();
            }

            if (!$this->hasAccessToSession($child->id, $session->id)) {
                return $this->error('You do not have access to this live session.', [], 403);
            }
        } else {
            $child = $user->children()->first();
        }

        if (!$child) {
            return $this->error('No child profile found.', [], 400);
        }

        $message = LiveSessionMessage::create([
            'live_session_id' => $session->id,
            'child_id' => $child->id,
            'message' => $validated['message'],
            'type' => $validated['type'] ?? 'question',
            'is_answered' => false,
        ]);

        broadcast(new MessageSent(
            $session->id,
            $message->id,
            $child->id,
            $child->child_name,
            $message->message,
            $message->type,
            false,
            null
        ))->toOthers();

        $message->load(['child', 'answeredBy']);
        $data = (new LiveSessionMessageResource($message))->resolve();

        return $this->success(['message' => $data], status: 201);
    }

    public function answer(Request $request, LiveLessonSession $session, LiveSessionMessage $message): JsonResponse
    {
        if ($response = $this->ensureTeacherAccess($request, $session)) {
            return $response;
        }

        if ($message->live_session_id !== $session->id) {
            return $this->error('Message not found.', [], 404);
        }

        $validated = $request->validate([
            'answer' => 'required|string|max:1000',
        ]);

        $message->update([
            'is_answered' => true,
            'answer' => $validated['answer'],
            'answered_by' => $request->user()?->id,
            'answered_at' => now(),
        ]);

        broadcast(new MessageSent(
            $session->id,
            $message->id,
            $message->child_id,
            $message->child?->child_name ?? 'student',
            $message->message,
            $message->type,
            true,
            $validated['answer']
        ))->toOthers();

        $message->load(['child', 'answeredBy']);
        $data = (new LiveSessionMessageResource($message))->resolve();

        return $this->success(['message' => $data]);
    }

    private function ensureSessionScope(Request $request, LiveLessonSession $session): ?JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id');
        if (!$user->isSuperAdmin() && (int) $session->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        return null;
    }

    private function ensureTeacherAccess(Request $request, LiveLessonSession $session): ?JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id');
        if (!$user->isSuperAdmin() && (int) $session->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        if (!$user->isSuperAdmin() && !$user->isAdmin() && (int) $session->teacher_id !== (int) $user->id) {
            return $this->error('Unauthorized access.', [], 403);
        }

        return null;
    }

    private function hasAccessToSession(int $childId, int $sessionId): bool
    {
        return Access::where('child_id', $childId)
            ->where('access', true)
            ->where('payment_status', 'paid')
            ->where(function ($query) use ($sessionId) {
                $query->where('lesson_id', $sessionId)
                    ->orWhereJsonContains('lesson_ids', $sessionId)
                    ->orWhereJsonContains('metadata->live_lesson_session_ids', $sessionId);
            })
            ->exists();
    }
}
