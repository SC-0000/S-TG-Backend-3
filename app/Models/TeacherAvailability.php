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
        'notes',
    ];

    protected $casts = [
        'teacher_profile_id' => 'integer',
        'day_of_week' => 'integer',
        'is_recurring' => 'boolean',
    ];

    /**
     * The teacher profile this availability belongs to.
     */
    public function teacherProfile()
    {
        return $this->belongsTo(TeacherProfile::class, 'teacher_profile_id');
    }
}
