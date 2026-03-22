<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'email_enabled', 'sms_enabled', 'whatsapp_enabled', 'whatsapp_opted_in',
        'phone_number', 'preferred_channels', 'in_app_enabled', 'push_enabled',
    ];

    protected $casts = [
        'email_enabled' => 'boolean',
        'sms_enabled' => 'boolean',
        'whatsapp_enabled' => 'boolean',
        'whatsapp_opted_in' => 'boolean',
        'in_app_enabled' => 'boolean',
        'push_enabled' => 'boolean',
        'preferred_channels' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
