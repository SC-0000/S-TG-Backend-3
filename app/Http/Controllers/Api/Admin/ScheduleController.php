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

        $dateFrom = Carbon::parse($request->date_from ?? now()->startOfWeek())->startOfDay();
        $dateTo = Carbon::parse($request->date_to ?? now()->endOfWeek())->endOfDay();

        $schedule = $this->allocationService->getUnifiedSchedule($teacherId, $dateFrom, $dateTo);

        if (!$schedule['teacher']) {
            return response()->json(['message' => 'Teacher not found.'], 404);
        }

        // Return ALL services — tag which ones are eligible for this teacher
        $schedule['services'] = \App\Models\Service::query()
            ->with([
                'lessons:id,title,lesson_mode,start_time',
                'assessments:id,title,deadline',
            ])
            ->select('id', 'service_name', '_type', 'booking_mode', 'session_duration_minutes', 'max_participants', 'credits_per_purchase', 'price', 'default_lesson_mode', 'teacher_ids', 'selection_config', 'availability')
            ->get()
            ->map(function ($service) use ($teacherId) {
                $tIds = $service->teacher_ids;
                $eligible = $service->instructor_id == $teacherId
                    || (is_array($tIds) && (in_array((int) $teacherId, $tIds) || in_array((string) $teacherId, $tIds)))
                    || empty($tIds) || $tIds === null;
                $service->teacher_eligible = $eligible;
                return $service;
            });

        // Pending/unscheduled lessons — sessions linked to this teacher's services
        // that have no instructor assigned yet (need scheduling attention)
        $serviceIds = $schedule['services']->pluck('id')->all();
        if (!empty($serviceIds)) {
            $schedule['pending_lessons'] = \App\Models\Lesson::whereIn('service_id', $serviceIds)
                ->whereNull('instructor_id')
                ->whereNotIn('status', ['cancelled', 'completed', 'draft'])
                ->with(['service:id,service_name,session_duration_minutes,max_participants,booking_mode,default_lesson_mode', 'children:id,child_name'])
                ->withCount('children as participants_count')
                ->orderByRaw('start_time IS NULL DESC')
                ->orderBy('start_time')
                ->orderBy('created_at', 'desc')
                ->limit(100)
                ->get()
                ->map(function ($l) {
                    return [
                        'id'                 => $l->id,
                        'title'              => $l->title,
                        'start_time'         => $l->start_time?->toIso8601String(),
                        'end_time'           => $l->end_time?->toIso8601String(),
                        'status'             => $l->status,
                        'lesson_type'        => $l->lesson_type,
                        'lesson_mode'        => $l->lesson_mode,
                        'participants_count' => $l->participants_count,
                        'max_participants'   => $l->max_participants ?? $l->service?->max_participants,
                        'student_name'       => $l->children->pluck('child_name')->join(', ') ?: null,
                        'service_name'       => $l->service?->service_name ?? $l->title,
                        'service_id'         => $l->service_id,
                        'booking_mode'       => $l->service?->booking_mode,
                        'session_duration_minutes' => $l->service?->session_duration_minutes,
                        'address'            => $l->address,
                        'meeting_link'       => $l->meeting_link,
                        'live_lesson_session_id' => $l->live_lesson_session_id,
                        'instructor_id'      => $l->instructor_id,
                    ];
                });
        } else {
            $schedule['pending_lessons'] = collect([]);
        }

        // All sessions for this teacher's services for sidebar display.
        // "All" in the scheduler sidebar should reflect the full lesson set, not a recent subset.
        if (!empty($serviceIds)) {
            // Pre-load instructor names to avoid N+1
            $instructorIds = \App\Models\Lesson::whereIn('service_id', $serviceIds)
                ->whereNotNull('instructor_id')
                ->whereNotIn('status', ['cancelled', 'draft'])
                ->distinct()
                ->pluck('instructor_id')
                ->all();
            $instructorMap = !empty($instructorIds)
                ? \App\Models\User::whereIn('id', $instructorIds)->pluck('name', 'id')->all()
                : [];

            $schedule['service_sessions'] = \App\Models\Lesson::whereIn('service_id', $serviceIds)
                ->whereNotIn('status', ['cancelled', 'draft'])
                ->with(['service:id,service_name,session_duration_minutes', 'children:id,child_name,user_id', 'children.user:id,name'])
                ->withCount('children as participants_count')
                ->orderByRaw('start_time IS NULL DESC')
                ->orderBy('start_time')
                ->get()
                ->map(function ($l) use ($instructorMap) {
                    // Resolve parent names from children → user relationship
                    $parentNames = $l->children
                        ->map(fn ($child) => $child->user?->name)
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();

                    return [
                        'id'                      => $l->id,
                        'title'                   => $l->title,
                        'start_time'              => $l->start_time?->toIso8601String(),
                        'end_time'                => $l->end_time?->toIso8601String(),
                        'status'                  => $l->status,
                        'lesson_type'             => $l->lesson_type,
                        'lesson_mode'             => $l->lesson_mode,
                        'participants_count'      => $l->participants_count,
                        'max_participants'        => $l->max_participants ?? $l->service?->max_participants,
                        'student_name'            => $l->children->pluck('child_name')->join(', ') ?: null,
                        'parent_names'            => $parentNames,
                        'service_name'            => $l->service?->service_name ?? $l->title,
                        'service_id'              => $l->service_id,
                        'instructor_id'           => $l->instructor_id,
                        'instructor_name'         => $instructorMap[$l->instructor_id] ?? null,
                        'address'                 => $l->address,
                        'meeting_link'            => $l->meeting_link,
                        'live_lesson_session_id'  => $l->live_lesson_session_id,
                        'session_duration_minutes'=> $l->start_time && $l->end_time
                            ? \Carbon\Carbon::parse($l->start_time)->diffInMinutes(\Carbon\Carbon::parse($l->end_time))
                            : ($l->service?->session_duration_minutes ?? null),
                    ];
                });
        } else {
            $schedule['service_sessions'] = collect([]);
        }

        return response()->json($schedule);
    }

    /* =============================================================
     |  PATCH /api/v1/admin/schedule/{teacherId}/lessons/{lessonId}/assign
     |  Quick-assign a lesson to a teacher + time without full validation
     | ============================================================= */
    public function assignLesson(Request $request, int $teacherId, int $lessonId): JsonResponse
    {
        $data = $request->validate([
            'instructor_id' => 'required|integer|exists:users,id',
            'start_time'    => 'required|date',
            'end_time'      => 'required|date|after:start_time',
        ]);

        $lesson = \App\Models\Lesson::findOrFail($lessonId);

        // Double-booking check
        $dbCheck = $this->checkDoubleBooking($data['instructor_id'], $data['start_time'], $data['end_time'], $lessonId, $lesson->service_id);
        if ($dbCheck instanceof JsonResponse) return $dbCheck;
        // If it returns an existing group lesson, we still proceed with assigning this specific lesson
        $lesson->update([
            'instructor_id' => $data['instructor_id'],
            'start_time'    => $data['start_time'],
            'end_time'      => $data['end_time'],
            'status'        => 'scheduled',
        ]);

        return response()->json(['message' => 'Session assigned.', 'lesson' => $lesson->only(['id', 'instructor_id', 'start_time', 'end_time', 'status'])]);
    }

    /* =============================================================
     |  PATCH /api/v1/admin/schedule/{teacherId}/lessons/{lessonId}/cancel
     |  Cancel a session
     | ============================================================= */
    public function cancelLesson(Request $request, int $teacherId, int $lessonId): JsonResponse
    {
        $lesson = \App\Models\Lesson::findOrFail($lessonId);

        if ($lesson->status === 'cancelled') {
            return response()->json(['message' => 'Session is already cancelled.'], 422);
        }

        $lesson->update(['status' => 'cancelled']);

        // Detach all enrolled children
        $lesson->children()->detach();

        return response()->json([
            'message' => 'Session cancelled successfully.',
            'lesson'  => $lesson->only(['id', 'status']),
        ]);
    }

    /**
     * Check if a teacher already has a session at the given time.
     * Returns: null (no conflict), JsonResponse (hard block), or the existing Lesson (joinable group).
     *
     * @param int|null $serviceId  If provided and a group session for this service exists with capacity, returns the lesson instead of blocking
     */
    private function checkDoubleBooking(int $instructorId, string $startTime, string $endTime, ?int $excludeLessonId = null, ?int $serviceId = null): null|JsonResponse|\App\Models\Lesson
    {
        $query = \App\Models\Lesson::where('instructor_id', $instructorId)
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime);

        if ($excludeLessonId) {
            $query->where('id', '!=', $excludeLessonId);
        }

        $conflicts = $query->withCount('children as participants_count')->get();
        if ($conflicts->isEmpty()) return null;

        // Check if any conflict is a joinable session for the same service
        if ($serviceId) {
            foreach ($conflicts as $conflict) {
                if ((int) $conflict->service_id === (int) $serviceId) {
                    $maxP = $conflict->max_participants ?? $conflict->service?->max_participants;
                    // Group session with capacity — return it so caller can add child
                    if ($maxP && $maxP > 1 && $conflict->participants_count < $maxP) {
                        return $conflict;
                    }
                    // 1:1 for same service at same time — this IS a conflict
                    // (don't fall through to generic error, give specific message)
                    return response()->json([
                        'message' => "A session for this service already exists at this time (\"{$conflict->title}\"). You can assign the child to the existing session instead.",
                    ], 422);
                }
            }
        }

        // Hard conflict — block
        $conflict = $conflicts->first();
        $conflictTime = $conflict->start_time?->format('H:i') . ' – ' . $conflict->end_time?->format('H:i');
        return response()->json([
            'message' => "This teacher already has a session at {$conflictTime} (\"{$conflict->title}\"). Choose a different time or cancel the existing session first.",
        ], 422);
    }

    /* =============================================================
     |  POST /api/v1/admin/schedule/{teacherId}/sessions
     |  Quick-create a session (lesson) directly from the calendar
     | ============================================================= */
    public function createSession(Request $request, int $teacherId): JsonResponse
    {
        $data = $request->validate([
            'service_id'       => 'nullable|integer|exists:services,id',
            'start_time'       => 'required|date',
            'end_time'         => 'required|date|after:start_time',
            'title'            => 'required|string|max:255',
            'description'      => 'nullable|string',
            'lesson_type'      => 'nullable|in:1:1,group',
            'lesson_mode'      => 'required|in:online,in_person',
            'max_participants' => 'nullable|integer|min:1',
            'address'          => 'nullable|string|max:500',
            'meeting_link'     => 'nullable|string|max:500',
        ]);

        $service = $data['service_id'] ? \App\Models\Service::find($data['service_id']) : null;

        // Double-booking check — may return an existing joinable group lesson
        $dbCheck = $this->checkDoubleBooking($teacherId, $data['start_time'], $data['end_time'], null, $data['service_id'] ?? null);
        if ($dbCheck instanceof JsonResponse) return $dbCheck;
        if ($dbCheck instanceof \App\Models\Lesson) {
            // Existing group session with capacity — return it instead of creating a new one
            return response()->json([
                'message' => 'Existing group session found — child can be added to it.',
                'lesson'  => $dbCheck->only(['id', 'instructor_id', 'service_id', 'start_time', 'end_time', 'status', 'title', 'lesson_type']),
                'existing' => true,
            ], 200);
        }

        $lesson = \App\Models\Lesson::create([
            'instructor_id'    => $teacherId,
            'service_id'       => $data['service_id'] ?? null,
            'start_time'       => $data['start_time'],
            'end_time'         => $data['end_time'],
            'title'            => $data['title'],
            'description'      => $data['description'] ?? null,
            'status'           => 'scheduled',
            'lesson_type'      => $data['lesson_type'] ?? (($service?->max_participants ?? 1) > 1 ? 'group' : '1:1'),
            'lesson_mode'      => $data['lesson_mode'],
            'max_participants'  => $data['max_participants'] ?? $service?->max_participants ?? null,
            'address'          => $data['address'] ?? null,
            'meeting_link'     => $data['meeting_link'] ?? null,
            'organization_id'  => $request->user()->organization_id ?? null,
        ]);

        return response()->json([
            'message' => 'Session created.',
            'lesson'  => $lesson->only(['id', 'instructor_id', 'service_id', 'start_time', 'end_time', 'status', 'title', 'lesson_type']),
        ], 201);
    }

    /* =============================================================
     |  POST /api/v1/admin/schedule/{teacherId}/sessions/{lessonId}/enrol
     |  Enrol a child into a session — creates transaction + access records
     | ============================================================= */
    public function enrolStudent(Request $request, int $teacherId, int $lessonId): JsonResponse
    {
        $data = $request->validate([
            'child_id' => 'required|integer|exists:children,id',
        ]);

        $lesson = \App\Models\Lesson::with('service')->findOrFail($lessonId);
        $child = \App\Models\Child::with('user')->findOrFail($data['child_id']);
        $parent = $child->user;
        $service = $lesson->service;

        // Check not already enrolled
        if ($lesson->children()->where('child_id', $child->id)->exists()) {
            return response()->json(['message' => 'Child is already enrolled in this session.'], 422);
        }

        // Check capacity
        $currentCount = $lesson->children()->count();
        $maxP = $lesson->max_participants ?? $service?->max_participants;
        if ($maxP && $currentCount >= $maxP) {
            return response()->json(['message' => 'Session is at full capacity.'], 422);
        }

        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            $price = $service?->price ?? 0;
            $transaction = null;
            $messages = [];

            // 1. Create transaction (mirrors checkout flow)
            if ($parent) {
                $transaction = \App\Models\Transaction::create([
                    'organization_id' => $lesson->organization_id ?? $parent->organization_id ?? null,
                    'user_id'         => $parent->id,
                    'user_email'      => $parent->email,
                    'type'            => 'purchase',
                    'status'          => 'completed',
                    'payment_method'  => 'manual',
                    'subtotal'        => $price,
                    'discount'        => 0,
                    'tax'             => 0,
                    'total'           => $price,
                    'paid_at'         => now(),
                    'comment'         => 'Admin-initiated booking via scheduler',
                    'meta'            => json_encode(['admin_user_id' => $request->user()?->id, 'lesson_id' => $lesson->id, 'child_id' => $child->id]),
                ]);

                if ($service) {
                    $transaction->items()->create([
                        'item_type'  => \App\Models\Service::class,
                        'item_id'    => $service->id,
                        'description' => $service->service_name . ' — ' . ($lesson->title ?? 'Session'),
                        'qty'        => 1,
                        'unit_price' => $price,
                        'line_total' => $price,
                    ]);
                }
                if ($price > 0) $messages[] = "Transaction of £{$price} created";
            }

            // 2. Enrol child into the lesson
            $lesson->children()->attach($child->id);
            $messages[] = "{$child->child_name} enrolled";

            // 3. Handle service-specific access (mirrors CheckoutController::grantAccessForTransaction)
            if ($service && $parent) {
                $transactionId = $transaction?->id;

                // A) Credit-based services — grant credits
                if ($service->credits_per_purchase > 0) {
                    \App\Models\ServiceCredit::create([
                        'child_id'       => $child->id,
                        'service_id'     => $service->id,
                        'organization_id' => $service->organization_id,
                        'total_credits'  => $service->credits_per_purchase,
                        'used_credits'   => 1, // 1 credit used for this session
                        'transaction_id' => $transactionId,
                        'expires_at'     => $service->end_datetime,
                    ]);
                    $messages[] = "{$service->credits_per_purchase} credits granted (1 used)";
                }

                // B) Gather all lesson + assessment IDs from service pivots for access
                $lessonIds = [$lesson->id];
                $assessmentIds = [];

                // Add any lessons linked via lesson_service pivot
                $pivotLessonIds = \Illuminate\Support\Facades\DB::table('lesson_service')
                    ->where('service_id', $service->id)
                    ->pluck('lesson_id')
                    ->all();
                if (!empty($pivotLessonIds)) {
                    $lessonIds = array_unique(array_merge($lessonIds, $pivotLessonIds));
                }

                // Add any assessments linked via assessment_service pivot
                $pivotAssessmentIds = \Illuminate\Support\Facades\DB::table('assessment_service')
                    ->where('service_id', $service->id)
                    ->pluck('assessment_id')
                    ->all();
                if (!empty($pivotAssessmentIds)) {
                    $assessmentIds = $pivotAssessmentIds;
                    $messages[] = count($assessmentIds) . ' assessment(s) assigned';
                }

                // C) Create access record
                if (class_exists(\App\Models\Access::class)) {
                    \App\Models\Access::create([
                        'child_id'       => $child->id,
                        'transaction_id' => (string) ($transactionId ?? ''),
                        'invoice_id'     => '',
                        'purchase_date'  => now(),
                        'access'         => true,
                        'lesson_ids'     => array_values(array_map('intval', $lessonIds)),
                        'course_ids'     => $service->course_id ? [$service->course_id] : [],
                        'module_ids'     => [],
                        'assessment_ids' => array_values(array_map('intval', $assessmentIds)),
                        'payment_status' => 'paid',
                    ]);
                    $messages[] = 'Access granted';
                }

                // D) Update enrollment counters on service pivots
                \Illuminate\Support\Facades\DB::table('lesson_service')
                    ->where('service_id', $service->id)
                    ->where('lesson_id', $lesson->id)
                    ->increment('current_enrollments');
            }

            // 4. Decrement service inventory if applicable
            if ($service && $service->quantity_remaining !== null && $service->quantity_remaining > 0) {
                $service->decrement('quantity_remaining');
            }

            \Illuminate\Support\Facades\DB::commit();

            return response()->json([
                'message'     => implode('. ', $messages) . '.',
                'child'       => ['id' => $child->id, 'child_name' => $child->child_name],
                'transaction' => $transaction ? $transaction->only(['id', 'total', 'status']) : null,
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json(['message' => 'Enrolment failed: ' . $e->getMessage()], 500);
        }
    }

    /* =============================================================
     |  POST /api/v1/admin/schedule/{teacherId}/allocations
     |  Create a new schedule allocation (drag-drop from sidebar)
     | ============================================================= */
    public function storeAllocation(Request $request, int $teacherId): JsonResponse
    {
        $request->validate([
            'service_id'       => 'nullable|integer|exists:services,id',
            'service_ids'      => 'nullable|array',
            'service_ids.*'    => 'integer|exists:services,id',
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

        // Normalize service_ids — merge single service_id into array if provided
        $serviceIds = $request->service_ids;
        if (!$serviceIds && $request->service_id) {
            $serviceIds = [(int) $request->service_id];
        }
        $request->merge(['service_ids' => $serviceIds]);

        $profile = TeacherProfile::where('user_id', $teacherId)->first();
        if (!$profile) {
            return response()->json(['message' => 'Teacher profile not found.'], 404);
        }

        try {
            $allocation = $this->allocationService->createAllocation(
                $request->only([
                    'service_id', 'service_ids', 'day_of_week', 'start_time', 'end_time',
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
            'service_ids'      => 'nullable|array',
            'service_ids.*'    => 'integer|exists:services,id',
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
