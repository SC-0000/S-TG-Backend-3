<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LiveSessionMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'live_session_id',
        'child_id',
        'message',
        'type',
        'is_answered',
        'answered_by',
        'answer',
        'answered_at',
    ];

    protected $casts = [
        'is_answered' => 'boolean',
        'answered_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the live session this message belongs to
     */
    public function liveSession()
    {
        return $this->belongsTo(LiveLessonSession::class, 'live_session_id');
    }

    /**
     * Get the student who sent this message
     */
    public function child()
    {
        return $this->belongsTo(Child::class);
    }

    /**
     * Get the user who answered this message
     */
    public function answeredBy()
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    /**
     * Scope for unanswered messages
     */
    public function scopeUnanswered($query)
    {
        return $query->where('is_answered', false);
    }

    /**
     * Scope for questions only
     */
    public function scopeQuestions($query)
    {
        return $query->where('type', 'question');
    }
}
