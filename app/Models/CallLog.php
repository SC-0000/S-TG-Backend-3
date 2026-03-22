<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallLog extends Model
{
    protected $fillable = [
        'organization_id',
        'conversation_id',
        'telnyx_call_control_id',
        'telnyx_call_leg_id',
        'from_number',
        'to_number',
        'direction',
        'initiated_by',
        'recipient_user_id',
        'status',
        'duration_seconds',
        'recording_url',
        'recording_status',
        'transcription',
        'ai_summary',
        'cost_tokens',
        'cost_currency_amount',
        'metadata',
        'started_at',
        'answered_at',
        'ended_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'cost_tokens' => 'integer',
        'cost_currency_amount' => 'integer',
        'duration_seconds' => 'integer',
        'started_at' => 'datetime',
        'answered_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public const STATUS_INITIATING = 'initiating';
    public const STATUS_RINGING = 'ringing';
    public const STATUS_ANSWERED = 'answered';
    public const STATUS_BRIDGING = 'bridging';
    public const STATUS_RECORDING = 'recording';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_MISSED = 'missed';
    public const STATUS_VOICEMAIL = 'voicemail';
    public const STATUS_BUSY = 'busy';
    public const STATUS_NO_ANSWER = 'no_answer';

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_INITIATING, self::STATUS_RINGING, self::STATUS_ANSWERED, self::STATUS_BRIDGING, self::STATUS_RECORDING]);
    }

    public function markAnswered(): void
    {
        $this->update(['status' => self::STATUS_ANSWERED, 'answered_at' => now()]);
    }

    public function markCompleted(): void
    {
        $endedAt = now();
        $duration = $this->answered_at ? (int) $endedAt->diffInSeconds($this->answered_at) : 0;
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'ended_at' => $endedAt,
            'duration_seconds' => $duration,
        ]);
    }

    public function markFailed(string $reason = ''): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'ended_at' => now(),
            'metadata' => array_merge($this->metadata ?? [], ['failure_reason' => $reason]),
        ]);
    }

    public function scopeForOrganization($query, int $orgId)
    {
        return $query->where('organization_id', $orgId);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_INITIATING, self::STATUS_RINGING, self::STATUS_ANSWERED, self::STATUS_BRIDGING, self::STATUS_RECORDING]);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }
}
