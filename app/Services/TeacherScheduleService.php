<?php

namespace App\Services;

use App\Models\Lesson;
use App\Models\ScheduleAllocation;
use App\Models\TeacherAvailability;
use App\Models\TeacherAvailabilityException;
use App\Models\TeacherProfile;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

class TeacherScheduleService
{
    /**
     * Get available booking slots for a teacher within a date range.
     *
     * @param  int         $teacherId       User ID of the teacher
     * @param  Carbon      $dateFrom        Start of range
     * @param  Carbon      $dateTo          End of range
     * @param  int         $durationMinutes Desired slot duration
     * @param  int|null    $organizationId  Optional org scope
     * @return Collection  Collection of available slot arrays [{start, end, teacher_id, teacher_name}]
     */
    public function getAvailableSlots(
        int $teacherId,
        Carbon $dateFrom,
        Carbon $dateTo,
        int $durationMinutes = 60,
        ?int $organizationId = null
    ): Collection {
        $profile = TeacherProfile::where('user_id', $teacherId)->first();
        if (!$profile) {
            return collect();
        }

        $teacher = User::find($teacherId);
        if (!$teacher) {
            return collect();
        }

        // Get recurring availabilities active in range
        $availabilities = $profile->availabilities()
            ->where('is_recurring', true)
            ->get();

        // Get exceptions for the range
        $exceptions = $profile->availabilityExceptions()
            ->forDateRange($dateFrom, $dateTo)
            ->get()
            ->groupBy(fn ($e) => $e->date->toDateString());

        // Get existing booked lessons in range
        $bookedSlots = Lesson::where('instructor_id', $teacherId)
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->where('start_time', '>=', $dateFrom)
            ->where('start_time', '<=', $dateTo)
            ->select('start_time', 'end_time')
            ->get();

        // Get fixed allocations (these block time for specific services, not open for booking)
        $fixedAllocations = $profile->allocations()
            ->where('allocation_type', 'fixed')
            ->get();

        $maxPerDay = $profile->max_hours_per_day ?? 8;
        $maxPerWeek = $profile->max_hours_per_week ?? 40;

        // Calculate hours already booked per day and per week
        $bookedHoursPerDay = [];
        $bookedHoursPerWeek = [];
        foreach ($bookedSlots as $slot) {
            $dayKey = Carbon::parse($slot->start_time)->toDateString();
            $weekKey = Carbon::parse($slot->start_time)->startOfWeek()->toDateString();
            $hours = Carbon::parse($slot->start_time)->diffInMinutes(Carbon::parse($slot->end_time)) / 60;

            $bookedHoursPerDay[$dayKey] = ($bookedHoursPerDay[$dayKey] ?? 0) + $hours;
            $bookedHoursPerWeek[$weekKey] = ($bookedHoursPerWeek[$weekKey] ?? 0) + $hours;
        }

        $slots = collect();
        $period = CarbonPeriod::create($dateFrom->copy()->startOfDay(), $dateTo->copy()->startOfDay());

        foreach ($period as $date) {
            $dayOfWeek = $date->dayOfWeek; // 0=Sunday, 6=Saturday
            $dateStr = $date->toDateString();
            $weekStr = $date->copy()->startOfWeek()->toDateString();

            // Check day exceptions
            $dayExceptions = $exceptions->get($dateStr, collect());

            // Check if whole day is blocked
            $wholeDayBlocked = $dayExceptions->contains(function ($ex) {
                return $ex->type === 'unavailable' && $ex->isWholeDay();
            });
            if ($wholeDayBlocked) {
                continue;
            }

            // Get availability windows for this day of week
            $dayAvailabilities = $availabilities->filter(function ($a) use ($dayOfWeek, $date) {
                return $a->day_of_week === $dayOfWeek && $a->isActiveOn($date);
            });

            // Also check for override exceptions (one-off availability)
            $overrides = $dayExceptions->where('type', 'override');
            if ($overrides->isNotEmpty()) {
                foreach ($overrides as $override) {
                    $dayAvailabilities->push((object)[
                        'start_time' => $override->start_time,
                        'end_time'   => $override->end_time,
                        'buffer_minutes' => 0,
                    ]);
                }
            }

            if ($dayAvailabilities->isEmpty()) {
                continue;
            }

            // Check daily hour cap
            $dayBookedHours = $bookedHoursPerDay[$dateStr] ?? 0;
            $weekBookedHours = $bookedHoursPerWeek[$weekStr] ?? 0;
            $slotHours = $durationMinutes / 60;

            foreach ($dayAvailabilities as $availability) {
                $windowStart = $date->copy()->setTimeFromTimeString($availability->start_time);
                $windowEnd = $date->copy()->setTimeFromTimeString($availability->end_time);
                $buffer = $availability->buffer_minutes ?? 0;

                // Skip past times
                if ($windowEnd->lte(now())) {
                    continue;
                }
                if ($windowStart->lt(now())) {
                    // Round up to nearest slot boundary
                    $minutesPassed = now()->diffInMinutes($windowStart);
                    $slotsSkipped = ceil($minutesPassed / ($durationMinutes + $buffer));
                    $windowStart->addMinutes($slotsSkipped * ($durationMinutes + $buffer));
                }

                // Remove unavailable time blocks from window
                $unavailableBlocks = $dayExceptions->where('type', 'unavailable')
                    ->filter(fn ($ex) => !$ex->isWholeDay());

                // Generate slots
                $cursor = $windowStart->copy();
                while ($cursor->copy()->addMinutes($durationMinutes)->lte($windowEnd)) {
                    $slotStart = $cursor->copy();
                    $slotEnd = $cursor->copy()->addMinutes($durationMinutes);

                    // Check against unavailable blocks
                    $blocked = $unavailableBlocks->contains(function ($block) use ($slotStart, $slotEnd, $date) {
                        $blockStart = $date->copy()->setTimeFromTimeString($block->start_time);
                        $blockEnd = $date->copy()->setTimeFromTimeString($block->end_time);
                        return $slotStart->lt($blockEnd) && $slotEnd->gt($blockStart);
                    });

                    if ($blocked) {
                        $cursor->addMinutes($durationMinutes + $buffer);
                        continue;
                    }

                    // Check against already booked slots
                    $overlaps = $bookedSlots->contains(function ($booked) use ($slotStart, $slotEnd) {
                        $bStart = Carbon::parse($booked->start_time);
                        $bEnd = Carbon::parse($booked->end_time);
                        return $slotStart->lt($bEnd) && $slotEnd->gt($bStart);
                    });

                    if ($overlaps) {
                        $cursor->addMinutes($durationMinutes + $buffer);
                        continue;
                    }

                    // Check against fixed allocations (these reserve time for specific services)
                    $fixedBlock = $fixedAllocations->contains(function ($alloc) use ($slotStart, $slotEnd, $date) {
                        if (!$alloc->isActiveOn($date)) return false;
                        $aStart = $date->copy()->setTimeFromTimeString($alloc->start_time);
                        $aEnd = $date->copy()->setTimeFromTimeString($alloc->end_time);
                        return $slotStart->lt($aEnd) && $slotEnd->gt($aStart);
                    });

                    if ($fixedBlock) {
                        $cursor->addMinutes($durationMinutes + $buffer);
                        continue;
                    }

                    // Check hour caps
                    if (($dayBookedHours + $slotHours) > $maxPerDay) {
                        break; // No more slots this day
                    }
                    if (($weekBookedHours + $slotHours) > $maxPerWeek) {
                        break; // No more slots this week
                    }

                    $slots->push([
                        'start'        => $slotStart->toIso8601String(),
                        'end'          => $slotEnd->toIso8601String(),
                        'date'         => $dateStr,
                        'teacher_id'   => $teacherId,
                        'teacher_name' => $teacher->name,
                    ]);

                    // Track potential usage for cap calculation within this generation
                    $dayBookedHours += $slotHours;
                    $weekBookedHours += $slotHours;

                    $cursor->addMinutes($durationMinutes + $buffer);
                }
            }
        }

        return $slots;
    }

