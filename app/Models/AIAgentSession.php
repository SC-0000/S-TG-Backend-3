<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

/**
 * AIAgentSession Model - Manages agent conversation sessions
 */
class AIAgentSession extends Model
{
    protected $table = 'ai_agent_sessions';
    
    protected $fillable = [
        'child_id',
        'agent_type',
        'session_data',
        'context_summary',
        'memory_embeddings',
        'session_metadata',
        'last_interaction',
        'is_active'
    ];

    protected $casts = [
        'session_data' => 'array',
        'session_metadata' => 'array',  // Fix for contextual navigation
        'metadata' => 'array',
        'memory_embeddings' => 'array',
        'expires_at' => 'datetime',
        'last_activity' => 'datetime',
        'last_interaction' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Agent types available in the system
     */
    public const AGENT_TYPES = [
        'tutor' => 'General Tutoring',
        'grading_review' => 'Grading Review',
        'progress_analysis' => 'Progress Analysis',
        'hint_generator' => 'Hint Generator'
    ];

    /**
     * Session statuses
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_EXPIRED = 'expired';

    /**
     * Relationship to child
     */
    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    /**
     * Relationship to agent memory contexts
     */
    public function memoryContexts(): HasMany
    {
        return $this->hasMany(AgentMemoryContext::class, 'child_id', 'child_id')
                    ->where('context_type', 'LIKE', $this->agent_type . '%');
    }

    /**
     * Check if session is active
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE 
               && $this->expires_at 
               && $this->expires_at->isFuture();
    }

    /**
     * Check if session is expired
     */
    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED 
               || ($this->expires_at && $this->expires_at->isPast());
    }

    /**
     * Extend session expiry
     */
    public function extend(int $hours = 24): void
    {
        $this->update([
            'expires_at' => now()->addHours($hours),
            'last_activity' => now(),
            'status' => self::STATUS_ACTIVE
        ]);
    }

    /**
     * Update session activity
     */
    public function updateActivity(): void
    {
        $this->update([
            'last_activity' => now()
        ]);
    }

    /**
     * Add message to session
     */
    public function addMessage(array $message): void
    {
        $sessionData = $this->session_data ?? [];
        $messages = $sessionData['messages'] ?? [];
        
        $messages[] = array_merge($message, [
            'timestamp' => now()->toISOString()
        ]);
        
        // Keep only last 50 messages to prevent excessive data growth
        if (count($messages) > 50) {
            $messages = array_slice($messages, -50);
        }
        
        $sessionData['messages'] = $messages;
        $sessionData['message_count'] = count($messages);
        
        $this->update([
            'session_data' => $sessionData,
            'last_activity' => now()
        ]);
    }

    /**
     * Get recent messages
     */
    public function getRecentMessages(int $limit = 10): array
    {
        $messages = $this->session_data['messages'] ?? [];
        return array_slice($messages, -$limit);
    }

    /**
     * Get session statistics
     */
    public function getStatistics(): array
    {
        $sessionData = $this->session_data ?? [];
        $metadata = $this->metadata ?? [];
        
        return [
            'total_messages' => $sessionData['message_count'] ?? 0,
            'session_duration' => $this->created_at->diffInMinutes($this->last_activity ?? $this->updated_at),
            'last_activity' => $this->last_activity?->diffForHumans() ?? 'Never',
            'agent_type' => $this->agent_type,
            'status' => $this->status,
            'expires_at' => $this->expires_at?->diffForHumans() ?? 'Never',
            'topics_discussed' => $metadata['topics_discussed'] ?? [],
            'confidence_avg' => $metadata['avg_confidence'] ?? 0,
        ];
    }

    /**
     * Update session metadata
     */
    public function updateMetadata(array $newMetadata): void
    {
        $existingMetadata = $this->metadata ?? [];
        $mergedMetadata = array_merge($existingMetadata, $newMetadata);
        
        $this->update([
            'metadata' => $mergedMetadata,
            'last_activity' => now()
        ]);
    }

    /**
     * Create or get active session for child and agent type
     */
    public static function createOrGet(int $childId, string $agentType): self
    {
        // Try to find an active session
        $session = self::where('child_id', $childId)
                      ->where('agent_type', $agentType)
                      ->where('status', self::STATUS_ACTIVE)
                      ->where('expires_at', '>', now())
                      ->first();
                      
        if ($session) {
            $session->updateActivity();
            return $session;
        }
        
        // Create new session
        return self::create([
            'child_id' => $childId,
            'agent_type' => $agentType,
            'session_data' => [
                'messages' => [],
                'message_count' => 0,
                'created_at' => now()->toISOString()
            ],
            'metadata' => [
                'topics_discussed' => [],
                'avg_confidence' => 0,
                'interaction_count' => 0
            ],
            'status' => self::STATUS_ACTIVE,
            'expires_at' => now()->addHours(24),
            'last_activity' => now()
        ]);
    }

    /**
     * Expire old sessions (cleanup method)
     */
    public static function expireOldSessions(): int
    {
        return self::where('expires_at', '<', now())
                   ->where('status', '!=', self::STATUS_EXPIRED)
                   ->update(['status' => self::STATUS_EXPIRED]);
    }

    /**
     * Get agent type display name
     */
    public function getAgentTypeNameAttribute(): string
    {
        return self::AGENT_TYPES[$this->agent_type] ?? ucfirst(str_replace('_', ' ', $this->agent_type));
    }

    /**
     * Scope for active sessions
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
                    ->where('expires_at', '>', now());
    }

    /**
     * Scope for specific agent type
     */
    public function scopeForAgent($query, string $agentType)
    {
        return $query->where('agent_type', $agentType);
    }

    /**
     * Scope for child
     */
    public function scopeForChild($query, int $childId)
    {
        return $query->where('child_id', $childId);
    }
}
