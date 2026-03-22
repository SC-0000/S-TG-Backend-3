<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Transaction extends Model
{
    use HasFactory;

    /* ─── Status Constants ─── */
    const STATUS_PENDING  = 'pending';
    const STATUS_PAID     = 'paid';
    const STATUS_FAILED   = 'failed';
    const STATUS_REFUNDED = 'refunded';
    const STATUS_VOID     = 'void';

    /** Statuses considered as "payment received" — single source of truth. */
    const PAID_STATUSES = [self::STATUS_PAID];

    protected $fillable = [
        'organization_id', 'user_id', 'user_email', 'type', 'status',
        'payment_method', 'subtotal', 'discount', 'tax',
        'total', 'paid_at', 'comment', 'meta', 'invoice_id',
        'promotion_id',
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
    public function paymentFollowup() { return $this->hasOne(PaymentFollowup::class); }
    public function promotion()       { return $this->belongsTo(Promotion::class); }
}
