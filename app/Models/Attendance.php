<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    protected $table = 'attendance';
    protected $fillable = [
        'child_id','lesson_id','date','status','notes',
        'approved','approved_by','approved_at',
    ];

    /* relationships */
    public function child()  { return $this->belongsTo(Child::class); }
    public function lesson() { return $this->belongsTo(LiveLessonSession::class, 'lesson_id'); }
    public function approver() { return $this->belongsTo(User::class,'approved_by'); }

    /* scopes */
    public function scopePending($q)  { return $q->where('approved',false); }
    public function scopeApproved($q) { return $q->where('approved',true); }
}
