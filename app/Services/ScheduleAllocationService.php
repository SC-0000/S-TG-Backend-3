<?php

namespace App\Services;

use App\Models\Lesson;
use App\Models\ScheduleAllocation;
use App\Models\Service;
use App\Models\TeacherAvailability;
use App\Models\TeacherAvailabilityException;
use App\Models\TeacherProfile;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScheduleAllocationService
{
    /* =============================================================
     |  CREATE ALLOCATION
     | ============================================================= */

    /**
     * Create a new schedule allocation after validating constraints.
     *
     * @throws \InvalidArgumentException if validation fails
     */
    public function createAllocation(array $data, TeacherProfile $profile): ScheduleAllocation
    {
        // Validate within working hours
        $this->validateWithinWorkingHours($profile, $data['day_of_week'], $data['start_time'], $data['end_time']);

        // Validate no overlap with existing allocations
        $this->validateNoOverlap($profile, $data['day_of_week'], $data['start_time'], $data['end_time']);

        $allocation = ScheduleAllocation::create([
            'teacher_profile_id' => $profile->id,
            'service_id'         => $data['service_id'] ?? null,
            'service_ids'        => $data['service_ids'] ?? ($data['service_id'] ? [(int) $data['service_id']] : null),
            'day_of_week'        => $data['day_of_week'],
            'start_time'         => $data['start_time'],
            'end_time'           => $data['end_time'],
            'allocation_type'    => $data['allocation_type'] ?? 'bookable',
            'recurrence'         => $data['recurrence'] ?? 'weekly',
            'effective_from'     => $data['effective_from'] ?? null,
            'effective_until'    => $data['effective_until'] ?? null,
            'title'              => $data['title'] ?? null,
            'max_participants'   => $data['max_participants'] ?? null,
            'organization_id'    => $data['organization_id'] ?? $profile->user?->organizations()?->first()?->id,
        ]);

        // "Fixed" allocations are now "reserved for service" slots — availability only, no auto-generated lessons
        // Sessions are created directly via the "Create Session" flow instead

        return $allocation->load('service');
    }

    /* =============================================================
     |  UPDATE ALLOCATION (move/resize)
     | ============================================================= */

    public function updateAllocation(ScheduleAllocation $allocation, array $data): ScheduleAllocation
    {
        $profile = $allocation->teacherProfile;
        $dayOfWeek = $data['day_of_week'] ?? $allocation->day_of_week;
        $startTime = $data['start_time'] ?? $allocation->start_time;
        $endTime = $data['end_time'] ?? $allocation->end_time;

        $this->validateWithinWorkingHours($profile, $dayOfWeek, $startTime, $endTime);
        $this->validateNoOverlap($profile, $dayOfWeek, $startTime, $endTime, $allocation->id);

        // Build update data explicitly — null values must be preserved to clear fields
        $updateData = [];
        $fields = ['day_of_week', 'start_time', 'end_time', 'allocation_type', 'recurrence',
                    'effective_from', 'effective_until', 'title', 'max_participants',
                    'service_id', 'service_ids'];

        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $data[$field];
            }
        }

        $allocation->update($updateData);

        return $allocation->fresh()->load('service');
    }

    /* =============================================================
     |  DELETE ALLOCATION
     | ============================================================= */

    public function deleteAllocation(ScheduleAllocation $allocation, bool $cancelFutureLessons = false): void
    {
        if ($cancelFutureLessons) {
            // Cancel future lessons generated from this allocation
            Lesson::where('allocation_id', $allocation->id)
                ->where('start_time', '>', now())
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->update(['status' => 'cancelled']);
        }

        $allocation->delete();
    }

    /* =============================================================
     |  LESSON GENERATION
     | ============================================================= */

    /**
     * Generate Lesson records from a fixed allocation.
     * If effective_until is set, generates ALL up to that date.
     * Otherwise, generates for the next $weeksAhead weeks.
     */
    public function generateLessonsForAllocation(
        ScheduleAllocation $allocation,
        int $weeksAhead = 4,
        ?Carbon $customFrom = null,
        ?Carbon $customUntil = null
    ): Collection {
        if (!$allocation->isFixed()) {
            return collect();
        }

        $profile = $allocation->teacherProfile;
        $service = $allocation->service;
        $teacherId = $profile->user_id;

        $from = $customFrom ?? Carbon::today();
        if ($allocation->effective_from && $allocation->effective_from->gt($from)) {
            $from = $allocation->effective_from->copy();
        }

        // Determine end date
        if ($customUntil) {
            $until = $customUntil;
        } elseif ($allocation->effective_until) {
            $until = $allocation->effective_until->copy();
        } else {
            $until = $from->copy()->addWeeks($weeksAhead);
        }

        // Get exceptions for the range
        $exceptions = $profile->availabilityExceptions()
            ->where('date', '>=', $from)
            ->where('date', '<=', $until)
            ->where('type', 'unavailable')
            ->get();

        $created = collect();
        $period = CarbonPeriod::create($from, $until);

        foreach ($period as $date) {
            if (!$allocation->isActiveOn($date)) {
                continue;
            }

            // Check for whole-day exceptions
            $dayExceptions = $exceptions->where('date', $date->format('Y-m-d'));
            $isWholeDayBlocked = $dayExceptions->contains(fn ($e) => $e->isWholeDay());
            if ($isWholeDayBlocked) {
                continue;
            }

            // Check for time-specific exceptions blocking this slot
            $isTimeBlocked = $dayExceptions->contains(function ($e) use ($allocation) {
                if ($e->isWholeDay()) return true;
                return Carbon::parse($e->start_time)->lt(Carbon::parse($allocation->end_time))
                    && Carbon::parse($e->end_time)->gt(Carbon::parse($allocation->start_time));
            });
            if ($isTimeBlocked) {
                continue;
            }

            $startTime = $date->copy()->setTimeFromTimeString($allocation->start_time);
            $endTime = $date->copy()->setTimeFromTimeString($allocation->end_time);

            // Skip past times
            if ($startTime->lt(now())) {
                continue;
            }

            // Check if lesson already exists for this allocation + date
            $exists = Lesson::where('allocation_id', $allocation->id)
                ->whereDate('start_time', $date)
                ->exists();

            if ($exists) {
                continue;
            }

            $lesson = Lesson::create([
                'title'           => $allocation->display_title,
                'lesson_type'     => ($allocation->max_participants ?? $service?->max_participants ?? 1) > 1 ? 'group' : '1:1',
                'lesson_mode'     => 'online',
                'start_time'      => $startTime,
                'end_time'        => $endTime,
                'instructor_id'   => $teacherId,
                'service_id'      => $allocation->service_id,
                'allocation_id'   => $allocation->id,
                'status'          => 'scheduled',
                'organization_id' => $allocation->organization_id,
            ]);

            $created->push($lesson);
        }

        return $created;
    }

    /**
     * Extend lesson generation for all open-ended fixed allocations of a teacher.
     * Called by cron job weekly.
     */
    public function extendLessonsForTeacher(TeacherProfile $profile, int $weeksAhead = 1): int
    {
        $count = 0;
        $allocations = $profile->allocations()
            ->fixed()
            ->whereNull('effective_until')
            ->get();

        foreach ($allocations as $allocation) {
            $generated = $this->generateLessonsForAllocation($allocation, $weeksAhead);
            $count += $generated->count();
        }

        return $count;
    }

    /* =============================================================
     |  GAP CALCULATION
     | ============================================================= */

    /**
     * Calculate unallocated working time for a teacher on a given day.
     * Returns array of gap time ranges.
     */
    public function calculateGaps(TeacherProfile $profile, int $dayOfWeek): array
    {
        // Get working hours for this day
        $workingHours = $profile->availabilities()
            ->where('day_of_week', $dayOfWeek)
            ->where('is_recurring', true)
            ->orderBy('start_time')
            ->get();

        if ($workingHours->isEmpty()) {
            return [];
        }

        // Get allocations for this day
        $allocations = $profile->allocations()
            ->onDay($dayOfWeek)
            ->orderBy('start_time')
            ->get();

        $gaps = [];

        foreach ($workingHours as $wh) {
            $whStart = Carbon::parse($wh->start_time);
            $whEnd = Carbon::parse($wh->end_time);

            // Get allocations that overlap with this working hour block
            $overlapping = $allocations->filter(function ($a) use ($whStart, $whEnd) {
                $aStart = Carbon::parse($a->start_time);
                $aEnd = Carbon::parse($a->end_time);
                return $aStart->lt($whEnd) && $aEnd->gt($whStart);
            })->sortBy('start_time')->values();

            if ($overlapping->isEmpty()) {
                $gaps[] = [
                    'start_time' => $wh->start_time,
                    'end_time'   => $wh->end_time,
                    'duration_minutes' => $whStart->diffInMinutes($whEnd),
                ];
                continue;
            }

            // Walk through the working hours, finding gaps between allocations
            $cursor = $whStart->copy();
            foreach ($overlapping as $a) {
                $aStart = Carbon::parse($a->start_time);
                $aEnd = Carbon::parse($a->end_time);

                if ($cursor->lt($aStart)) {
                    $gaps[] = [
                        'start_time' => $cursor->format('H:i'),
                        'end_time'   => $aStart->format('H:i'),
                        'duration_minutes' => $cursor->diffInMinutes($aStart),
                    ];
                }
                $cursor = $aEnd->gt($cursor) ? $aEnd->copy() : $cursor;
            }

            // Gap after last allocation
            if ($cursor->lt($whEnd)) {
                $gaps[] = [
                    'start_time' => $cursor->format('H:i'),
                    'end_time'   => $whEnd->format('H:i'),
                    'duration_minutes' => $cursor->diffInMinutes($whEnd),
                ];
            }
        }

        return $gaps;
    }

    /**
     * Calculate all gaps for all days of the week.
     */
    public function calculateAllGaps(TeacherProfile $profile): array
    {
        $allGaps = [];
        for ($day = 1; $day <= 7; $day++) {
            $gaps = $this->calculateGaps($profile, $day);
            if (!empty($gaps)) {
                $allGaps[$day] = $gaps;
            }
        }
        return $allGaps;
    }

    /**
     * Suggest optimal placement for a service within gaps.
     * Returns the first gap that fits the service duration.
     */
    public function suggestBestFit(TeacherProfile $profile, int $dayOfWeek, int $durationMinutes): ?array
    {
        $gaps = $this->calculateGaps($profile, $dayOfWeek);

        foreach ($gaps as $gap) {
            if ($gap['duration_minutes'] >= $durationMinutes) {
                return [
                    'start_time' => $gap['start_time'],
                    'end_time'   => Carbon::parse($gap['start_time'])->addMinutes($durationMinutes)->format('H:i'),
                    'gap'        => $gap,
                ];
            }
        }

        return null;
    }

    /* =============================================================
     |  VALIDATION
     | ============================================================= */

    /**
     * Validate that the time range falls within the teacher's working hours.
     */
    private function validateWithinWorkingHours(TeacherProfile $profile, int $dayOfWeek, string $startTime, string $endTime): void
    {
        // Working hours validation is advisory, not blocking
        // Admins can create allocations outside working hours if needed
        // The calendar UI shows working hours as background info only
    }

    /**
     * Validate that the time range does not overlap with existing allocations.
     */
    private function validateNoOverlap(TeacherProfile $profile, int $dayOfWeek, string $startTime, string $endTime, ?int $excludeId = null): void
    {
        $query = $profile->allocations()
            ->where('day_of_week', $dayOfWeek)
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw new \InvalidArgumentException("This time overlaps with an existing allocation.");
        }
    }

    /* =============================================================
     |  UNIFIED SCHEDULE VIEW
     | ============================================================= */

    /**
     * Get the complete schedule view for a teacher.
     * Returns all layers in one response.
     */
    public function getUnifiedSchedule(int $teacherId, Carbon $dateFrom, Carbon $dateTo): array
    {
        $profile = TeacherProfile::where('user_id', $teacherId)->first();
        $teacher = User::find($teacherId);

        if (!$profile || !$teacher) {
            return [
                'teacher'        => null,
                'working_hours'  => [],
                'exceptions'     => [],
                'allocations'    => [],
                'lessons'        => [],
                'bookable_gaps'  => [],
                'stats'          => ['booked_hours' => 0, 'allocated_hours' => 0, 'open_hours' => 0, 'utilization' => 0],
            ];
        }

        // Layer 1: Working hours
        $workingHours = $profile->availabilities()
            ->where('is_recurring', true)
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        // Exceptions
        $exceptions = $profile->availabilityExceptions()
            ->where('date', '>=', $dateFrom->copy()->subDays(7))
            ->where('date', '<=', $dateTo)
            ->orderBy('date')
            ->get();

        // Layer 2: Allocations
        $allocations = $profile->allocations()
            ->with('service:id,service_name,max_participants,session_duration_minutes,credits_per_purchase,booking_mode')
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get()
            ->map(function ($a) {
                // Resolve service names from service_ids array
                $serviceIds = $a->service_ids ?? ($a->service_id ? [$a->service_id] : []);
                $serviceNames = [];
                if (!empty($serviceIds)) {
                    $serviceNames = \App\Models\Service::whereIn('id', $serviceIds)
                        ->pluck('service_name', 'id')
                        ->all();
                }

                return [
                    'id'              => $a->id,
                    'service_id'      => $a->service_id,
                    'service_ids'     => $serviceIds,
                    'service_name'    => $a->service?->service_name ?? $a->title ?? 'Open',
                    'service_names'   => $serviceNames,
                    'day_of_week'     => $a->day_of_week,
                    'start_time'      => $a->start_time,
                    'end_time'        => $a->end_time,
                    'allocation_type' => $a->allocation_type,
                    'recurrence'      => $a->recurrence,
                    'effective_from'  => $a->effective_from?->format('Y-m-d'),
                    'effective_until' => $a->effective_until?->format('Y-m-d'),
                    'title'           => $a->display_title,
                    'max_participants' => $a->max_participants ?? $a->service?->max_participants,
                    'duration_minutes' => $a->duration_minutes,
                ];
            });

        // Layer 3: Booked lessons
        $lessons = Lesson::where('instructor_id', $teacherId)
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->where('start_time', '>=', $dateFrom)
            ->where('start_time', '<=', $dateTo)
            ->with(['children:id,child_name,user_id', 'children.user:id,name', 'service:id,service_name,max_participants,credits_per_purchase'])
            ->withCount('children as participants_count')
            ->get()
            ->map(function ($l) {
                $parentNames = $l->children
                    ->map(fn ($child) => $child->user?->name)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                return [
                    'id'                => $l->id,
                    'allocation_id'     => $l->allocation_id,
                    'start_time'        => $l->start_time->toIso8601String(),
                    'end_time'          => $l->end_time->toIso8601String(),
                    'status'            => $l->status,
                    'lesson_type'       => $l->lesson_type,
                    'lesson_mode'       => $l->lesson_mode,
                    'participants_count' => $l->participants_count,
                    'max_participants'  => $l->service?->max_participants,
                    'student_name'      => $l->children->pluck('child_name')->join(', ') ?: null,
                    'parent_names'      => $parentNames,
                    'service_name'      => $l->service?->service_name ?? $l->title,
                    'service_id'        => $l->service_id,
                    'is_credit_based'   => (bool) $l->service?->credits_per_purchase,
                    'title'             => $l->title,
                ];
            });

        // Gaps (unallocated working time)
        $allGaps = $this->calculateAllGaps($profile);

        // Stats
        $totalBookedMins = $lessons->sum(function ($l) {
            return Carbon::parse($l['start_time'])->diffInMinutes(Carbon::parse($l['end_time']));
        });
        $totalAllocatedMins = $allocations->sum('duration_minutes') * 1; // per week
        $totalWorkingMins = $workingHours->sum(function ($wh) {
            return Carbon::parse($wh->start_time)->diffInMinutes(Carbon::parse($wh->end_time));
        });
        $totalOpenMins = collect($allGaps)->flatten(1)->sum('duration_minutes');

        // Resolve avatar
        $teacherRecord = \App\Models\Teacher::where('user_id', $teacherId)->first();
        $avatarUrl = null;
        if ($teacherRecord?->image_path) {
            $avatarUrl = '/storage/' . $teacherRecord->image_path;
        } elseif ($teacher->avatar_path) {
            $avatarUrl = '/storage/' . $teacher->avatar_path;
        }

        return [
            'teacher' => [
                'id'           => $teacher->id,
                'name'         => $teacher->name,
                'email'        => $teacher->email,
                'avatar_url'   => $avatarUrl,
                'auto_bookable' => $profile->auto_bookable,
            ],
            'profile' => [
                'id'                => $profile->id,
                'max_hours_per_day' => $profile->max_hours_per_day,
                'max_hours_per_week' => $profile->max_hours_per_week,
                'auto_bookable'     => $profile->auto_bookable,
            ],
            'working_hours'  => $workingHours,
            'exceptions'     => $exceptions,
            'allocations'    => $allocations,
            'lessons'        => $lessons,
            'bookable_gaps'  => $allGaps,
            'stats'          => [
                'booked_hours'    => round($totalBookedMins / 60, 1),
                'allocated_hours' => round($totalAllocatedMins / 60, 1),
                'open_hours'      => round($totalOpenMins / 60, 1),
                'working_hours'   => round($totalWorkingMins / 60, 1),
                'utilization'     => $totalWorkingMins > 0
                    ? round(($totalBookedMins / $totalWorkingMins) * 100, 1)
                    : 0,
            ],
        ];
    }
}
