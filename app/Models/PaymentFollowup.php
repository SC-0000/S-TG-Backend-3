<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentFollowup extends Model
{
    protected $fillable = [
        'organization_id',
        'transaction_id',
        'user_id',
        'followup_stage',
        'last_followup_at',
        'next_followup_at',
        'status',
        'notes',
    ];

    protected $casts = [
        'followup_stage' => 'integer',
        'last_followup_at' => 'datetime',
        'next_followup_at' => 'datetime',
        'notes' => 'array',
    ];

    public const STAGE_GENTLE = 1;
    public const STAGE_FIRM = 2;
    public const STAGE_FINAL = 3;
    public const STAGE_ESCALATED = 4;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_ESCALATED = 'escalated';
    public const STATUS_CANCELLED = 'cancelled';

    // Days after initial due date for each stage
    public const STAGE_SCHEDULE = [
        self::STAGE_GENTLE => 3,
        self::STAGE_FIRM => 7,
        self::STAGE_FINAL => 14,
        self::STAGE_ESCALATED => 21,
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function addNote(string $note): void
    {
        $notes = $this->notes ?? [];
        $notes[] = [
            'message' => $note,
            'stage' => $this->followup_stage,
            'timestamp' => now()->toISOString(),
        ];
        $this->update(['notes' => $notes]);
    }

    public function advanceStage(): void
    {
        $nextStage = min($this->followup_stage + 1, self::STAGE_ESCALATED);
        $this->update([
            'followup_stage' => $nextStage,
            'last_followup_at' => now(),
            'next_followup_at' => now()->addDays(self::STAGE_SCHEDULE[$nextStage] ?? 7),
            'status' => $nextStage >= self::STAGE_ESCALATED ? self::STATUS_ESCALATED : self::STATUS_ACTIVE,
        ]);
    }

    public function resolve(): void
    {
        $this->update([
            'status' => self::STATUS_RESOLVED,
            'next_followup_at' => null,
        ]);
        $this->addNote('Payment resolved');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeDue($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where('next_followup_at', '<=', now());
    }
}
