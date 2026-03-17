<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeacherAvailability extends Model
{
    use HasFactory;

    protected $table = 'teacher_availabilities';

    protected $fillable = [
        'teacher_profile_id',
        'day_of_week',
        'start_time',
        'end_time',
        'is_recurring',
        'effective_from',
        'effective_until',
        'slot_duration_minutes',
        'buffer_minutes',
        'notes',
    ];

    protected $casts = [
        'teacher_profile_id'    => 'integer',
        'day_of_week'           => 'integer',
        'is_recurring'          => 'boolean',
        'effective_from'        => 'date',
        'effective_until'       => 'date',
        'slot_duration_minutes' => 'integer',
        'buffer_minutes'        => 'integer',
    ];

    /**
     * The teacher profile this availability belongs to.
     */
    public function teacherProfile()
    {
        return $this->belongsTo(TeacherProfile::class, 'teacher_profile_id');
    }

    /**
     * Check if this availability is active on a given date.
     */
    public function isActiveOn(\Carbon\Carbon $date): bool
    {
        if ($this->effective_from && $date->lt($this->effective_from)) {
            return false;
        }
        if ($this->effective_until && $date->gt($this->effective_until)) {
            return false;
        }
        return true;
    }

    /**
     * Scope to active availabilities for a given date.
     */
    public function scopeActiveOn($query, $date)
    {
        return $query->where(function ($q) use ($date) {
            $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date);
        })->where(function ($q) use ($date) {
            $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date);
        });
    }
}
