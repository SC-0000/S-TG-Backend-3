<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TransactionItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id', 'item_type', 'item_id',
        'description', 'qty', 'unit_price', 'line_total',
    ];

    protected $casts = [
        'qty'         => 'integer',
        'unit_price'  => 'float',
        'line_total'  => 'float',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function item()            // Service or Product instance
    {
        return $this->morphTo();
    }
}
