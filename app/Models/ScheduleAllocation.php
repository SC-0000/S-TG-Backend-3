<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduleAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'teacher_profile_id',
        'service_id',
        'service_ids',
        'day_of_week',
        'start_time',
        'end_time',
        'allocation_type',
        'recurrence',
        'effective_from',
        'effective_until',
        'title',
        'max_participants',
        'organization_id',
    ];

    protected $casts = [
        'day_of_week'      => 'integer',
        'max_participants'  => 'integer',
        'effective_from'    => 'date',
        'effective_until'   => 'date',
        'service_ids'      => 'array',
    ];

    /* -----------------------------------------------------------
     |  Relationships
     |----------------------------------------------------------- */

    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Lessons generated from this allocation template. */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class, 'allocation_id');
    }

    /* -----------------------------------------------------------
     |  Helpers
     |----------------------------------------------------------- */

    public function isFixed(): bool
    {
        return $this->allocation_type === 'fixed';
    }

    public function isBookable(): bool
    {
        return $this->allocation_type === 'bookable';
    }

    public function isRecurring(): bool
    {
        return $this->recurrence !== null;
    }

    /** Check if this allocation is active on a given date. */
    public function isActiveOn(\Carbon\Carbon $date): bool
    {
        if ($this->effective_from && $date->lt($this->effective_from)) {
            return false;
        }
        if ($this->effective_until && $date->gt($this->effective_until)) {
            return false;
        }

        // Check day of week matches
        $dateDow = $date->dayOfWeekIso; // 1=Mon ... 7=Sun
        if ($this->day_of_week !== $dateDow) {
            return false;
        }

        // Check recurrence
        if ($this->recurrence === 'biweekly' && $this->effective_from) {
            $weeksDiff = $this->effective_from->diffInWeeks($date);
            if ($weeksDiff % 2 !== 0) {
                return false;
            }
        }

        return true;
    }

    /** Get the display title (uses service name if no override). */
    public function getDisplayTitleAttribute(): string
    {
        return $this->title ?? $this->service?->service_name ?? 'Untitled';
    }

    /** Duration in minutes. */
    public function getDurationMinutesAttribute(): int
    {
        $start = \Carbon\Carbon::parse($this->start_time);
        $end = \Carbon\Carbon::parse($this->end_time);
        return $start->diffInMinutes($end);
    }

    /** Check if this allocation overlaps with a time range on the same day. */
    public function overlaps(string $startTime, string $endTime): bool
    {
        $thisStart = \Carbon\Carbon::parse($this->start_time);
        $thisEnd = \Carbon\Carbon::parse($this->end_time);
        $otherStart = \Carbon\Carbon::parse($startTime);
        $otherEnd = \Carbon\Carbon::parse($endTime);

        return $thisStart->lt($otherEnd) && $thisEnd->gt($otherStart);
    }

    /* -----------------------------------------------------------
     |  Scopes
     |----------------------------------------------------------- */

    public function scopeForTeacher($query, int $teacherProfileId)
    {
        return $query->where('teacher_profile_id', $teacherProfileId);
    }

    public function scopeFixed($query)
    {
        return $query->where('allocation_type', 'fixed');
    }

    public function scopeBookable($query)
    {
        return $query->where('allocation_type', 'bookable');
    }

    public function scopeActiveOn($query, \Carbon\Carbon $date)
    {
        return $query->where('day_of_week', $date->dayOfWeekIso)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date);
            })
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date);
            });
    }

    public function scopeOnDay($query, int $dayOfWeek)
    {
        return $query->where('day_of_week', $dayOfWeek);
    }
}
