<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssessmentSubmission extends Model
{
    protected $fillable = [
        'assessment_id','child_id','user_id','retake_number',
        'total_marks','marks_obtained','status',
        'started_at','finished_at','meta', 'answers_json',
    ];
    protected $casts = [
        'meta'=>'array',
        'started_at'=>'datetime',
        'finished_at'=>'datetime',
         'answers_json' => 'array', 
    ];
    public function items()
    {
        return $this->hasMany(AssessmentSubmissionItem::class, 'submission_id');
    }
    public function assessment() { return $this->belongsTo(Assessment::class); }
    public function user()       { return $this->belongsTo(User::class); }
     public function child()
    {
        return $this->belongsTo(Child::class);
    }
}
