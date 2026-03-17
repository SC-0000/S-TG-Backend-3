<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceCredit extends Model
{
    use HasFactory;

    protected $fillable = [
        'child_id',
        'service_id',
        'organization_id',
        'total_credits',
        'used_credits',
        'transaction_id',
        'invoice_id',
        'expires_at',
    ];

    protected $casts = [
        'total_credits' => 'integer',
        'used_credits'  => 'integer',
        'expires_at'    => 'datetime',
    ];

    /* -----------------------------------------------------------
     |  Relationships
     |----------------------------------------------------------- */

    public function child()        { return $this->belongsTo(Child::class); }
    public function service()      { return $this->belongsTo(Service::class); }
    public function organization() { return $this->belongsTo(Organization::class); }
    public function transaction()   { return $this->belongsTo(Transaction::class); }

    /* -----------------------------------------------------------
     |  Helpers
     |----------------------------------------------------------- */

    public function getRemainingAttribute(): int
    {
        return max(0, $this->total_credits - $this->used_credits);
    }

    public function hasCredits(): bool
    {
        return $this->remaining > 0;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isValid(): bool
    {
        return $this->hasCredits() && !$this->isExpired();
    }

    public function useCredit(): bool
    {
        if (!$this->isValid()) {
            return false;
        }

        $this->increment('used_credits');
        return true;
    }

    public function refundCredit(): void
    {
        if ($this->used_credits > 0) {
            $this->decrement('used_credits');
        }
    }

    /* -----------------------------------------------------------
     |  Scopes
     |----------------------------------------------------------- */

    public function scopeValid($query)
    {
        return $query->whereRaw('used_credits < total_credits')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
    }

    public function scopeForChild($query, int $childId)
    {
        return $query->where('child_id', $childId);
    }
}
