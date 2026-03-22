<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\LessonNotification;
use App\Models\Service;
use App\Models\ServiceCredit;
use App\Models\User;
use App\Services\Tasks\TaskService;
use App\Services\TeacherScheduleService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SlotBookingController extends Controller
{
    public function __construct(
        private TeacherScheduleService $scheduleService
    ) {}

    /* ================================================================
     |  GET  /api/v1/services/{service}/available-slots
     | ================================================================ */
    public function availableSlots(Request $request, Service $service): JsonResponse
    {
        $request->validate([
            'date_from'  => 'required|date|after_or_equal:today',
            'date_to'    => 'required|date|after_or_equal:date_from',
            'teacher_id' => 'nullable|integer|exists:users,id',
        ]);

        if ($service->booking_mode !== 'flexible_booking') {
            return response()->json(['message' => 'This service does not support flexible booking.'], 422);
        }

        // Service availability window check
        if ($service->start_datetime && now()->lt($service->start_datetime)) {
            return response()->json(['message' => 'This service is not yet available.', 'slots' => [], 'teachers' => []], 200);
        }
        if ($service->end_datetime && now()->gt($service->end_datetime)) {
            return response()->json(['message' => 'This service has ended.', 'slots' => [], 'teachers' => []], 200);
        }

        $dateFrom = Carbon::parse($request->date_from)->startOfDay();
        $dateTo   = Carbon::parse($request->date_to)->endOfDay();

        // Cap range to 4 weeks
        if ($dateFrom->diffInDays($dateTo) > 28) {
            $dateTo = $dateFrom->copy()->addDays(28);
        }

        $duration   = $service->session_duration_minutes ?? 60;
        $teacherIds = $this->getEligibleTeacherIds($service, $request->teacher_id);

        if (empty($teacherIds)) {
            return response()->json(['slots' => [], 'teachers' => []]);
        }

        $slots = $this->scheduleService->getAvailableSlotsForService(
            $teacherIds, $dateFrom, $dateTo, $duration, $service->organization_id
        );

        // For group services: merge with partially-filled existing group lessons
        if ($service->max_participants && $service->max_participants > 1) {
            $slots = $this->filterGroupSlots($slots, $service);
        }

        $teachers = $service->getEligibleTeachers()
            ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])
            ->values();

        return response()->json([
            'slots'    => $slots,
            'teachers' => $teachers,
            'service'  => [
                'id'                      => $service->id,
                'name'                    => $service->service_name,
                'duration'                => $duration,
                'session_duration_minutes' => $duration,
                'price'                   => $service->price,
                'max_participants'        => $service->max_participants,
                'credits_per_purchase'    => $service->credits_per_purchase,
                'allow_recurring'         => $service->allow_recurring,
                'cancellation_hours'      => $service->cancellation_hours,
                'default_lesson_mode'     => $service->default_lesson_mode ?? 'both',
            ],
        ]);
    }

    /* ================================================================
     |  POST /api/v1/bookings/services/{service}/book
     | ================================================================ */
    public function book(Request $request, Service $service): JsonResponse
    {
        $request->validate([
            'child_id'         => 'required|integer|exists:children,id',
            'teacher_id'       => 'required|integer|exists:users,id',
            'start_time'       => 'required|date|after:now',
            'lesson_type'      => 'nullable|in:1:1,group',
            'lesson_mode'      => 'nullable|in:in_person,online',
            'notes'            => 'nullable|string|max:500',
            'recurring'        => 'nullable|boolean',
            'recurrence_weeks' => 'nullable|integer|min:1|max:52',
        ]);

        if ($service->booking_mode !== 'flexible_booking') {
            return response()->json(['message' => 'This service does not support flexible booking.'], 422);
        }

        // Service availability window check
        if ($service->end_datetime && now()->gt($service->end_datetime)) {
            return response()->json(['message' => 'This service has ended.'], 422);
        }

        $start     = Carbon::parse($request->start_time);
        $duration  = $service->session_duration_minutes ?? 60;
        $end       = $start->copy()->addMinutes($duration);
        $teacherId = $request->teacher_id;
        $childId   = $request->child_id;

        // Validate teacher eligibility
        $eligibleIds = $this->getEligibleTeacherIds($service);
        if (!in_array($teacherId, $eligibleIds)) {
            return response()->json(['message' => 'This teacher is not available for this service.'], 422);
        }

        // Recurring config
        $isRecurring = $request->recurring && $service->allow_recurring;
        $totalWeeks  = $isRecurring ? min($request->recurrence_weeks ?? 4, 52) : 1;

        // Upfront credit check for ALL sessions
        if ($service->isCreditBased()) {
            $credit = ServiceCredit::where('child_id', $childId)
                ->where('service_id', $service->id)
                ->valid()
                ->first();

            if (!$credit) {
                return response()->json(['message' => 'No available credits for this service.'], 422);
            }

            if ($credit->remaining < $totalWeeks) {
                return response()->json([
                    'message' => "You need {$totalWeeks} credit(s) but only have {$credit->remaining} remaining."
                        . ($isRecurring ? ' Reduce the number of weeks or purchase more credits.' : ''),
                ], 422);
            }
        }

        $bookings = collect();
        $errors   = [];

        DB::beginTransaction();
        try {
            for ($week = 0; $week < $totalWeeks; $week++) {
                $slotStart = $start->copy()->addWeeks($week);
                $slotEnd   = $slotStart->copy()->addMinutes($duration);

                // Verify slot availability
                if (!$this->scheduleService->isSlotAvailable($teacherId, $slotStart, $slotEnd)) {
                    if ($isRecurring) {
                        $errors[] = "Slot on {$slotStart->format('D d M')} at {$slotStart->format('H:i')} is unavailable — skipped.";
                        continue;
                    }
                    DB::rollBack();
                    return response()->json(['message' => 'This time slot is no longer available.'], 409);
                }

                // Group session: try to add child to an existing lesson at same time/teacher
                if ($service->max_participants && $service->max_participants > 1) {
                    $existingLesson = Lesson::where('service_id', $service->id)
                        ->where('start_time', $slotStart)
                        ->where('instructor_id', $teacherId)
                        ->whereNotIn('status', ['cancelled', 'draft'])
                        ->lockForUpdate()
                        ->withCount('children')
                        ->first();

                    // Use per-lesson cap when set, otherwise fall back to service-level cap
                    $effectiveCap = $existingLesson?->max_participants ?? $service->max_participants;

                    if ($existingLesson && $existingLesson->children_count < $effectiveCap) {
                        $existingLesson->children()->syncWithoutDetaching([$childId]);
                        $this->useCredit($service, $childId);
                        $bookings->push($existingLesson->fresh());
                        continue;
                    } elseif ($existingLesson && $existingLesson->children_count >= $effectiveCap) {
                        if ($isRecurring) {
                            $errors[] = "Group on {$slotStart->format('D d M')} is full — skipped.";
                            continue;
                        }
                        DB::rollBack();
                        return response()->json(['message' => 'This group session is full.'], 409);
                    }
                }

                // Find matching bookable allocation for this slot (if any)
                $matchingAllocation = \App\Models\ScheduleAllocation::whereHas('teacherProfile', fn ($q) => $q->where('user_id', $teacherId))
                    ->where('allocation_type', 'bookable')
                    ->where('day_of_week', $slotStart->dayOfWeekIso)
                    ->where('start_time', '<=', $slotStart->format('H:i'))
                    ->where('end_time', '>=', $slotEnd->format('H:i'))
                    ->where(function ($q) use ($service) {
                        $q->whereNull('service_id')->orWhere('service_id', $service->id);
                    })
                    ->first();

                // Resolve lesson mode: use service default if not 'both', else use parent's choice
                $resolvedMode = match ($service->default_lesson_mode ?? 'both') {
                    'online'    => 'online',
                    'in_person' => 'in_person',
                    default     => $request->lesson_mode ?? 'online',
                };

                // Create new lesson (booking slot)
                $lesson = Lesson::create([
                    'title'              => $service->service_name,
                    'description'        => $request->notes,
                    'lesson_type'        => $request->lesson_type ?? ($service->max_participants > 1 ? 'group' : '1:1'),
                    'lesson_mode'        => $resolvedMode,
                    'start_time'         => $slotStart,
                    'end_time'           => $slotEnd,
                    'instructor_id'      => $teacherId,
                    'service_id'         => $service->id,
                    'allocation_id'      => $matchingAllocation?->id,
                    'status'             => 'scheduled',
                    'organization_id'    => $service->organization_id,
                ]);

                $lesson->children()->attach($childId);
                $this->useCredit($service, $childId);
                $bookings->push($lesson);
            }

            DB::commit();

            // Send notifications (outside transaction)
            $this->notifyBookingCreated($bookings, $service, $childId, $teacherId);

            return response()->json([
                'message'  => $bookings->count() . ' session(s) booked successfully.',
                'bookings' => $bookings->map(fn ($l) => [
                    'id'         => $l->id,
                    'title'      => $l->title,
                    'start_time' => $l->start_time?->toIso8601String(),
                    'end_time'   => $l->end_time?->toIso8601String(),
                    'status'     => $l->status,
                    'teacher_id' => $l->instructor_id,
                ]),
                'skipped' => $errors,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Booking failed', ['error' => $e->getMessage(), 'service' => $service->id]);
            return response()->json(['message' => 'Booking failed. Please try again.'], 500);
        }
    }

    /* ================================================================
     |  POST /api/v1/bookings/services/{service}/request
     |  For services with booking_mode = 'requested'
     | ================================================================ */
    public function requestSession(Request $request, Service $service): JsonResponse
    {
        $request->validate([
            'child_id'       => 'required|integer|exists:children,id',
            'preferred_date' => 'required|date|after_or_equal:today',
            'preferred_time' => 'nullable|string|max:20',
            'lesson_mode'    => 'nullable|in:online,in_person',
            'notes'          => 'nullable|string|max:1000',
        ]);

        if ($service->booking_mode !== 'requested') {
            return response()->json(['message' => 'This service does not accept session requests.'], 422);
        }

        if (!$service->availability) {
            return response()->json(['message' => 'This service is not currently available.'], 422);
        }

        $child = \App\Models\Child::find($request->child_id);
        if (!$child) {
            return response()->json(['message' => 'Child not found.'], 422);
        }

        $timeLabel = $request->preferred_date
            . ($request->preferred_time ? ' at ' . $request->preferred_time : '');

        $modeLabel = match ($request->lesson_mode) {
            'online'    => 'Online',
            'in_person' => 'In-Person',
            default     => 'No preference',
        };

        // Create an admin task so the team can review and confirm
        TaskService::createFromEvent('custom_task', [
            'organization_id' => $service->organization_id,
            'title'           => 'Session Request — ' . $service->service_name,
            'description'     => "{$child->child_name} has requested a session for '{$service->service_name}'. "
                . "Preferred: {$timeLabel} ({$modeLabel})."
                . ($request->notes ? " Notes: {$request->notes}" : ''),
            'source_model'    => $service,
            'metadata'        => [
                'type'           => 'session_request',
                'service_id'     => $service->id,
                'service_name'   => $service->service_name,
                'child_id'       => $request->child_id,
                'child_name'     => $child->child_name,
                'preferred_date' => $request->preferred_date,
                'preferred_time' => $request->preferred_time,
                'lesson_mode'    => $request->lesson_mode,
                'notes'          => $request->notes,
            ],
        ]);

        return response()->json([
            'message' => 'Session request submitted! We\'ll be in touch to confirm your session.',
        ], 201);
    }

    /* ================================================================
     |  POST /api/v1/bookings/{lesson}/cancel
     | ================================================================ */
    public function cancel(Request $request, Lesson $lesson): JsonResponse
    {
        $request->validate([
            'child_id' => 'required|integer|exists:children,id',
            'reason'   => 'nullable|string|max:500',
        ]);

        $service = $lesson->service;
        $childId = $request->child_id;

        // Cancellation policy check
        if ($service && $service->cancellation_hours) {
            $hoursUntilStart = now()->diffInHours(Carbon::parse($lesson->start_time), false);
            if ($hoursUntilStart < $service->cancellation_hours) {
                return response()->json([
                    'message' => "Cancellations require at least {$service->cancellation_hours} hours notice.",
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            $lesson->children()->detach($childId);

            // If empty, mark cancelled and free the slot
            if ($lesson->children()->count() === 0) {
                $lesson->update(['status' => 'cancelled']);
            }

            // Refund credit
            if ($service && $service->isCreditBased()) {
                $credit = ServiceCredit::where('child_id', $childId)
                    ->where('service_id', $service->id)
                    ->first();
                $credit?->refundCredit();
            }

            DB::commit();

            // Notifications
            $this->notifyBookingCancelled($lesson, $service, $childId, $request->reason);

            return response()->json([
                'message'         => 'Booking cancelled successfully.',
                'credit_refunded' => $service?->isCreditBased() ?? false,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Cancellation failed.'], 500);
        }
    }

    /* ================================================================
     |  POST /api/v1/bookings/{lesson}/reschedule
     | ================================================================ */
    public function reschedule(Request $request, Lesson $lesson): JsonResponse
    {
        $request->validate([
            'child_id'       => 'required|integer|exists:children,id',
            'new_start_time' => 'required|date|after:now',
            'new_teacher_id' => 'nullable|integer|exists:users,id',
        ]);

        $service       = $lesson->service;
        $duration      = $service?->session_duration_minutes ?? 60;
        $newStart      = Carbon::parse($request->new_start_time);
        $newEnd        = $newStart->copy()->addMinutes($duration);
        $newTeacherId  = $request->new_teacher_id ?? $lesson->instructor_id;
        $oldStartLabel = $lesson->start_time?->format('D d M H:i');

        // Cancellation policy for original slot
        if ($service && $service->cancellation_hours) {
            $hoursUntilStart = now()->diffInHours(Carbon::parse($lesson->start_time), false);
            if ($hoursUntilStart < $service->cancellation_hours) {
                return response()->json([
                    'message' => "Rescheduling requires at least {$service->cancellation_hours} hours notice.",
                ], 422);
            }
        }

        if (!$this->scheduleService->isSlotAvailable($newTeacherId, $newStart, $newEnd)) {
            return response()->json(['message' => 'The new time slot is not available.'], 409);
        }

        DB::beginTransaction();
        try {
            $lesson->update([
                'start_time'    => $newStart,
                'end_time'      => $newEnd,
                'instructor_id' => $newTeacherId,
            ]);

            DB::commit();

            $this->notifyBookingRescheduled($lesson, $service, $oldStartLabel);

            return response()->json([
                'message' => 'Session rescheduled successfully.',
                'booking' => [
                    'id'         => $lesson->id,
                    'start_time' => $lesson->start_time->toIso8601String(),
                    'end_time'   => $lesson->end_time->toIso8601String(),
                    'teacher_id' => $lesson->instructor_id,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Rescheduling failed.'], 500);
        }
    }

    /* ================================================================
     |  GET  /api/v1/bookings
     | ================================================================ */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'child_id'   => 'nullable|integer',
            'status'     => 'nullable|in:upcoming,past,cancelled',
            'service_id' => 'nullable|integer',
        ]);

        $user     = $request->user();
        $childIds = $user->children()->pluck('id');

        if ($request->child_id) {
            $childIds = $childIds->intersect([$request->child_id]);
        }

        $query = Lesson::whereHas('children', fn ($q) => $q->whereIn('children.id', $childIds))
            ->with([
                'service:id,service_name,price,booking_mode,credits_per_purchase,max_participants,cancellation_hours,session_duration_minutes',
                'children:id,child_name',
            ])
            ->withCount('children as participants_count')
            ->addSelect(['*'])
            ->selectSub(
                User::select('name')->whereColumn('users.id', 'live_sessions.instructor_id')->limit(1),
                'instructor_name'
            );

        match ($request->status) {
            'upcoming'  => $query->where('start_time', '>', now())->whereNotIn('status', ['cancelled']),
            'past'      => $query->where('start_time', '<', now()),
            'cancelled' => $query->where('status', 'cancelled'),
            default     => $query->whereNotIn('status', ['cancelled']),
        };

        if ($request->service_id) {
            $query->where('service_id', $request->service_id);
        }

        $bookings = $query->orderBy('start_time', $request->status === 'past' ? 'desc' : 'asc')
            ->paginate(20);

        $serviceCredits = [];
        if ($childIds->isNotEmpty()) {
            $serviceCredits = ServiceCredit::whereIn('child_id', $childIds)
                ->valid()
                ->with('service:id,service_name')
                ->get()
                ->groupBy('service_id')
                ->map(fn ($credits) => [
                    'service_name' => $credits->first()?->service?->service_name ?? 'Service',
                    'total'        => $credits->sum('total_credits'),
                    'used'         => $credits->sum('used_credits'),
                    'remaining'    => $credits->sum(fn ($c) => $c->remaining),
                ]);
        }

        return response()->json([
            'bookings' => $bookings,
            'credits'  => $serviceCredits,
        ]);
    }

    /* ================================================================
     |  Private helpers
     | ================================================================ */

    private function getEligibleTeacherIds(Service $service, ?int $filteredTeacherId = null): array
    {
        $teachers = $service->getEligibleTeachers();
        $ids      = $teachers->pluck('id')->toArray();

        if ($filteredTeacherId && in_array($filteredTeacherId, $ids)) {
            return [$filteredTeacherId];
        }

        return $ids;
    }

    /** Use one credit for a booking if the service is credit-based. */
    private function useCredit(Service $service, int $childId): void
    {
        if (!$service->isCreditBased()) {
            return;
        }
        $credit = ServiceCredit::where('child_id', $childId)
            ->where('service_id', $service->id)
            ->valid()
            ->first();
        $credit?->useCredit();
    }

    /** Filter available slots for group services — include partially-filled existing group lessons. */
    private function filterGroupSlots($slots, Service $service)
    {
        // Get all existing group lessons for this service with room
        $existingGroupLessons = Lesson::where('service_id', $service->id)
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->withCount('children')
            ->get()
            ->filter(fn ($l) => $l->children_count < ($l->max_participants ?? $service->max_participants));

        return $slots->filter(function ($slot) use ($service, $existingGroupLessons) {
            // Check if an existing group lesson at this time/teacher is full
            $existing = $existingGroupLessons->first(function ($l) use ($slot) {
                return Carbon::parse($l->start_time)->eq(Carbon::parse($slot['start']))
                    && $l->instructor_id == $slot['teacher_id'];
            });

            // If there's an existing lesson that's NOT full, show the slot (joinable)
            if ($existing) {
                return true;
            }

            // If there's an existing FULL lesson at this time/teacher, hide the slot
            $fullLesson = Lesson::where('service_id', $service->id)
                ->where('start_time', $slot['start'])
                ->where('instructor_id', $slot['teacher_id'])
                ->whereNotIn('status', ['cancelled', 'draft'])
                ->withCount('children')
                ->first();

            if ($fullLesson && $fullLesson->children_count >= ($fullLesson->max_participants ?? $service->max_participants)) {
                return false;
            }

            // No existing lesson — slot is available for a new group to form
            return true;
        })->values();
    }

    /* ================================================================
     |  Notification helpers
     | ================================================================ */

    private function notifyBookingCreated($bookings, Service $service, int $childId, int $teacherId): void
    {
        try {
            $child   = \App\Models\Child::find($childId);
            $teacher = User::find($teacherId);

            if (!$child || !$teacher || $bookings->isEmpty()) {
                return;
            }

            $firstBooking = $bookings->first();
            $count        = $bookings->count();
            $timeLabel    = Carbon::parse($firstBooking->start_time)->format('D d M, H:i');

            // Notify teacher via TaskService
            TaskService::createFromEvent('lesson_assigned', [
                'organization_id' => $service->organization_id,
                'assigned_to'     => $teacherId,
                'title'           => 'New Booking — ' . $child->child_name,
                'description'     => "{$child->child_name} booked {$count} session(s) for {$service->service_name} — first session {$timeLabel}.",
                'source_model'    => $firstBooking,
                'metadata'        => [
                    'type'       => 'booking_created',
                    'service_id' => $service->id,
                    'child_id'   => $childId,
                    'lesson_ids' => $bookings->pluck('id')->toArray(),
                ],
            ]);

            // Notify parent via LessonNotification
            if ($child->user_id) {
                foreach ($bookings as $booking) {
                    LessonNotification::create([
                        'user_id'     => $child->user_id,
                        'message'     => "Session booked: {$service->service_name} on " . Carbon::parse($booking->start_time)->format('D d M, H:i') . " with {$teacher->name}.",
                        'type'        => 'booking_confirmed',
                        'read_status' => false,
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Booking notification failed', ['error' => $e->getMessage()]);
        }
    }

    private function notifyBookingCancelled(Lesson $lesson, ?Service $service, int $childId, ?string $reason): void
    {
        try {
            $child     = \App\Models\Child::find($childId);
            $timeLabel = $lesson->start_time?->format('D d M, H:i');

            if ($lesson->instructor_id) {
                TaskService::createFromEvent('custom_task', [
                    'organization_id' => $lesson->organization_id,
                    'assigned_to'     => $lesson->instructor_id,
                    'title'           => 'Booking Cancelled — ' . ($child?->child_name ?? 'Student'),
                    'description'     => ($child?->child_name ?? 'A student') . " cancelled their session on {$timeLabel}" . ($reason ? " — Reason: {$reason}" : '.'),
                    'source_model'    => $lesson,
                    'metadata'        => [
                        'type'      => 'booking_cancelled',
                        'lesson_id' => $lesson->id,
                        'child_id'  => $childId,
                    ],
                ]);
            }

            if ($child?->user_id) {
                LessonNotification::create([
                    'user_id'     => $child->user_id,
                    'message'     => "Your session on {$timeLabel}" . ($service ? " ({$service->service_name})" : '') . ' has been cancelled.',
                    'type'        => 'booking_cancelled',
                    'read_status' => false,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Cancellation notification failed', ['error' => $e->getMessage()]);
        }
    }

    private function notifyBookingRescheduled(Lesson $lesson, ?Service $service, string $oldStartLabel): void
    {
        try {
            $newTimeLabel = $lesson->start_time?->format('D d M, H:i');
            $childIds     = $lesson->children()->pluck('children.id');

            if ($lesson->instructor_id) {
                TaskService::createFromEvent('custom_task', [
                    'organization_id' => $lesson->organization_id,
                    'assigned_to'     => $lesson->instructor_id,
                    'title'           => 'Booking Rescheduled',
                    'description'     => "Session moved from {$oldStartLabel} to {$newTimeLabel}" . ($service ? " ({$service->service_name})" : '') . '.',
                    'source_model'    => $lesson,
                    'metadata'        => [
                        'type'      => 'booking_rescheduled',
                        'lesson_id' => $lesson->id,
                    ],
                ]);
            }

            foreach ($childIds as $childId) {
                $child = \App\Models\Child::find($childId);
                if ($child?->user_id) {
                    LessonNotification::create([
                        'user_id'     => $child->user_id,
                        'message'     => "Your session has been moved to {$newTimeLabel}" . ($service ? " ({$service->service_name})" : '') . '.',
                        'type'        => 'booking_rescheduled',
                        'read_status' => false,
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Reschedule notification failed', ['error' => $e->getMessage()]);
        }
    }
}
