<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomeworkAssignment extends Model
{
    protected $fillable = [
        'title',
        'description',
        'subject',
        'due_date',
        'attachments',
        'created_by',
        'organization_id',
    ];

    protected $casts = [
        'attachments' => 'array',
        'due_date' => 'datetime',
    ];

    public function submissions()
    {
        return $this->hasMany(HomeworkSubmission::class, 'assignment_id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