    /**
     * Get available slots for multiple teachers (for a service).
     */
    public function getAvailableSlotsForService(
        array $teacherIds,
        Carbon $dateFrom,
        Carbon $dateTo,
        int $durationMinutes = 60,
        ?int $organizationId = null
    ): Collection {
        $allSlots = collect();

        foreach ($teacherIds as $teacherId) {
            $teacherSlots = $this->getAvailableSlots(
                $teacherId, $dateFrom, $dateTo, $durationMinutes, $organizationId
            );
            $allSlots = $allSlots->merge($teacherSlots);
        }

        return $allSlots->sortBy('start')->values();
    }

    /**
     * Check if a specific slot is available for a teacher.
     */
    public function isSlotAvailable(int $teacherId, Carbon $start, Carbon $end): bool
    {
        // Check for overlapping booked lessons
        $hasConflict = Lesson::where('instructor_id', $teacherId)
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->exists();

        if ($hasConflict) {
            return false;
        }

        // Check teacher has availability covering this slot
        $profile = TeacherProfile::where('user_id', $teacherId)->first();
        if (!$profile) {
            return false;
        }

        $dayOfWeek = $start->dayOfWeek;
        $dateStr = $start->toDateString();
        $slotStartTime = $start->format('H:i:s');
        $slotEndTime = $end->format('H:i:s');

        // Check for whole-day exceptions
        $wholeDayBlock = TeacherAvailabilityException::where('teacher_profile_id', $profile->id)
            ->where('date', $dateStr)
            ->where('type', 'unavailable')
            ->whereNull('start_time')
            ->exists();

        if ($wholeDayBlock) {
            return false;
        }

        // Check for partial time-block exceptions
        $partialBlock = TeacherAvailabilityException::where('teacher_profile_id', $profile->id)
            ->where('date', $dateStr)
            ->where('type', 'unavailable')
            ->whereNotNull('start_time')
            ->where('start_time', '<', $slotEndTime)
            ->where('end_time', '>', $slotStartTime)
            ->exists();

        if ($partialBlock) {
            return false;
        }

        // Check for fixed allocations blocking this slot
        $fixedAllocationBlock = ScheduleAllocation::where('teacher_profile_id', $profile->id)
            ->where('allocation_type', 'fixed')
            ->where('day_of_week', $start->dayOfWeekIso)
            ->where('start_time', '<', $slotEndTime)
            ->where('end_time', '>', $slotStartTime)
            ->where(function ($q) use ($start) {
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', $start->toDateString());
            })
            ->where(function ($q) use ($start) {
                $q->whereNull('effective_until')->orWhere('effective_until', '>=', $start->toDateString());
            })
            ->exists();

        if ($fixedAllocationBlock) {
            return false;
        }

        // Check recurring availability covers this slot
        $hasAvailability = $profile->availabilities()
            ->where('day_of_week', $dayOfWeek)
            ->where('is_recurring', true)
            ->activeOn($start)
            ->where('start_time', '<=', $slotStartTime)
            ->where('end_time', '>=', $slotEndTime)
            ->exists();

        // Also check override exceptions as availability
        if (!$hasAvailability) {
            $hasAvailability = TeacherAvailabilityException::where('teacher_profile_id', $profile->id)
                ->where('date', $dateStr)
                ->where('type', 'override')
                ->where('start_time', '<=', $slotStartTime)
                ->where('end_time', '>=', $slotEndTime)
                ->exists();
        }

        if (!$hasAvailability) {
            return false;
        }

        // Check hour caps
        $dayBookedMinutes = Lesson::where('instructor_id', $teacherId)
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->whereDate('start_time', $dateStr)
            ->get()
            ->sum(fn ($l) => Carbon::parse($l->start_time)->diffInMinutes(Carbon::parse($l->end_time)));

        $slotMinutes = $start->diffInMinutes($end);
        $maxPerDay = ($profile->max_hours_per_day ?? 8) * 60;

        if (($dayBookedMinutes + $slotMinutes) > $maxPerDay) {
            return false;
        }

        return true;
    }

