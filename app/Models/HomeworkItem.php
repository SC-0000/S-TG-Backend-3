<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomeworkItem extends Model
{
    protected $fillable = [
        'homework_id',
        'type',
        'ref_id',
        'payload',
        'sort_order',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function assignment()
    {
        return $this->belongsTo(HomeworkAssignment::class, 'homework_id');
    }
}
