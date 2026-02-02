<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'email_enabled', 'sms_enabled', 'in_app_enabled', 'push_enabled',
    ];

    // public function user()
    // {
    //     return $this->belongsTo(User::class);
    // }
}
