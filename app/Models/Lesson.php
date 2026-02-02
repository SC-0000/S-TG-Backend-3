<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lesson extends Model
{
    // Point to the renamed table
    protected $table = 'live_sessions';
    
    protected $fillable = [
        'title','description','lesson_type','lesson_mode',
        'start_time','end_time','address','meeting_link',
        'live_lesson_session_id','instructor_id','service_id','status','journey_category_id',
        'year_group','organization_id','is_global',
    ];
    protected $casts = [
      'start_time' => 'datetime',
      'end_time'   => 'datetime',
      'is_global'  => 'boolean',
    ];

    /* --- relationships --- */

    public function children()
    {
        return $this->belongsToMany(Child::class, 'child_live_session')
                    ->withPivot('attendance')
                    ->withTimestamps();
    }

    public function category() {
        return $this->belongsTo(JourneyCategory::class,'journey_category_id');
     }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function assessments()
    {
        return $this->hasMany(Assessment::class);
    }

    public function liveLessonSession()
    {
        return $this->belongsTo(LiveLessonSession::class, 'live_lesson_session_id');
    }

    public function attendances() { return $this->hasMany(Attendance::class); }
    
    public function participants()
    {
        return $this->hasManyThrough(
            LiveSessionParticipant::class,  // Final model we want
            LiveLessonSession::class,       // Intermediate model
            'id',                           // Foreign key on LiveLessonSession table
            'live_lesson_session_id',       // Foreign key on LiveSessionParticipant table
            'live_lesson_session_id',       // Local key on Lesson table
            'id'                            // Local key on LiveLessonSession table
        );
    }
    
   public function scopeForParent($q, int $parentId)
{
    return $q->whereHas('service.children', fn ($c) =>
               $c->whereHas('user', fn ($u) => $u->where('users.id', $parentId))
           );
}

    public function scopeGlobal($query)
    {
        return $query->where('is_global', true);
    }

    public function scopeVisibleToOrg($query, ?int $organizationId)
    {
        return $query->where(function ($q) use ($organizationId) {
            $q->where('is_global', true);
            if ($organizationId) {
                $q->orWhere('organization_id', $organizationId);
            }
        });
    }
}
