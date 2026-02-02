<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Attendance\AttendanceApproveRequest;
use App\Http\Requests\Api\Attendance\AttendanceMarkAllRequest;
use App\Models\Attendance;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends ApiController
{
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $lessons = Lesson::with([
                'attendances:id,lesson_id,status,date',
            ])
            ->when(!$user->isSuperAdmin(), function ($query) use ($request) {
                $orgId = $request->attributes->get('organization_id');
                if ($orgId) {
                    $query->where('organization_id', $orgId);
                }
            })
            ->latest('start_time')
            ->get(['id', 'title', 'start_time'])
            ->map(function (Lesson $lesson) {
                $lessonDate = $lesson->start_time?->toDateString();

                $accessRows = \App\Models\Access::where('access', true)
                    ->where('payment_status', 'paid')
                    ->where(function ($q) use ($lesson) {
                        $q->where('lesson_id', $lesson->id)
                          ->orWhereJsonContains('lesson_ids', $lesson->id)
                          ->orWhereRaw('JSON_CONTAINS(lesson_ids, ?)', [json_encode((string) $lesson->id)]);
                    })
                    ->get();

                $childIds = $accessRows->pluck('child_id')->unique()->values();
                $childrenCount = $childIds->count();

                $rowsToday = $lessonDate
                    ? $lesson->attendances->where('date', $lessonDate)
                    : collect();

                return [
                    'id' => $lesson->id,
                    'title' => $lesson->title,
                    'start_time' => $lesson->start_time,
                    'children_count' => $childrenCount,
                    'attendances_count' => $rowsToday->count(),
                    'present_count' => $rowsToday->where('status', 'present')->count(),
                ];
            })
            ->values();

        return $this->success(['lessons' => $lessons]);
    }

    public function sheet(Request $request, Lesson $lesson): JsonResponse
    {
        if ($response = $this->ensureLessonScope($request, $lesson)) {
            return $response;
        }

        $lesson->load([
            'attendances' => fn ($q) => $q->whereDate('date', $this->lessonDate($lesson)),
        ]);

        $lessonDate = $this->lessonDate($lesson);

        $accessRows = \App\Models\Access::where('access', true)
            ->where('payment_status', 'paid')
            ->where(function ($q) use ($lesson) {
                $q->where('lesson_id', $lesson->id)
                  ->orWhereJsonContains('lesson_ids', $lesson->id)
                  ->orWhereRaw('JSON_CONTAINS(lesson_ids, ?)', [json_encode((string) $lesson->id)]);
            })
            ->get();

        $childIds = $accessRows->pluck('child_id')->unique()->values();

        $children = \App\Models\Child::whereIn('id', $childIds->all())
            ->get(['id', 'child_name']);

        $attendanceRows = Attendance::where('lesson_id', $lesson->id)
            ->get(['id', 'child_id', 'status', 'approved', 'notes', 'date']);

        $rows = $children->map(function ($child) use ($attendanceRows) {
            $a = $attendanceRows->firstWhere('child_id', $child->id);

            return [
                'child_id' => $child->id,
                'name' => $child->child_name,
                'status' => $a->status ?? 'pending',
                'approved' => $a->approved ?? false,
                'notes' => $a->notes ?? '',
                'attendance_id' => $a->id ?? null,
            ];
        })->values();

        return $this->success([
            'lesson' => $lesson->only('id', 'title', 'start_time'),
            'rows' => $rows,
            'date' => $lessonDate,
        ]);
    }

    public function markAll(AttendanceMarkAllRequest $request, Lesson $lesson): JsonResponse
    {
        if ($response = $this->ensureLessonScope($request, $lesson)) {
            return $response;
        }

        $data = $request->validated();

        $date = $this->lessonDate($lesson);
        $skipped = [];
        $updated = [];
        $duplicatesFound = [];

        $accessRows = \App\Models\Access::where('access', true)
            ->where('payment_status', 'paid')
            ->where(function ($q) use ($lesson) {
                $q->where('lesson_id', $lesson->id)
                  ->orWhereJsonContains('lesson_ids', $lesson->id)
                  ->orWhereRaw('JSON_CONTAINS(lesson_ids, ?)', [json_encode((string) $lesson->id)]);
            })
            ->get();

        $childIds = $accessRows->pluck('child_id')->unique()->values();
        $children = \App\Models\Child::whereIn('id', $childIds->all())->get(['id', 'child_name']);

        foreach ($children as $child) {
            $existing = Attendance::where('lesson_id', $lesson->id)
                ->where('child_id', $child->id)
                ->whereDate('date', $date)
                ->get();

            if ($existing->count() > 1) {
                $duplicatesFound[] = [
                    'child_id' => $child->id,
                    'duplicate_ids' => $existing->pluck('id')->all(),
                ];
            }

            $single = $existing->first();

            if ($single && $single->approved) {
                $skipped[] = $child->id;
                continue;
            }

            Attendance::updateOrCreate(
                ['lesson_id' => $lesson->id, 'child_id' => $child->id, 'date' => $date],
                [
                    'status' => $data['status'],
                    'notes' => $data['notes'] ?? null,
                    'approved' => $single ? $single->approved : false,
                ]
            );

            $post = Attendance::where('lesson_id', $lesson->id)
                ->where('child_id', $child->id)
                ->whereDate('date', $date)
                ->get();

            if ($post->count() > 1) {
                $duplicatesFound[] = [
                    'child_id' => $child->id,
                    'duplicate_ids' => $post->pluck('id')->all(),
                ];
            }

            $updated[] = $child->id;
        }

        $message = 'All students marked "' . $data['status'] . '"';
        if (!empty($skipped)) {
            $message .= ' — skipped ' . count($skipped) . ' approved rows';
        }

        return $this->success([
            'message' => $message,
            'updated_count' => count($updated),
            'skipped_count' => count($skipped),
            'duplicates' => $duplicatesFound,
        ]);
    }

    public function approveAll(Request $request, Lesson $lesson): JsonResponse
    {
        if ($response = $this->ensureLessonScope($request, $lesson)) {
            return $response;
        }

        Attendance::where('lesson_id', $lesson->id)
            ->update([
                'approved' => true,
                'approved_by' => $request->user()?->id,
                'approved_at' => now(),
            ]);

        return $this->success(['message' => 'Attendance approved']);
    }

    public function approve(AttendanceApproveRequest $request, Attendance $attendance): JsonResponse
    {
        $validated = $request->validated();

        if ($response = $this->ensureAttendanceScope($request, $attendance)) {
            return $response;
        }

        $attendance->update([
            'status' => $validated['status'],
            'approved' => $validated['approve'],
            'approved_by' => $validated['approve'] ? $request->user()?->id : null,
            'approved_at' => $validated['approve'] ? now() : null,
        ]);

        return $this->success(['message' => 'Attendance updated']);
    }

    private function lessonDate(Lesson $lesson): string
    {
        return $lesson->start_time->toDateString();
    }

    private function ensureLessonScope(Request $request, Lesson $lesson): ?JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        if ($user->isSuperAdmin() && $request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
        } else {
            $orgId = $request->attributes->get('organization_id');
        }

        if ($orgId && (int) $lesson->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        return null;
    }

    private function ensureAttendanceScope(Request $request, Attendance $attendance): ?JsonResponse
    {
        $lesson = Lesson::find($attendance->lesson_id);
        if (!$lesson) {
            return $this->error('Not found.', [], 404);
        }

        return $this->ensureLessonScope($request, $lesson);
    }
}
