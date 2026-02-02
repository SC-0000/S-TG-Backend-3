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
    ];

    protected $casts = [
        'qualifications' => 'array',
        'metadata' => 'array',
        'max_hours_per_day' => 'integer',
        'max_hours_per_week' => 'integer',
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
     * TeacherAvailability model will be created (table: teacher_availabilities).
     */
    public function availabilities()
    {
        return $this->hasMany(TeacherAvailability::class, 'teacher_profile_id');
    }

    /**
     * Convenience: check if teacher has any availability on a given day.
     */
    public function hasAvailabilityOnDay(int $dayOfWeek): bool
    {
        return $this->availabilities()->where('day_of_week', $dayOfWeek)->exists();
    }
}
