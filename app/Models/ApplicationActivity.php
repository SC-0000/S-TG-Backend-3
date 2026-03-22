<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationActivity extends Model
{
    public const TYPE_STATUS_CHANGE = 'status_change';
    public const TYPE_NOTE          = 'note';
    public const TYPE_CALL          = 'call';
    public const TYPE_EMAIL         = 'email';
    public const TYPE_SMS           = 'sms';
    public const TYPE_WHATSAPP      = 'whatsapp';
    public const TYPE_SYSTEM        = 'system';

    protected $fillable = [
        'application_id',
        'organization_id',
        'user_id',
        'activity_type',
        'title',
        'description',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class, 'application_id', 'application_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForApplication($query, string $applicationId)
    {
        return $query->where('application_id', $applicationId);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('activity_type', $type);
    }
}
