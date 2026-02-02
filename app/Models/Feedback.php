<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Feedback extends Model
{
    protected $table = 'feedbacks';
    protected $fillable = [
        'organization_id',
        'user_id',
        'name',
        'user_email',
        'category',
        'message',
        'attachments',
        'status',
        'admin_response',
        'submission_date',
        'user_ip',
    ];

    protected $casts = [
        'attachments' => 'array',
        'submission_date' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }
}
