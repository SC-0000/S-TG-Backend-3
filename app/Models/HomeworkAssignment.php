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
        'assigned_by',
        'assigned_by_role',
        'status',
        'visibility',
        'available_from',
        'grading_mode',
        'settings',
        'organization_id',
        'journey_category_id',
    ];

    protected $casts = [
        'attachments' => 'array',
        'due_date' => 'datetime',
        'available_from' => 'datetime',
        'settings' => 'array',
    ];

    public function submissions()
    {
        return $this->hasMany(HomeworkSubmission::class, 'assignment_id');
    }

    public function items()
    {
        return $this->hasMany(HomeworkItem::class, 'homework_id')->orderBy('sort_order');
    }

    public function targets()
    {
        return $this->hasMany(HomeworkTarget::class, 'homework_id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function journeyCategory()
    {
        return $this->belongsTo(JourneyCategory::class, 'journey_category_id');
    }
}
