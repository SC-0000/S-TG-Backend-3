<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Module extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'course_id',
        'organization_id',
        'uid',
        'title',
        'description',
        'order_position',
        'status',
        'metadata',
        'estimated_duration_minutes',
    ];

    protected $casts = [
        'metadata' => 'array',
        'estimated_duration_minutes' => 'integer',
        'order_position' => 'integer',
    ];

    // Relationships
    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function lessons()
    {
        return $this->belongsToMany(ContentLesson::class, 'content_lesson_module')
            ->withPivot('order_position')
            ->withTimestamps()
            ->orderByPivot('order_position');
    }

    public function assessments()
    {
        return $this->belongsToMany(Assessment::class, 'assessment_module')
            ->withPivot('timing')
            ->withTimestamps();
    }

    // Computed properties
    public function getTotalLessonsAttribute()
    {
        return $this->lessons()->count();
    }

    public function getTotalDurationAttribute()
    {
        return $this->lessons->sum('estimated_minutes');
    }

    // Boot method for UID
    protected static function booted()
    {
        static::creating(function ($module) {
            if (empty($module->uid)) {
                $module->uid = 'MOD-' . strtoupper(uniqid());
            }
        });
    }
}
