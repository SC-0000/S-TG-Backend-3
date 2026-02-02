<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIUploadLog extends Model
{
    protected $table = 'ai_upload_logs';

    // Only created_at, no updated_at
    const UPDATED_AT = null;

    protected $fillable = [
        'session_id',
        'proposal_id',
        'level',
        'action',
        'message',
        'context',
        'ai_model',
        'tokens_input',
        'tokens_output',
        'cost_usd',
        'duration_ms',
    ];

    protected $casts = [
        'context' => 'array',
        'cost_usd' => 'decimal:6',
    ];

    // Log levels
    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';

    // Common actions
    const ACTION_SESSION_START = 'session_start';
    const ACTION_GENERATE = 'generate';
    const ACTION_VALIDATE = 'validate';
    const ACTION_REFINE = 'refine';
    const ACTION_UPLOAD = 'upload';
    const ACTION_ERROR = 'error';
    const ACTION_COMPLETE = 'complete';

    // Relationships
    public function session(): BelongsTo
    {
        return $this->belongsTo(AIUploadSession::class, 'session_id');
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(AIUploadProposal::class, 'proposal_id');
    }

    // Scopes
    public function scopeForSession($query, int $sessionId)
    {
        return $query->where('session_id', $sessionId);
    }

    public function scopeByLevel($query, string $level)
    {
        return $query->where('level', $level);
    }

    public function scopeErrors($query)
    {
        return $query->where('level', self::LEVEL_ERROR);
    }

    public function scopeWarnings($query)
    {
        return $query->where('level', self::LEVEL_WARNING);
    }

    // Static factory methods
    public static function debug(int $sessionId, string $action, string $message, array $context = []): self
    {
        return static::create([
            'session_id' => $sessionId,
            'level' => self::LEVEL_DEBUG,
            'action' => $action,
            'message' => $message,
            'context' => $context,
        ]);
    }

    public static function info(int $sessionId, string $action, string $message, array $context = []): self
    {
        return static::create([
            'session_id' => $sessionId,
            'level' => self::LEVEL_INFO,
            'action' => $action,
            'message' => $message,
            'context' => $context,
        ]);
    }

    public static function warning(int $sessionId, string $action, string $message, array $context = []): self
    {
        return static::create([
            'session_id' => $sessionId,
            'level' => self::LEVEL_WARNING,
            'action' => $action,
            'message' => $message,
            'context' => $context,
        ]);
    }

    public static function error(int $sessionId, string $action, string $message, array $context = []): self
    {
        return static::create([
            'session_id' => $sessionId,
            'level' => self::LEVEL_ERROR,
            'action' => $action,
            'message' => $message,
            'context' => $context,
        ]);
    }

    /**
     * Log AI interaction with token usage
     */
    public static function aiInteraction(
        int $sessionId,
        string $action,
        string $message,
        string $model,
        int $tokensInput,
        int $tokensOutput,
        int $durationMs,
        ?int $proposalId = null,
        array $context = []
    ): self {
        // Estimate cost based on model (approximate rates)
        $costPerInputToken = match(true) {
            str_contains($model, 'gpt-4') => 0.00003,
            str_contains($model, 'gpt-3.5') => 0.000001,
            str_contains($model, 'o1') => 0.000015,
            default => 0.00001,
        };
        $costPerOutputToken = match(true) {
            str_contains($model, 'gpt-4') => 0.00006,
            str_contains($model, 'gpt-3.5') => 0.000002,
            str_contains($model, 'o1') => 0.00006,
            default => 0.00003,
        };

        $cost = ($tokensInput * $costPerInputToken) + ($tokensOutput * $costPerOutputToken);

        return static::create([
            'session_id' => $sessionId,
            'proposal_id' => $proposalId,
            'level' => self::LEVEL_INFO,
            'action' => $action,
            'message' => $message,
            'context' => $context,
            'ai_model' => $model,
            'tokens_input' => $tokensInput,
            'tokens_output' => $tokensOutput,
            'cost_usd' => $cost,
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * Get total tokens used in this session
     */
    public static function getSessionTokenUsage(int $sessionId): array
    {
        $stats = static::where('session_id', $sessionId)
            ->whereNotNull('tokens_input')
            ->selectRaw('SUM(tokens_input) as total_input, SUM(tokens_output) as total_output, SUM(cost_usd) as total_cost')
            ->first();

        return [
            'tokens_input' => (int) ($stats->total_input ?? 0),
            'tokens_output' => (int) ($stats->total_output ?? 0),
            'total_cost' => (float) ($stats->total_cost ?? 0),
        ];
    }

    /**
     * Get formatted cost string
     */
    public function getFormattedCost(): string
    {
        if (!$this->cost_usd) {
            return '$0.00';
        }
        return '$' . number_format($this->cost_usd, 4);
    }

    /**
     * Get formatted duration
     */
    public function getFormattedDuration(): string
    {
        if (!$this->duration_ms) {
            return '0ms';
        }
        if ($this->duration_ms < 1000) {
            return $this->duration_ms . 'ms';
        }
        return number_format($this->duration_ms / 1000, 2) . 's';
    }
}
