<?php

namespace App\Http\Controllers\Api\LiveSessions;

use App\Events\AnnotationClear;
use App\Events\AnnotationStroke;
use App\Events\BlockHighlighted;
use App\Events\SessionStateChanged;
use App\Events\SlideChanged;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\LiveSessions\LiveSessionStoreRequest;
use App\Http\Requests\Api\LiveSessions\LiveSessionUpdateRequest;
use App\Http\Resources\LiveLessonSessionResource;
use App\Http\Resources\LiveSessionParticipantResource;
use App\Models\Access;
use App\Models\ContentLesson;
use App\Models\LiveLessonSession;
use App\Services\LiveKitTokenService;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LiveSessionController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id');

        $query = LiveLessonSession::with(['lesson', 'teacher', 'organization'])
            ->orderBy('scheduled_start_time', 'desc');

        if ($user?->isSuperAdmin()) {
            if ($request->filled('organization_id')) {
                $query->where('organization_id', $request->integer('organization_id'));
            }
        } else {
            $query->where('organization_id', $orgId);
        }

        if ($user?->isTeacher()) {
            $query->where('teacher_id', $user->id);
        }

        ApiQuery::applyFilters($query, $request, [
            'status' => true,
            'teacher_id' => true,
            'lesson_id' => true,
        ]);

        $sessions = $query->paginate(ApiPagination::perPage($request, 20));
        $data = LiveLessonSessionResource::collection($sessions->items())->resolve();

        return $this->paginated($sessions, $data);
    }

    public function show(Request $request, LiveLessonSession $session): JsonResponse
    {
        if ($response = $this->ensureSessionScope($request, $session)) {
            return $response;
        }

        $session->load(['lesson', 'teacher', 'organization']);
        $data = (new LiveLessonSessionResource($session))->resolve();

        return $this->success($data);
    }

    public function teach(Request $request, LiveLessonSession $session): JsonResponse
    {
        if ($response = $this->ensureSessionScope($request, $session, requireTeacher: true)) {
            return $response;
        }

        $session->load([
            'lesson.slides',
            'teacher',
            'participants.child.user',
        ]);

        $participants = $session->participants
            ->where('status', 'joined')
            ->whereIn('connection_status', ['connected', 'reconnecting'])
            ->values();

        return $this->success([
            'session' => (new LiveLessonSessionResource($session))->resolve(),
            'participants' => LiveSessionParticipantResource::collection($participants)->resolve(),
        ]);
    }

    public function store(LiveSessionStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        $teacherId = $validated['teacher_id'] ?? $user?->id;
        if ($user?->isAdmin() && empty($validated['teacher_id'])) {
            return $this->error('teacher_id is required for admin users.', [], 422);
        }

        $organizationId = $validated['organization_id'] ?? $request->attributes->get('organization_id');
        if ($user?->isSuperAdmin() && empty($organizationId)) {
            return $this->error('organization_id is required.', [], 422);
        }

        $lesson = ContentLesson::with('slides')->findOrFail($validated['lesson_id']);
        $firstSlide = $lesson->slides->sortBy('order')->first();

        $session = LiveLessonSession::create([
            'lesson_id' => $validated['lesson_id'],
            'course_id' => $validated['course_id'] ?? null,
            'year_group' => $validated['year_group'] ?? null,
            'teacher_id' => $teacherId,
            'organization_id' => $organizationId,
            'scheduled_start_time' => $validated['scheduled_start_time'],
            'current_slide_id' => $firstSlide?->id,
            'status' => !empty($validated['start_now']) ? 'live' : 'scheduled',
            'actual_start_time' => !empty($validated['start_now']) ? now() : null,
            'audio_enabled' => $validated['audio_enabled'] ?? true,
            'video_enabled' => $validated['video_enabled'] ?? false,
            'allow_student_questions' => $validated['allow_student_questions'] ?? true,
            'whiteboard_enabled' => $validated['whiteboard_enabled'] ?? true,
            'record_session' => $validated['record_session'] ?? false,
            'pacing_mode' => 'teacher_controlled',
            'navigation_locked' => false,
        ]);

        $this->createNotificationTask($user?->role, $session, $lesson);

        $session->load(['lesson', 'teacher', 'organization']);
        $data = (new LiveLessonSessionResource($session))->resolve();

        return $this->success(['session' => $data], status: 201);
    }

    public function update(LiveSessionUpdateRequest $request, LiveLessonSession $session): JsonResponse
    {
        if ($response = $this->ensureSessionScope($request, $session, requireTeacher: true)) {
            return $response;
        }

        if (in_array($session->status, ['live', 'completed', 'ended'], true)) {
            return $this->error('Cannot edit live or completed sessions.', [], 422);
        }

        $validated = $request->validated();
        $user = $request->user();

        if ($user?->isAdmin() && empty($validated['teacher_id'])) {
            return $this->error('teacher_id is required for admin users.', [], 422);
        }

        if (!$user?->isSuperAdmin()) {
            $validated['organization_id'] = $session->organization_id;
            if (!$user?->isAdmin()) {
                $validated['teacher_id'] = $session->teacher_id;
            }
        }

        $session->update($validated);

        $session->load(['lesson', 'teacher', 'organization']);
        $data = (new LiveLessonSessionResource($session))->resolve();

        return $this->success(['session' => $data]);
    }

    public function destroy(Request $request, LiveLessonSession $session): JsonResponse
    {
        if ($response = $this->ensureSessionScope($request, $session, requireTeacher: true)) {
            return $response;
        }

        if ($session->status === 'live') {
            return $this->error('Cannot delete live sessions. End the session first.', [], 422);
        }

        $session->delete();

        return $this->success(['message' => 'Session deleted successfully.']);
    }

    public function start(Request $request, LiveLessonSession $session): JsonResponse
    {
        if ($response = $this->ensureSessionScope($request, $session, requireTeacher: true)) {
            return $response;
        }

        if ($session->status !== 'scheduled') {
            return $this->error('Can only start scheduled sessions.', [], 422);
        }

        $session->startSession();

        return $this->success([
            'message' => 'Session started successfully.',
            'status' => $session->status,
            'actual_start_time' => $session->actual_start_time?->toISOString(),
        ]);
    }

    public function changeSlide(Request $request, LiveLessonSession $session): JsonResponse
    {
        if ($response = $this->ensureSessionScope($request, $session, requireTeacher: true)) {
            return $response;
        }

        $validated = $request->validate([
            'slide_id' => 'required|integer|exists:lesson_slides,id',
        ]);

        $session->update([
            'current_slide_id' => $validated['slide_id'],
        ]);

        broadcast(new SlideChanged($session, $validated['slide_id'], $request->user()?->id))->toOthers();

        return $this->success([
            'current_slide_id' => $validated['slide_id'],
        ]);
    }

    public function changeState(Request $request, LiveLessonSession $session): JsonResponse
    {
        if ($response = $this->ensureSessionScope($request, $session, requireTeacher: true)) {
            return $response;
        }

        $validated = $request->validate([
            'state' => 'required|in:live,paused,ended,cancelled',
            'message' => 'nullable|string|max:255',
        ]);

        $session->update([
            'status' => $validated['state'],
        ]);

        broadcast(new SessionStateChanged(
            $session,
            $validated['state'],
            $validated['message'] ?? null
        ))->toOthers();

        return $this->success(['state' => $validated['state']]);
    }

    public function highlightBlock(Request $request, LiveLessonSession $session): JsonResponse
    {
        if ($response = $this->ensureSessionScope($request, $session, requireTeacher: true)) {
            return $response;
        }

        $validated = $request->validate([
            'slide_id' => 'required|integer',
            'block_id' => 'nullable|string',
            'highlighted' => 'required|boolean',
        ]);

        broadcast(new BlockHighlighted(
            $session,
            $validated['slide_id'],
            $validated['block_id'],
            $validated['highlighted']
        ))->toOthers();

        return $this->success(['message' => 'Block highlight broadcasted.']);
    }

    public function sendAnnotation(Request $request, LiveLessonSession $session): JsonResponse
    {
        if ($response = $this->ensureSessionScope($request, $session)) {
            return $response;
        }

        $validated = $request->validate([
            'slide_id' => 'required|integer',
            'stroke_data' => 'required|array',
            'user_role' => 'required|in:teacher,student',
        ]);

        broadcast(new AnnotationStroke(
            $session,
            $validated['slide_id'],
            $validated['stroke_data'],
            $request->user()?->id,
            $validated['user_role']
        ))->toOthers();

        return $this->success(['message' => 'Annotation broadcasted.']);
    }

    public function clearAnnotations(Request $request, LiveLessonSession $session): JsonResponse
    {
        if ($response = $this->ensureSessionScope($request, $session)) {
            return $response;
        }

        $validated = $request->validate([
            'slide_id' => 'required|integer',
        ]);

        broadcast(new AnnotationClear($session, $validated['slide_id'], $request->user()?->id))->toOthers();

        return $this->success(['message' => 'Annotations cleared.']);
    }

    public function toggleNavigationLock(Request $request, LiveLessonSession $session): JsonResponse
    {
        if ($response = $this->ensureSessionScope($request, $session, requireTeacher: true)) {
            return $response;
        }

        $validated = $request->validate([
            'locked' => 'required|boolean',
        ]);

        $session->update([
            'navigation_locked' => $validated['locked'],
        ]);
        $session->refresh();

        broadcast(new SessionStateChanged(
            $session,
            $session->status,
            $validated['locked'] ? 'Navigation locked by teacher' : 'Navigation unlocked'
        ))->toOthers();

        return $this->success([
            'navigation_locked' => $session->navigation_locked,
        ]);
    }

    public function participants(Request $request, LiveLessonSession $session): JsonResponse
    {
        if ($response = $this->ensureSessionScope($request, $session, requireTeacher: true)) {
            return $response;
        }

        $session->load(['participants.child']);
        $participants = LiveSessionParticipantResource::collection($session->participants)->resolve();

        return $this->success(['participants' => $participants]);
    }

    public function liveKitToken(Request $request, LiveLessonSession $session): JsonResponse
    {
        if ($response = $this->ensureSessionScope($request, $session)) {
            return $response;
        }

        $tokenService = app(LiveKitTokenService::class);

        $permissions = [
            'can_publish' => true,
            'can_subscribe' => true,
            'can_publish_data' => true,
        ];

        $metadata = [
            'role' => ($session->teacher_id === $request->user()?->id) ? 'teacher' : 'student',
            'name' => $request->user()?->name,
        ];

        $user = $request->user();
        if ($user && ($user->isParent() || $user->role === \App\Models\User::ROLE_GUEST_PARENT)) {
            $children = $user->children ?? collect();
            if ($children->isEmpty()) {
                return $this->error('No child profile found.', [], 400);
            }

            if ($request->filled('child_id')) {
                $childId = $request->integer('child_id');
                $child = $children->firstWhere('id', $childId);
                if (!$child) {
                    return $this->error('Invalid child selection.', [], 422);
                }
            } elseif ($children->count() > 1) {
                return $this->error('child_id is required when multiple children exist.', [], 422);
            } else {
                $child = $children->first();
                $childId = $child->id;
            }

            if (!$this->hasAccessToSession($childId, $session->id)) {
                return $this->error('You do not have access to this live session.', [], 403);
            }

            $metadata['child_id'] = $childId;
        } elseif ($request->filled('child_id')) {
            $metadata['child_id'] = $request->integer('child_id');
        }

        try {
            $tokenData = $tokenService->generateSessionToken($session, $request->user(), $permissions, $metadata);
        } catch (\Throwable $e) {
            Log::error('[LiveSessionApi] Failed to generate LiveKit token', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
            return $this->error('Failed to generate LiveKit token.', [], 500);
        }

        return $this->success($tokenData);
    }

    private function ensureSessionScope(Request $request, LiveLessonSession $session, bool $requireTeacher = false): ?JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id');
        if (!$user->isSuperAdmin() && (int) $session->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        if ($requireTeacher && !$user->isSuperAdmin() && !$user->isAdmin()) {
            if ((int) $session->teacher_id !== (int) $user->id) {
                return $this->error('Unauthorized access.', [], 403);
            }
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

    private function createNotificationTask(?string $creatorRole, LiveLessonSession $session, ContentLesson $lesson): void
    {
        try {
            if ($creatorRole === 'admin') {
                \App\Models\AdminTask::create([
                    'task_type' => 'Live Session Scheduled',
                    'assigned_to' => $session->teacher_id,
                    'status' => 'Pending',
                    'related_entity' => route('teacher.live-sessions.index'),
                    'priority' => 'Medium',
                    'description' => "A new live session '{$lesson->title}' has been scheduled for " .
                        $session->scheduled_start_time->format('M d, Y \a\t g:i A') . ". Please review and prepare for the session.",
                ]);
            } elseif ($creatorRole === 'teacher') {
                \App\Models\AdminTask::create([
                    'task_type' => 'Live Session Created by Teacher',
                    'assigned_to' => null,
                    'status' => 'Pending',
                    'related_entity' => route('admin.live-sessions.index'),
                    'priority' => 'Low',
                    'description' => "Teacher {$session->teacher?->name} has created a new live session '{$lesson->title}' scheduled for " .
                        $session->scheduled_start_time->format('M d, Y \a\t g:i A') . ".",
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[LiveSessionApi] Failed to create notification task', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
