<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LiveSessionParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'live_lesson_session_id',
        'child_id',
        'status',
        'hand_raised',
        'hand_raised_at',
        'connection_status',
        'invited_at',
        'joined_at',
        'left_at',
        'current_slide_id',
        'audio_muted',
        'video_off',
        'interaction_data',
        'connection_metrics',
    ];

    protected $casts = [
        'interaction_data' => 'array',
        'connection_metrics' => 'array',
        'audio_muted' => 'boolean',
        'video_off' => 'boolean',
        'hand_raised' => 'boolean',
        'invited_at' => 'datetime',
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
        'hand_raised_at' => 'datetime',
    ];

    // Relationships
    public function liveSession()
    {
        return $this->belongsTo(LiveLessonSession::class, 'live_lesson_session_id');
    }

    public function child()
    {
        return $this->belongsTo(Child::class);
    }

    public function currentSlide()
    {
        return $this->belongsTo(LessonSlide::class, 'current_slide_id');
    }

    // Scopes
    public function scopeJoined($query)
    {
        return $query->where('status', 'joined');
    }

    public function scopeConnected($query)
    {
        return $query->where('connection_status', 'connected');
    }

    public function scopeForSession($query, $sessionId)
    {
        return $query->where('live_lesson_session_id', $sessionId);
    }

    // Helper methods
    public function join()
    {
        $this->status = 'joined';
        $this->joined_at = now();
        $this->connection_status = 'connected';
        $this->save();
    }

    public function leave()
    {
        $this->status = 'left';
        $this->left_at = now();
        $this->connection_status = 'disconnected';
        $this->save();
    }

    public function updateConnectionStatus($status)
    {
        $this->connection_status = $status;
        $this->save();
    }

    public function toggleAudio()
    {
        $this->audio_muted = !$this->audio_muted;
        $this->save();
    }

    public function toggleVideo()
    {
        $this->video_off = !$this->video_off;
        $this->save();
    }

    public function getSessionDurationAttribute()
    {
        if ($this->joined_at && $this->left_at) {
            return $this->joined_at->diffInMinutes($this->left_at);
        }
        
        if ($this->joined_at) {
            return $this->joined_at->diffInMinutes(now());
        }
        
        return 0;
    }
}
