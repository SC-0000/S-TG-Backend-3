<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    public $timestamps = false;

    protected $casts = [
        'due_date' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected $fillable = [
        'assigned_to', 'created_by', 'title', 'description', 'due_date', 'priority', 'status',
    ];

    // Uncomment when the user model is available.
    // public function assignedTo()
    // {
    //     return $this->belongsTo(User::class, 'assigned_to');
    // }
    // public function createdBy()
    // {
    //     return $this->belongsTo(User::class, 'created_by');
    // }

    public function completions()
    {
        return $this->hasMany(TaskCompletion::class);
    }
}