    /**
     * Get a teacher's schedule summary (booked + available) for a date range.
     */
    public function getTeacherSchedule(int $teacherId, Carbon $dateFrom, Carbon $dateTo): array
    {
        $profile = TeacherProfile::where('user_id', $teacherId)->first();

        $bookedSlots = Lesson::where('instructor_id', $teacherId)
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->where('start_time', '>=', $dateFrom)
            ->where('start_time', '<=', $dateTo)
            ->with(['children:id,child_name', 'service:id,service_name,max_participants,credits_per_purchase,booking_mode'])
            ->withCount('children as participants_count')
            ->get()
            ->map(function ($l) {
                $l->service_name = $l->service?->service_name;
                $l->service_id = $l->service?->id;
                $l->max_participants = $l->service?->max_participants;
                $l->is_credit_based = (bool) $l->service?->credits_per_purchase;
                $l->student_name = $l->children->pluck('child_name')->join(', ') ?: 'Student';
                $l->session_type = $l->lesson_type;
                return $l;
            });

        $availableSlots = $this->getAvailableSlots($teacherId, $dateFrom, $dateTo, 60);

        $totalBookedMinutes = $bookedSlots->sum(function ($l) {
            return Carbon::parse($l->start_time)->diffInMinutes(Carbon::parse($l->end_time));
        });

        $totalAvailableMinutes = $availableSlots->count() * 60;

        return [
            'booked_slots'     => $bookedSlots,
            'available_slots'  => $availableSlots,
            'booked_hours'     => round($totalBookedMinutes / 60, 1),
            'available_hours'  => round($totalAvailableMinutes / 60, 1),
            'utilization_rate' => $totalAvailableMinutes > 0
                ? round(($totalBookedMinutes / ($totalBookedMinutes + $totalAvailableMinutes)) * 100, 1)
                : 0,
            'max_hours_per_day'  => $profile?->max_hours_per_day ?? 8,
            'max_hours_per_week' => $profile?->max_hours_per_week ?? 40,
        ];
    }
}
