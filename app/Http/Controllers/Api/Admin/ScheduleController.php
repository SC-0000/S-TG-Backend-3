<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScheduleAllocation;
use App\Models\TeacherProfile;
use App\Services\ScheduleAllocationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    public function __construct(
        private ScheduleAllocationService $allocationService
    ) {}

    /* =============================================================
     |  GET /api/v1/admin/schedule/{teacherId}
     |  Unified schedule view — all three layers in one response
     | ============================================================= */
    public function show(Request $request, int $teacherId): JsonResponse
    {
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date',
        ]);

        $dateFrom = Carbon::parse($request->date_from ?? now()->startOfWeek());
        $dateTo = Carbon::parse($request->date_to ?? now()->endOfWeek());

        $schedule = $this->allocationService->getUnifiedSchedule($teacherId, $dateFrom, $dateTo);

        if (!$schedule['teacher']) {
            return response()->json(['message' => 'Teacher not found.'], 404);
        }

        // Return all bookable services this teacher is eligible for
        // Match by teacher_ids JSON (try both int and string), instructor_id, or null teacher_ids (any teacher)
        $schedule['services'] = \App\Models\Service::whereIn('booking_mode', ['flexible_booking', 'fixed_schedule'])
            ->where('availability', true)
            ->where(function ($q) use ($teacherId) {
                $q->whereJsonContains('teacher_ids', (int) $teacherId)
                    ->orWhereJsonContains('teacher_ids', (string) $teacherId)
                    ->orWhere('instructor_id', $teacherId)
                    ->orWhere(function ($q2) {
                        $q2->whereNull('teacher_ids')
                            ->orWhere('teacher_ids', '[]')
                            ->orWhere('teacher_ids', 'null');
                    });
            })
            ->select('id', 'service_name', '_type', 'booking_mode', 'session_duration_minutes', 'max_participants', 'credits_per_purchase', 'price')
            ->get();

        // If no services matched via teacher filtering, also include org-level services as fallback
        if ($schedule['services']->isEmpty() && $schedule['teacher']) {
            $schedule['services'] = \App\Models\Service::whereIn('booking_mode', ['flexible_booking', 'fixed_schedule'])
                ->where('availability', true)
                ->select('id', 'service_name', '_type', 'booking_mode', 'session_duration_minutes', 'max_participants', 'credits_per_purchase', 'price')
                ->get();
        }

        return response()->json($schedule);
    }

    /* =============================================================
     |  POST /api/v1/admin/schedule/{teacherId}/allocations
     |  Create a new schedule allocation (drag-drop from sidebar)
     | ============================================================= */
    public function storeAllocation(Request $request, int $teacherId): JsonResponse
    {
        $request->validate([
            'service_id'       => 'nullable|integer|exists:services,id',
            'day_of_week'      => 'required|integer|between:1,7',
            'start_time'       => 'required|date_format:H:i',
            'end_time'         => 'required|date_format:H:i|after:start_time',
            'allocation_type'  => 'required|in:fixed,bookable',
            'recurrence'       => 'nullable|in:weekly,biweekly',
            'effective_from'   => 'nullable|date',
            'effective_until'  => 'nullable|date|after_or_equal:effective_from',
            'title'            => 'nullable|string|max:255',
            'max_participants' => 'nullable|integer|min:1',
        ]);

        $profile = TeacherProfile::where('user_id', $teacherId)->first();
        if (!$profile) {
            return response()->json(['message' => 'Teacher profile not found.'], 404);
        }

        try {
            $allocation = $this->allocationService->createAllocation(
                $request->only([
                    'service_id', 'day_of_week', 'start_time', 'end_time',
                    'allocation_type', 'recurrence', 'effective_from', 'effective_until',
                    'title', 'max_participants',
                ]),
                $profile
            );

            $lessonsGenerated = $allocation->isFixed() ? $allocation->lessons()->count() : 0;

            return response()->json([
                'message'           => 'Allocation created.',
                'allocation'        => $allocation,
                'lessons_generated' => $lessonsGenerated,
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /* =============================================================
     |  PUT /api/v1/admin/schedule/{teacherId}/allocations/{id}
     |  Update an allocation (move/resize on calendar)
     | ============================================================= */
    public function updateAllocation(Request $request, int $teacherId, int $id): JsonResponse
    {
        $request->validate([
            'service_id'       => 'nullable|integer|exists:services,id',
            'day_of_week'      => 'nullable|integer|between:1,7',
            'start_time'       => 'nullable|date_format:H:i',
            'end_time'         => 'nullable|date_format:H:i',
            'allocation_type'  => 'nullable|in:fixed,bookable',
            'recurrence'       => 'nullable|in:weekly,biweekly',
            'effective_from'   => 'nullable|date',
            'effective_until'  => 'nullable|date',
            'title'            => 'nullable|string|max:255',
            'max_participants' => 'nullable|integer|min:1',
        ]);

        $allocation = ScheduleAllocation::where('id', $id)
            ->whereHas('teacherProfile', fn ($q) => $q->where('user_id', $teacherId))
            ->first();

        if (!$allocation) {
            return response()->json(['message' => 'Allocation not found.'], 404);
        }

        try {
            $updated = $this->allocationService->updateAllocation($allocation, $request->all());

            return response()->json([
                'message'    => 'Allocation updated.',
                'allocation' => $updated,
            ]);

        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /* =============================================================
     |  DELETE /api/v1/admin/schedule/{teacherId}/allocations/{id}
     |  Remove an allocation
     | ============================================================= */
    public function destroyAllocation(Request $request, int $teacherId, int $id): JsonResponse
    {
        $allocation = ScheduleAllocation::where('id', $id)
            ->whereHas('teacherProfile', fn ($q) => $q->where('user_id', $teacherId))
            ->first();

        if (!$allocation) {
            return response()->json(['message' => 'Allocation not found.'], 404);
        }

        $cancelFuture = $request->boolean('cancel_future_lessons', false);
        $this->allocationService->deleteAllocation($allocation, $cancelFuture);

        return response()->json(['message' => 'Allocation removed.']);
    }

    /* =============================================================
     |  POST /api/v1/admin/schedule/{teacherId}/working-hours
     |  Update working hours (replaces all for specified days)
     | ============================================================= */
    public function updateWorkingHours(Request $request, int $teacherId): JsonResponse
    {
        $request->validate([
            'hours'               => 'required|array',
            'hours.*.day_of_week' => 'required|integer|between:1,7',
            'hours.*.start_time'  => 'required|date_format:H:i',
            'hours.*.end_time'    => 'required|date_format:H:i|after:hours.*.start_time',
        ]);

        $profile = TeacherProfile::firstOrCreate(
            ['user_id' => $teacherId],
            ['display_name' => \App\Models\User::find($teacherId)?->name ?? '', 'max_hours_per_day' => 8, 'max_hours_per_week' => 40]
        );

        // Get unique days being updated
        $daysToUpdate = collect($request->hours)->pluck('day_of_week')->unique();

        // Delete existing availability for those days
        $profile->availabilities()->whereIn('day_of_week', $daysToUpdate)->delete();

        // Create new ones
        foreach ($request->hours as $hour) {
            $profile->availabilities()->create([
                'day_of_week'          => $hour['day_of_week'],
                'start_time'           => $hour['start_time'],
                'end_time'             => $hour['end_time'],
                'is_recurring'         => true,
                'slot_duration_minutes' => $hour['slot_duration_minutes'] ?? 60,
                'buffer_minutes'       => $hour['buffer_minutes'] ?? 0,
            ]);
        }

        return response()->json([
            'message'       => 'Working hours updated.',
            'working_hours' => $profile->availabilities()->orderBy('day_of_week')->orderBy('start_time')->get(),
        ]);
    }

    /* =============================================================
     |  POST /api/v1/admin/schedule/{teacherId}/generate-lessons
     |  Manually generate lessons for a date range
     | ============================================================= */
    public function generateLessons(Request $request, int $teacherId): JsonResponse
    {
        $request->validate([
            'allocation_id' => 'nullable|integer|exists:schedule_allocations,id',
            'date_from'     => 'nullable|date',
            'date_until'    => 'nullable|date|after_or_equal:date_from',
            'weeks_ahead'   => 'nullable|integer|min:1|max:52',
        ]);

        $profile = TeacherProfile::where('user_id', $teacherId)->first();
        if (!$profile) {
            return response()->json(['message' => 'Teacher profile not found.'], 404);
        }

        $from = $request->date_from ? Carbon::parse($request->date_from) : null;
        $until = $request->date_until ? Carbon::parse($request->date_until) : null;
        $weeks = $request->weeks_ahead ?? 4;

        if ($request->allocation_id) {
            $allocation = ScheduleAllocation::where('id', $request->allocation_id)
                ->where('teacher_profile_id', $profile->id)
                ->first();

            if (!$allocation) {
                return response()->json(['message' => 'Allocation not found.'], 404);
            }

            $generated = $this->allocationService->generateLessonsForAllocation($allocation, $weeks, $from, $until);
            return response()->json([
                'message'  => "{$generated->count()} lesson(s) generated.",
                'count'    => $generated->count(),
                'lessons'  => $generated,
            ]);
        }

        // Generate for all fixed allocations
        $totalCount = $this->allocationService->extendLessonsForTeacher($profile, $weeks);

        return response()->json([
            'message' => "{$totalCount} lesson(s) generated across all allocations.",
            'count'   => $totalCount,
        ]);
    }

    /* =============================================================
     |  PUT /api/v1/admin/schedule/{teacherId}/settings
     |  Update teacher schedule settings
     | ============================================================= */
    public function updateSettings(Request $request, int $teacherId): JsonResponse
    {
        $request->validate([
            'max_hours_per_day'  => 'nullable|integer|min:1|max:24',
            'max_hours_per_week' => 'nullable|integer|min:1|max:168',
            'auto_bookable'      => 'nullable|boolean',
        ]);

        $profile = TeacherProfile::where('user_id', $teacherId)->first();
        if (!$profile) {
            return response()->json(['message' => 'Teacher profile not found.'], 404);
        }

        $profile->update(array_filter($request->only(['max_hours_per_day', 'max_hours_per_week', 'auto_bookable']), fn ($v) => $v !== null));

        return response()->json([
            'message' => 'Settings updated.',
            'profile' => $profile->fresh(),
        ]);
    }
}
