<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionLog extends Model
{
    public $timestamps = false; // Only created_at is used.

    protected $fillable = [
        'transaction_id', 'log_message', 'log_type',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}
