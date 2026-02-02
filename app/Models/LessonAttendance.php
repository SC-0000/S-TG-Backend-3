<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LessonAttendance extends Model
{
    protected $table = 'lesson_attendance';

    protected $fillable = [
        'lesson_id',
        'user_id',
        'status',
        'notes',
    ];

    public function lesson()
    {
        return $this->belongsTo(LiveLessonSession::class, 'lesson_id');
    }
}
