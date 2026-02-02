<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LessonSlide extends Model
{
    use HasFactory;

    protected $fillable = [
        'lesson_id',
        'uid',
        'title',
        'order_position',
        'blocks',
        'template_id',
        'layout_settings',
        'teacher_notes',
        'estimated_seconds',
        'auto_advance',
        'min_time_seconds',
        'settings',
    ];

    protected $casts = [
        'blocks' => 'array',
        'layout_settings' => 'array',
        'settings' => 'array',
        'order_position' => 'integer',
        'estimated_seconds' => 'integer',
        'min_time_seconds' => 'integer',
        'auto_advance' => 'boolean',
    ];

    // Relationships
    public function lesson()
    {
        return $this->belongsTo(ContentLesson::class, 'lesson_id');
    }

    public function interactions()
    {
        return $this->hasMany(SlideInteraction::class, 'slide_id');
    }

    public function uploads()
    {
        return $this->hasMany(LessonUpload::class, 'slide_id');
    }

    public function questionResponses()
    {
        return $this->hasMany(LessonQuestionResponse::class, 'slide_id');
    }

    public function liveInteractions()
    {
        return $this->hasMany(LiveSlideInteraction::class, 'slide_id');
    }

    // Helper methods
    public function getQuestionBlocks()
    {
        return collect($this->blocks)->filter(function ($block) {
            return $block['type'] === 'question';
        });
    }

    public function getUploadBlocks()
    {
        return collect($this->blocks)->filter(function ($block) {
            return $block['type'] === 'upload' || $block['type'] === 'task';
        });
    }

    public function hasInteractiveContent()
    {
        $interactiveTypes = ['question', 'upload', 'task', 'whiteboard', 'timer'];
        
        return collect($this->blocks)->contains(function ($block) use ($interactiveTypes) {
            return in_array($block['type'], $interactiveTypes);
        });
    }

    // Boot method for UID
    protected static function booted()
    {
        static::creating(function ($slide) {
            if (empty($slide->uid)) {
                $slide->uid = 'SLD-' . strtoupper(uniqid());
            }
        });
    }
}
