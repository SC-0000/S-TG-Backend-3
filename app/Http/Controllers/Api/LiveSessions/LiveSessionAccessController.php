<?php

namespace App\Http\Controllers\Api\LiveSessions;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\LiveLessonSessionResource;
use App\Models\Access;
use App\Models\LiveLessonSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LiveSessionAccessController extends ApiController
{
    public function browse(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $children = $user->children ?? collect();
        if ($children->isEmpty()) {
            return $this->success(['sessions' => []]);
        }

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
            ]);
        } else {
            $child = $children->first();
        }

        $sessions = LiveLessonSession::with(['lesson.modules.course', 'teacher'])
            ->whereNotIn('status', ['ended', 'cancelled'])
            ->when($child?->organization_id, function ($query) use ($child) {
                $query->where('organization_id', $child->organization_id);
            })
            ->orderByRaw("CASE WHEN status = 'live' THEN 0 ELSE 1 END")
            ->orderBy('scheduled_start_time', 'asc')
            ->get()
            ->map(function ($session) {
                $course = $session->course ?: $session->lesson?->modules?->first()?->course;

                return [
                    'id' => $session->id,
                    'uid' => $session->uid,
                    'session_code' => $session->session_code,
                    'status' => $session->status,
                    'scheduled_start_time' => $session->scheduled_start_time?->toISOString(),
                    'actual_start_time' => $session->actual_start_time?->toISOString(),
                    'lesson' => [
                        'id' => $session->lesson?->id,
                        'title' => $session->lesson?->title,
                        'description' => $session->lesson?->description,
                        'course_name' => $course?->title ?? 'N/A',
                    ],
                    'teacher' => [
                        'id' => $session->teacher?->id,
                        'name' => $session->teacher?->name,
                    ],
                ];
            });

        return $this->success(['sessions' => $sessions]);
    }

    public function mySessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id');

        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $visibleChildIds = $user->children()->pluck('id')->all();
        if (empty($visibleChildIds)) {
            return $this->success(['sessions' => []]);
        }

        $accessRecords = Access::whereIn('child_id', $visibleChildIds)
            ->where('access', true)
            ->where('payment_status', 'paid')
            ->where(function ($query) {
                $query->whereNotNull('lesson_id')
                    ->orWhereNotNull('lesson_ids')
                    ->orWhereJsonContains('metadata->live_lesson_session_ids', DB::raw('json_array()'));
            })
            ->with(['service', 'course', 'child'])
            ->get();

        $sessionIds = collect();
        foreach ($accessRecords as $access) {
            if ($access->lesson_id) {
                $sessionIds->push($access->lesson_id);
            }
            if ($access->lesson_ids && is_array($access->lesson_ids)) {
                $sessionIds = $sessionIds->merge($access->lesson_ids);
            }
            if (isset($access->metadata['live_lesson_session_ids'])) {
                $sessionIds = $sessionIds->merge($access->metadata['live_lesson_session_ids']);
            }
        }

        $sessionIds = $sessionIds->unique()->filter();

        $sessionsQuery = LiveLessonSession::whereIn('id', $sessionIds)
            ->with(['lesson', 'teacher', 'course'])
            ->orderBy('scheduled_start_time', 'desc');

        if (!$user->isSuperAdmin() && $orgId) {
            $sessionsQuery->where('organization_id', $orgId);
        }

        $sessions = $sessionsQuery->get()->map(function ($session) use ($accessRecords) {
            $grantingAccess = $accessRecords->first(function ($access) use ($session) {
                if ($access->lesson_id == $session->id) return true;
                if (is_array($access->lesson_ids) && in_array($session->id, $access->lesson_ids)) return true;
                if (isset($access->metadata['live_lesson_session_ids']) && in_array($session->id, $access->metadata['live_lesson_session_ids'])) return true;
                return false;
            });

            $childIds = $accessRecords->filter(function ($access) use ($session) {
                if ($access->lesson_id == $session->id) return true;
                if (is_array($access->lesson_ids) && in_array($session->id, $access->lesson_ids)) return true;
                if (isset($access->metadata['live_lesson_session_ids']) && in_array($session->id, $access->metadata['live_lesson_session_ids'])) return true;
                return false;
            })
                ->pluck('child_id')
                ->map(fn ($id) => (string) $id)
                ->unique()
                ->values();

            return [
                'session' => (new LiveLessonSessionResource($session))->resolve(),
                'child_ids' => $childIds,
                'purchase_source' => $grantingAccess ? [
                    'type' => $grantingAccess->course_id ? 'course' : 'service',
                    'name' => $grantingAccess->course_id
                        ? ($grantingAccess->course?->title ?? 'Unknown Course')
                        : ($grantingAccess->service?->name ?? 'Unknown Service'),
                    'id' => $grantingAccess->course_id ?: $grantingAccess->service_id,
                ] : null,
            ];
        })
        ->filter(function ($session) {
            return !empty($session['child_ids']) && $session['child_ids']->isNotEmpty();
        })
        ->values();

        return $this->success(['sessions' => $sessions]);
    }

    public function mySessionShow(Request $request, LiveLessonSession $session): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id');

        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        if (!$user->isSuperAdmin() && $orgId && (int) $session->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $visibleChildIds = $user->children()->pluck('id')->all();
        if (empty($visibleChildIds)) {
            return $this->error('No child profile found.', [], 400);
        }

        $accessRecords = Access::whereIn('child_id', $visibleChildIds)
            ->where('access', true)
            ->where('payment_status', 'paid')
            ->where(function ($query) {
                $query->whereNotNull('lesson_id')
                    ->orWhereNotNull('lesson_ids')
                    ->orWhereJsonContains('metadata->live_lesson_session_ids', DB::raw('json_array()'));
            })
            ->with(['service', 'course'])
            ->get();

        $hasAccess = $accessRecords->contains(function ($access) use ($session) {
            if ($access->lesson_id == $session->id) return true;
            if (is_array($access->lesson_ids) && in_array($session->id, $access->lesson_ids)) return true;
            if (isset($access->metadata['live_lesson_session_ids']) && in_array($session->id, $access->metadata['live_lesson_session_ids'])) return true;
            return false;
        });

        if (!$hasAccess && !$user->isAdmin() && !$user->isSuperAdmin()) {
            return $this->error('You do not have access to this live session.', [], 403);
        }

        $access = $accessRecords->first(function ($access) use ($session) {
            if ($access->lesson_id == $session->id) return true;
            if (is_array($access->lesson_ids) && in_array($session->id, $access->lesson_ids)) return true;
            if (isset($access->metadata['live_lesson_session_ids']) && in_array($session->id, $access->metadata['live_lesson_session_ids'])) return true;
            return false;
        });

        $session->load(['lesson', 'teacher', 'course', 'participants']);
        $data = (new LiveLessonSessionResource($session))->resolve();

        return $this->success([
            'session' => $data,
            'purchase_source' => $access ? [
                'type' => $access->course_id ? 'course' : 'service',
                'name' => $access->course_id
                    ? ($access->course?->title ?? 'Unknown Course')
                    : ($access->service?->name ?? 'Unknown Service'),
                'id' => $access->course_id ?: $access->service_id,
            ] : null,
        ]);
    }
}
