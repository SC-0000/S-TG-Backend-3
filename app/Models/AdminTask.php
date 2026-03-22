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
        'due_at',
        'source',
        'source_model_type',
        'source_model_id',
        'auto_resolve_event',
        'assigned_at',
        'snoozed_until',
        'category',
        'action_url',
    ];

    protected $casts = [
        'metadata'     => 'array',
        'completed_at' => 'datetime',
        'due_at'       => 'datetime',
        'assigned_at'  => 'datetime',
        'snoozed_until'=> 'datetime',
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

    public function scopeOverdue($query)
    {
        return $query->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNotIn('status', ['Completed']);
    }

    public function scopeForCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'Pending');
    }

    public function scopeOpen($query)
    {
        return $query->whereNotIn('status', ['Completed']);
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->due_at
            && $this->due_at->isPast()
            && $this->status !== 'Completed';
    }

    public function getDaysOpenAttribute(): ?int
    {
        if ($this->status === 'Completed') {
            return null;
        }
        $start = $this->assigned_at ?? $this->created_at;
        return $start ? (int) $start->diffInDays(now()) : null;
    }

    public function getDueAtFormattedAttribute(): ?string
    {
        return $this->due_at?->format('d-m-Y H:i');
    }

    public function getIsSystemTaskAttribute(): bool
    {
        return $this->source === 'system' || $this->source === 'agent';
    }

    /**
     * The source model that triggered this task (polymorphic).
     */
    public function sourceModel()
    {
        return $this->morphTo('source_model', 'source_model_type', 'source_model_id');
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
