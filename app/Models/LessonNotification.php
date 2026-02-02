<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LessonNotification extends Model
{
    protected $fillable = [
        'lesson_id',
        'user_id',
        'message',
        'type',
        'read_status',
    ];

    public function lesson()
    {
        return $this->belongsTo(LiveLessonSession::class, 'lesson_id');
    }
}
