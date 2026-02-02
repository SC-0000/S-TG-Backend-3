<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class AdminTask extends Model
{
    protected $fillable = [
        'organization_id',
        'task_type',
        'assigned_to',
        'status',
        'related_entity',
        'priority',
        'title',
        'description',
        'metadata',
        'completed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'completed_at' => 'datetime',
    ];

    // Append the formatted dates when converting to array/JSON.
    protected $appends = ['created_at_formatted', 'updated_at_formatted'];

    // Accessor for formatted created_at date.
    public function getCreatedAtFormattedAttribute()
    {
        return $this->created_at ? $this->created_at->format('d-m-Y') : null;
    }

    // Accessor for formatted updated_at date.
    public function getUpdatedAtFormattedAttribute()
    {
        return $this->updated_at ? $this->updated_at->format('d-m-Y') : null;
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    // Alias for admin() relationship - used in teacher context
    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    protected static function booted()
    {
        static::creating(function (self $task) {
            if (!$task->organization_id && Auth::check()) {
                $task->organization_id = Auth::user()->current_organization_id;
            }
        });
    }
}
