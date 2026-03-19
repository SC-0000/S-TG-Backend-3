<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeacherProfile extends Model
{
    use HasFactory;

    protected $table = 'teacher_profiles';

    protected $fillable = [
        'user_id',
        'display_name',
        'bio',
        'qualifications',
        'metadata',
        'max_hours_per_day',
        'max_hours_per_week',
        'auto_bookable',
    ];

    protected $casts = [
        'qualifications' => 'array',
        'metadata' => 'array',
        'max_hours_per_day' => 'integer',
        'max_hours_per_week' => 'integer',
        'auto_bookable' => 'boolean',
    ];

    /**
     * The user account this profile belongs to.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Availabilities for this teacher profile.
     */
    public function availabilities()
    {
        return $this->hasMany(TeacherAvailability::class, 'teacher_profile_id');
    }

    /**
     * Availability exceptions (holidays, blocks, overrides).
     */
    public function availabilityExceptions()
    {
        return $this->hasMany(TeacherAvailabilityException::class, 'teacher_profile_id');
    }

    /**
     * Schedule allocations (fixed classes + bookable blocks).
     */
    public function allocations()
    {
        return $this->hasMany(ScheduleAllocation::class, 'teacher_profile_id');
    }

    /**
     * Convenience: check if teacher has any availability on a given day.
     */
    public function hasAvailabilityOnDay(int $dayOfWeek): bool
    {
        return $this->availabilities()->where('day_of_week', $dayOfWeek)->exists();
    }

    /**
     * Get all lessons (booking slots) for this teacher within a date range.
     */
    public function getBookedSlots($dateFrom, $dateTo)
    {
        return Lesson::where('instructor_id', $this->user_id)
            ->whereIn('status', ['scheduled', 'confirmed', 'completed'])
            ->where('start_time', '>=', $dateFrom)
            ->where('end_time', '<=', $dateTo)
            ->get();
    }
}
