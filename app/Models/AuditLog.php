<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    // Immutable — no updated_at
    const UPDATED_AT = null;

    protected $table = 'audit_logs';

    protected $guarded = [];

    protected $casts = [
        'changes'    => 'array',
        'created_at' => 'datetime',
    ];

    // Ensure created_at is always set on insert
    protected static function booted(): void
    {
        static::creating(function (AuditLog $log) {
            $log->created_at ??= now();
        });
    }
}
