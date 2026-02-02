<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssessmentNotification extends Model
{
    public $timestamps = false; // Using only created_at timestamp.
    
    protected $fillable = [
        'assessment_id',
        'user_id',
        'message',
        'type',
        'read_status',
    ];

    public function assessment()
    {
        return $this->belongsTo(Assessment::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
