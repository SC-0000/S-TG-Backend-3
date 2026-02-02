<?php

namespace App\Http\Controllers\Api\LiveSessions;

use App\Events\EmojiReaction;
use App\Events\HandRaised;
use App\Events\ParticipantJoined;
use App\Events\ParticipantCameraDisabled;
use App\Events\ParticipantKicked;
use App\Events\ParticipantMuted;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\LiveSessionParticipantResource;
use App\Models\Access;
use App\Models\LiveLessonSession;
use App\Models\LiveSessionParticipant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiveSessionParticipantController extends ApiController
{
    public function join(Request $request, LiveLessonSession $session): JsonResponse
    {
        if ($response = $this->ensureSessionScope($request, $session)) {
            return $response;
        }

        if ($session->status !== 'live') {
            return $this->error('This live session is not currently active.', [
                ['message' => 'Session status is ' . $session->status],
            ], 422);
        }

        $user = $request->user();
        $children = $user?->children ?? collect();

        if ($children->isEmpty()) {
            return $this->error('No child profile found.', [], 400);
        }

        $child = null;
        if ($request->filled('child_id')) {
            $child = $children->firstWhere('id', $request->integer('child_id'));
            if (!$child) {
                return $this->error('Invalid child selection.', [], 422);
            }
        } elseif ($children->count() > 1) {
            return $this->success([
                'needs_child_selection' => true,
                'children' => $children->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->child_name,
                    'age' => $c->age,
                ])->values(),
            ], status: 200);
        } else {
            $child = $children->first();
        }

        if (!$this->hasAccessToSession($child->id, $session->id)) {
            return $this->error('You do not have access to this live session.', [], 403);
        }

        $participant = LiveSessionParticipant::firstOrCreate([
            'live_lesson_session_id' => $session->id,
            'child_id' => $child->id,
        ], [
            'joined_at' => now(),
            'status' => 'joined',
            'connection_status' => 'connected',
        ]);

        if (!$participant->wasRecentlyCreated) {
            $participant->update([
                'status' => 'joined',
                'connection_status' => 'connected',
                'joined_at' => now(),
            ]);
        }

        $participantData = [
            'id' => $participant->id,
            'child_id' => $child->id,
            'child_name' => $child->child_name,
            'joined_at' => $participant->joined_at?->toISOString(),
            'status' => $participant->status,
            'connection_status' => $participant->connection_status,
            'hand_raised' => (bool) $participant->hand_raised,
            'hand_raised_at' => $participant->hand_raised_at?->toISOString(),
        ];

        broadcast(new ParticipantJoined($session->id, $participantData))->toOthers();

        $participant->load('child');
        $resource = (new LiveSessionParticipantResource($participant))->resolve();

        return $this->success([
            'participant' => $resource,
        ]);
    }

    public function leave(Request $request, LiveLessonSession $session): JsonResponse
    {
        if ($response = $this->ensureSessionScope($request, $session)) {
            return $response;
        }

        $user = $request->user();
        $children = $user?->children ?? collect();

        if ($children->isEmpty()) {
            return $this->error('No child profile found.', [], 400);
        }

        if ($request->filled('child_id')) {
            $child = $children->firstWhere('id', $request->integer('child_id'));
            if (!$child) {
                return $this->error('Invalid child selection.', [], 422);
            }
        } elseif ($children->count() > 1) {
            return $this->error('child_id is required when multiple children exist.', [], 422);
        } else {
            $child = $children->first();
        }

        if (!$child) {
            return $this->error('No child profile found.', [], 400);
        }

        $participant = LiveSessionParticipant::where('live_lesson_session_id', $session->id)
            ->where('child_id', $child->id)
            ->first();

        if ($participant) {
            $participant->update([
                'status' => 'left',
                'connection_status' => 'disconnected',
                'left_at' => now(),
            ]);
        }

        return $this->success(['message' => 'Left session successfully.']);
    }

    public function raiseHand(Request $request, LiveLessonSession $session): JsonResponse
    {
        if ($response = $this->ensureSessionScope($request, $session)) {
            return $response;
        }

        $validated = $request->validate([
            'raised' => 'required|boolean',
            'child_id' => 'nullable|integer',
        ]);

        $user = $request->user();
        $children = $user?->children ?? collect();

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

        $participant = LiveSessionParticipant::where('live_lesson_session_id', $session->id)
            ->where('child_id', $child->id)
            ->first();

        if (!$participant) {
            return $this->error('Participant not found.', [], 404);
        }

        $participant->update([
            'hand_raised' => $validated['raised'],
            'hand_raised_at' => $validated['raised'] ? now() : null,
        ]);

        broadcast(new HandRaised(
            $session,
            $child->id,
            $child->child_name,
            $validated['raised']
        ))->toOthers();

        return $this->success([
            'hand_raised' => $validated['raised'],
        ]);
    }

    public function sendReaction(Request $request, LiveLessonSession $session): JsonResponse
    {
        if ($response = $this->ensureSessionScope($request, $session)) {
            return $response;
        }

        $validated = $request->validate([
            'emoji' => 'required|string|max:10',
        ]);

        broadcast(new EmojiReaction(
            $session->id,
            $request->user()?->id,
            $request->user()?->name ?? 'student',
            $validated['emoji']
        ))->toOthers();

        return $this->success(['message' => 'Reaction sent.']);
    }

    public function muteParticipant(Request $request, LiveLessonSession $session, LiveSessionParticipant $participant): JsonResponse
    {
        if ($response = $this->ensureTeacherAccess($request, $session)) {
            return $response;
        }

        $validated = $request->validate([
            'muted' => 'required|boolean',
        ]);

        broadcast(new ParticipantMuted(
            $session->id,
            $participant->id,
            $participant->child_id,
            $validated['muted'],
            'teacher'
        ))->toOthers();

        return $this->success(['muted' => $validated['muted']]);
    }

    public function lowerHand(Request $request, LiveLessonSession $session, LiveSessionParticipant $participant): JsonResponse
    {
        if ($response = $this->ensureTeacherAccess($request, $session)) {
            return $response;
        }

        if ($participant->live_lesson_session_id !== $session->id) {
            return $this->error('Participant not found.', [], 404);
        }

        $participant->update([
            'hand_raised' => false,
            'hand_raised_at' => null,
        ]);

        $participant->load('child');

        broadcast(new HandRaised(
            $session,
            $participant->child_id,
            $participant->child?->child_name ?? 'student',
            false
        ))->toOthers();

        return $this->success(['hand_raised' => false]);
    }

    public function disableCamera(Request $request, LiveLessonSession $session, LiveSessionParticipant $participant): JsonResponse
    {
        if ($response = $this->ensureTeacherAccess($request, $session)) {
            return $response;
        }

        $validated = $request->validate([
            'disabled' => 'required|boolean',
        ]);

        broadcast(new ParticipantCameraDisabled(
            $session->id,
            $participant->id,
            $participant->child_id,
            $validated['disabled'],
            'teacher'
        ))->toOthers();

        return $this->success(['disabled' => $validated['disabled']]);
    }

    public function muteAll(Request $request, LiveLessonSession $session): JsonResponse
    {
        if ($response = $this->ensureTeacherAccess($request, $session)) {
            return $response;
        }

        $validated = $request->validate([
            'muted' => 'required|boolean',
        ]);

        $session->load('participants');
        foreach ($session->participants as $participant) {
            broadcast(new ParticipantMuted(
                $session->id,
                $participant->id,
                $participant->child_id,
                $validated['muted'],
                'teacher'
            ))->toOthers();
        }

        return $this->success([
            'muted' => $validated['muted'],
            'count' => $session->participants->count(),
        ]);
    }

    public function kick(Request $request, LiveLessonSession $session, LiveSessionParticipant $participant): JsonResponse
    {
        if ($response = $this->ensureTeacherAccess($request, $session)) {
            return $response;
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $participant->update([
            'status' => 'kicked',
            'connection_status' => 'disconnected',
            'left_at' => now(),
        ]);

        broadcast(new ParticipantKicked(
            $session->id,
            $participant->id,
            $participant->child_id,
            $validated['reason'] ?? 'Removed by teacher'
        ))->toOthers();

        return $this->success(['message' => 'Participant removed from session.']);
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

        if (!in_array($user->role, [User::ROLE_PARENT, User::ROLE_GUEST_PARENT, User::ROLE_TEACHER, User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN], true)) {
            return $this->error('Unauthorized access.', [], 403);
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
