<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionLog extends Model
{
    public $timestamps = false; // Only created_at is used.

    protected $fillable = [
        'transaction_id', 'log_message', 'log_type', 'webhook_delivery_id',
        'event_type', 'payload', 'source_ip',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}
