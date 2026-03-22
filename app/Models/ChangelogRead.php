<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChangelogRead extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'changelog_entry_id',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function changelogEntry(): BelongsTo
    {
        return $this->belongsTo(ChangelogEntry::class);
    }
}
