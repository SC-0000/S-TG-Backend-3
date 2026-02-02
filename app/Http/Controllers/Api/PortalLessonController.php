<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\Attendance\AttendanceStoreRequest;
use App\Models\Access;
use App\Models\Assessment;
use App\Models\Attendance;
use App\Models\Child;
use App\Models\Lesson;
use App\Models\LiveLessonSession;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalLessonController extends ApiController
{
    public function show(Request $request, Lesson $lesson): JsonResponse
    {
        if ($response = $this->ensureLessonScope($request, $lesson)) {
            return $response;
        }

        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $lesson->load([
            'attendances' => fn ($q) => $lesson->start_time
                ? $q->whereDate('date', $lesson->start_time)
                : $q,
        ]);

        $linkedSession = null;
        if ($lesson->live_lesson_session_id) {
            $linkedSession = LiveLessonSession::with('lesson:id,title')
                ->find($lesson->live_lesson_session_id);
        }

        $accessRecords = Access::where('access', true)
            ->where('payment_status', 'paid')
            ->where(function ($q) use ($lesson) {
                $q->where('lesson_id', $lesson->id)
                    ->orWhereJsonContains('lesson_ids', $lesson->id)
                    ->orWhereRaw('JSON_CONTAINS(lesson_ids, ?)', [json_encode((string) $lesson->id)]);
            })
            ->get();

        $childIds = collect();
        foreach ($accessRecords as $record) {
            $lessonIds = is_array($record->lesson_ids) ? $record->lesson_ids : (json_decode($record->lesson_ids, true) ?: []);
            if ((int) $record->lesson_id === (int) $lesson->id || in_array((int) $lesson->id, array_map('intval', $lessonIds), true)) {
                $childIds->push($record->child_id);
            }
        }
        $childIds = $childIds->unique()->values();

        if (! in_array($user->role, ['admin', 'super_admin'], true)) {
            $allowedIds = $user->children->pluck('id');
            $childIds = $childIds->intersect($allowedIds)->values();
        }

        $children = Child::whereIn('id', $childIds->all())
            ->get(['id', 'child_name']);

        $attendanceRows = Attendance::where('lesson_id', $lesson->id)
            ->get(['id', 'child_id', 'status', 'notes', 'approved', 'date']);

        $childrenPayload = $children->map(fn ($child) => [
            'id' => $child->id,
            'name' => $child->child_name,
            'attendance' => optional(
                $attendanceRows->firstWhere('child_id', $child->id)
            )->only(['id', 'status', 'notes', 'approved', 'date']),
        ])->values();

        $assessmentIdsFromAccess = collect();
        foreach ($accessRecords as $record) {
            if (! empty($record->assessment_id)) {
                $assessmentIdsFromAccess->push($record->assessment_id);
            }

            $parsed = [];
            try {
                $parsed = $record->assessment_ids ?: [];
            } catch (\Throwable $e) {
                $parsed = [];
            }

            foreach ($parsed as $assessmentId) {
                $assessmentIdsFromAccess->push($assessmentId);
            }
        }
        $assessmentIdsFromAccess = $assessmentIdsFromAccess->unique()->values();

        if ($assessmentIdsFromAccess->isNotEmpty()) {
            $assessments = Assessment::where('lesson_id', $lesson->id)
                ->whereIn('id', $assessmentIdsFromAccess->all())
                ->get();
        } else {
            $assessments = Assessment::where('lesson_id', $lesson->id)->get();
        }

        return $this->success([
            'lesson' => [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'description' => $lesson->description,
                'lesson_type' => $lesson->lesson_type,
                'lesson_mode' => $lesson->lesson_mode,
                'start_time' => $lesson->start_time?->toISOString(),
                'end_time' => $lesson->end_time?->toISOString(),
                'address' => $lesson->address,
                'meeting_link' => $lesson->meeting_link,
                'live_lesson_session_id' => $lesson->live_lesson_session_id,
            ],
            'assessments' => $assessments->map(fn ($assessment) => [
                'id' => $assessment->id,
                'title' => $assessment->title,
                'description' => $assessment->description,
                'deadline' => $assessment->deadline,
            ]),
            'children' => $childrenPayload,
            'attendances' => $attendanceRows->map(fn ($row) => [
                'id' => $row->id,
                'child_id' => $row->child_id,
                'status' => $row->status,
                'notes' => $row->notes,
                'approved' => (bool) $row->approved,
                'date' => $row->date,
            ]),
            'linked_session' => $linkedSession ? [
                'id' => $linkedSession->id,
                'lesson_id' => $linkedSession->lesson_id,
                'session_code' => $linkedSession->session_code,
                'status' => $linkedSession->status,
                'scheduled_start_time' => $linkedSession->scheduled_start_time?->toISOString(),
                'lesson' => $linkedSession->lesson ? [
                    'id' => $linkedSession->lesson->id,
                    'title' => $linkedSession->lesson->title,
                ] : null,
            ] : null,
        ]);
    }

    public function storeAttendance(AttendanceStoreRequest $request, Lesson $lesson): JsonResponse
    {
        if ($response = $this->ensureLessonScope($request, $lesson)) {
            return $response;
        }

        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $data = $request->validated();
        $childId = (int) $data['child_id'];

        if (! in_array($user->role, ['admin', 'super_admin'], true)) {
            $allowedChildIds = $user->children()->pluck('id')->all();
            if (! in_array($childId, $allowedChildIds, true)) {
                return $this->error('Child not available for this user.', [], 403);
            }

            $hasAccess = Access::where('access', true)
                ->where('payment_status', 'paid')
                ->where('child_id', $childId)
                ->where(function ($q) use ($lesson) {
                    $q->where('lesson_id', $lesson->id)
                        ->orWhereJsonContains('lesson_ids', $lesson->id)
                        ->orWhereRaw('JSON_CONTAINS(lesson_ids, ?)', [json_encode((string) $lesson->id)]);
                })
                ->exists();

            if (! $hasAccess) {
                return $this->error('Child does not have access to this lesson.', [], 403);
            }
        }

        $date = ! empty($data['date'])
            ? Carbon::parse($data['date'])->toDateString()
            : ($lesson->start_time?->toDateString() ?: now()->toDateString());

        $existing = Attendance::where('lesson_id', $lesson->id)
            ->where('child_id', $childId)
            ->whereDate('date', $date)
            ->first();

        if (! in_array($user->role, ['admin', 'super_admin'], true) && $existing && $existing->approved) {
            return $this->error('Attendance has already been approved and cannot be changed.', [], 403);
        }

        $attendance = Attendance::updateOrCreate(
            [
                'lesson_id' => $lesson->id,
                'child_id' => $childId,
            ],
            [
                'status' => $data['status'],
                'notes' => $data['notes'] ?? null,
                'approved' => $existing ? (bool) $existing->approved : false,
                'date' => $date,
            ]
        );

        $duplicates = Attendance::where('lesson_id', $lesson->id)
            ->where('child_id', $childId)
            ->whereDate('date', $date)
            ->get(['id']);

        return $this->success([
            'attendance' => [
                'id' => $attendance->id,
                'child_id' => $attendance->child_id,
                'status' => $attendance->status,
                'notes' => $attendance->notes,
                'approved' => (bool) $attendance->approved,
                'date' => $attendance->date,
            ],
            'warning' => $duplicates->count() > 1
                ? 'Attendance saved, but duplicate attendance rows detected.'
                : null,
            'duplicate_ids' => $duplicates->count() > 1 ? $duplicates->pluck('id')->all() : [],
        ]);
    }

    private function ensureLessonScope(Request $request, Lesson $lesson): ?JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');

        if ($orgId && ! $lesson->is_global && (int) $lesson->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        return null;
    }
}
