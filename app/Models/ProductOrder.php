<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductOrder extends Model
{
    protected $fillable = [
        'user_id',
        'total_amount',
        'payment_status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(ProductOrderItem::class, 'order_id');
    }

    public function transaction()
    {
        return $this->hasOne(ProductTransaction::class, 'order_id');
    }
}
