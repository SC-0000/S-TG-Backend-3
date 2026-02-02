<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    protected $fillable = [
        'organization_id',
        'title',
        'message',
        'type',
        'priority',
        'start_time',
        'end_time',
        'pages',
        'created_by',
        'additional_context',
    ];

    protected $casts = [
        'pages'      => 'array',
        'start_time' => 'datetime',
        'end_time'   => 'datetime',
    ];
    protected $primaryKey = 'alert_id';

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }
}
