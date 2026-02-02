<?php 
// app/Models/Teacher.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Teacher extends Model
{
    protected $fillable = [
        'user_id','name','title','role','bio',
        'category','metadata','specialties','image_path'
    ];

    protected $casts = [
        'metadata'    => 'array',
        'specialties' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
