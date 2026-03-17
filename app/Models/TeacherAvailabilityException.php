<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeacherAvailabilityException extends Model
{
    use HasFactory;

    protected $fillable = [
        'teacher_profile_id',
        'date',
        'start_time',
        'end_time',
        'type',     // unavailable | override
        'reason',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    /* -----------------------------------------------------------
     |  Relationships
     |----------------------------------------------------------- */

    public function teacherProfile()
    {
        return $this->belongsTo(TeacherProfile::class, 'teacher_profile_id');
    }

    /* -----------------------------------------------------------
     |  Scopes
     |----------------------------------------------------------- */

    public function scopeUnavailable($query)
    {
        return $query->where('type', 'unavailable');
    }

    public function scopeOverride($query)
    {
        return $query->where('type', 'override');
    }

    public function scopeForDateRange($query, $from, $to)
    {
        return $query->whereBetween('date', [$from, $to]);
    }

    /** Check if this exception blocks the entire day */
    public function isWholeDay(): bool
    {
        return $this->start_time === null && $this->end_time === null;
    }
}
