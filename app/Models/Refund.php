<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    public $timestamps = false; // We only have created_at here.

    protected $fillable = [
        'transaction_id', 'user_id', 'amount_refunded', 'refund_reason', 'status',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
