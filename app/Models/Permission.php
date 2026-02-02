<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Permission extends Model
{
    use HasFactory;

    // If you named your table something other than "permissions", uncomment & adjust:
    // protected $table = 'permissions';

    protected $fillable = [
        'user_id',
        'child_id',
        'terms_accepted_at',
        'signature_path',
    ];

    protected $casts = [
        'terms_accepted_at' => 'datetime',
    ];

    /**
     * The parent/guardian who gave consent.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The child for whom consent was given.
     */
    public function child()
    {
        return $this->belongsTo(Child::class);
    }
}
