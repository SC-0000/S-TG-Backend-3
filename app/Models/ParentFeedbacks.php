<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParentFeedbacks extends Model
{
    protected $fillable = [
        'organization_id',
        'user_id',
        'name',
        'user_email',
        'category',
        'message',
        'details',        // JSON field
        'attachments',    // JSON field
        'status',
        'admin_response',
        'submitted_at',
        'user_ip',
    ];

    protected $casts = [
        'details'      => 'array',
        'attachments'  => 'array',
        'submitted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    // Accessor for a nicely formatted created_at
    public function getCreatedAtFormattedAttribute()
    {
        return $this->created_at?->format('d-m-Y');
    }
}
