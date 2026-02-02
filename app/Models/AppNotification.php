<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppNotification extends Model
{
    protected $table = 'app_notifications'; // Custom table name.
    public $timestamps = false; // Using only created_at.

    protected $fillable = [
        'user_id', 'title', 'message', 'type', 'status', 'channel',
    ];
    protected $casts = [
        'created_at' => 'datetime',
    ];
    
     public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
