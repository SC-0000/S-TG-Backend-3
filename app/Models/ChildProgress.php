<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChildProgress extends Model
{
    protected $table = 'child_progress';

    protected $fillable = [
        'child_id',
        'lesson_id',
        'progress',
        'feedback',
    ];

    public function child()
    {
        return $this->belongsTo(Child::class);
    }
       // Uncomment the following if you have a Lesson model:
    // public function lesson()
    // {
    //     return $this->belongsTo(Lesson::class);
    // }
}
