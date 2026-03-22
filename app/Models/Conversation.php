<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = [
        'organization_id',
        'contact_user_id',
        'contact_phone',
        'contact_email',
        'contact_name',
        'status',
        'last_message_at',
        'unread_count',
        'assigned_to',
        'last_check_in_at',
        'last_check_in_type',
        'last_check_in_by',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'last_message_at' => 'datetime',
        'last_check_in_at' => 'datetime',
        'unread_count' => 'integer',
    ];

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_ARCHIVED = 'archived';

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function contactUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contact_user_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CommunicationMessage::class)->orderBy('created_at');
    }

    public function latestMessage()
    {
        return $this->hasOne(CommunicationMessage::class)->latestOfMany();
    }

    public function isAssignedToHuman(): bool
    {
        return $this->assigned_to !== null;
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeWithUnread($query)
    {
        return $query->where('unread_count', '>', 0);
    }
}
