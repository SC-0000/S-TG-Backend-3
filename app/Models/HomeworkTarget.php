<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomeworkTarget extends Model
{
    protected $fillable = [
        'homework_id',
        'child_id',
        'assigned_by',
        'assigned_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    public function assignment()
    {
        return $this->belongsTo(HomeworkAssignment::class, 'homework_id');
    }

    public function child()
    {
        return $this->belongsTo(Child::class, 'child_id');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
