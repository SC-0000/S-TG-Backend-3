<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LessonMaterial extends Model
{
    protected $fillable = [
        'lesson_id',
        'type',
        'material_url',
    ];

    public function lesson()
    {
        return $this->belongsTo(LiveLessonSession::class, 'lesson_id');
    }
}
