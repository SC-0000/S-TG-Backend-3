<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomeworkSubmission extends Model
{
    protected $fillable = [
        'assignment_id',
        'student_id',
        'organization_id',
        'submission_status',
        'content',
        'attachments',
        'grade',
        'feedback',
        'graded_by',
        'attempt',
        'submitted_at',
        'reviewed_at',
    ];

    protected $casts = [
        'attachments' => 'array',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function assignment()
    {
        return $this->belongsTo(HomeworkAssignment::class, 'assignment_id');
    }

    public function child()
    {
        return $this->belongsTo(Child::class, 'student_id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function gradedBy()
    {
        return $this->belongsTo(User::class, 'graded_by');
    }
}
