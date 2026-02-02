<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductNotification extends Model
{
    // If you don't need updated_at, you can disable timestamps.
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'message',
        'type',
        'read_status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
