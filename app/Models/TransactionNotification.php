<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionNotification extends Model
{
    public $timestamps = false; // Using only created_at.

    protected $fillable = [
        'user_id', 'message', 'type', 'read_status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
