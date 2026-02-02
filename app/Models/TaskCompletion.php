<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskCompletion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'task_id', 'user_id', 'completed_at', 'feedback',
    ];

    // Uncomment when needed.
    // public function task()
    // {
    //     return $this->belongsTo(Task::class);
    // }
    // public function user()
    // {
    //     return $this->belongsTo(User::class);
    // }
}
