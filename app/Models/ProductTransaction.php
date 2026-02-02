<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductTransaction extends Model
{
    protected $fillable = [
        'order_id',
        'user_id',
        'payment_method',
        'transaction_id',
        'amount',
        'status',
    ];

    public function order()
    {
        return $this->belongsTo(ProductOrder::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
