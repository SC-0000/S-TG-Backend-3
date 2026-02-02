<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'user_email', 'type', 'status',
        'payment_method', 'subtotal', 'discount', 'tax',
        'total', 'paid_at', 'comment', 'meta', 'invoice_id',
    ];

    protected $casts = [
        'meta'     => 'array',
        'paid_at'  => 'datetime',
    ];

    /* ─── Relationships ─── */
    public function user()        { return $this->belongsTo(User::class); }
    public function items()       { return $this->hasMany(TransactionItem::class); }
    public function invoice()     { return $this->hasOne(Invoice::class); }
    public function refunds()     { return $this->hasMany(Refund::class); }
    public function logs()        { return $this->hasMany(TransactionLog::class); }
}
