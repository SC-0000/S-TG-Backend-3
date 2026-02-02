<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'notification_id', 'sent_to', 'sent_via', 'status', 'error_message', 'sent_at',
    ];

    // public function notification()
    // {
    //     return $this->belongsTo(AppNotification::class, 'notification_id');
    // }
}
