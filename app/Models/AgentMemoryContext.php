<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

/**
 * AgentMemoryContext Model - Stores learning patterns and context compression
 */
class AgentMemoryContext extends Model
{
    protected $table = 'agent_memory_contexts';
    
    protected $fillable = [
        'child_id',
        'context_type',
        'context_key',
        'content',
        'metadata',
        'importance_score',
        'access_count',
        'last_accessed'
    ];

    protected $casts = [
        'content' => 'array',
        'metadata' => 'array',
        'importance_score' => 'decimal:2',
        'last_accessed' => 'datetime',
    ];

    /**
     * Context types for different agent memory categories
     */
    public const CONTEXT_TYPES = [
        'lesson' => 'Learning Interaction',
        'struggle_pattern' => 'Struggle Pattern',
        'success_pattern' => 'Success Pattern',
        'preference' => 'Learning Preference',
        'misconception' => 'Common Misconception',
        'progress_marker' => 'Progress Milestone',
        'tutor_interaction' => 'Tutor Chat History',
        'hint_progression' => 'Hint Usage Pattern',
        'grading_dispute' => 'Grading Review Context',
        'analysis_insight' => 'Progress Analysis Result'
    ];

    /**
     * Importance score thresholds
     */
    public const IMPORTANCE_LOW = 0.25;
    public const IMPORTANCE_MEDIUM = 0.50;
    public const IMPORTANCE_HIGH = 0.75;
    public const IMPORTANCE_CRITICAL = 0.90;

    /**
     * Relationship to child
     */
    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    /**
     * Check if context is high importance
     */
    public function isHighImportance(): bool
    {
        return $this->importance_score >= self::IMPORTANCE_HIGH;
    }

    /**
     * Check if context is critical
     */
    public function isCritical(): bool
    {
        return $this->importance_score >= self::IMPORTANCE_CRITICAL;
    }

    /**
     * Update access tracking
     */
    public function recordAccess(): void
    {
        $this->increment('access_count');
        $this->update(['last_accessed' => now()]);
        
        // Increase importance slightly based on frequent access
        if ($this->access_count % 5 === 0 && $this->importance_score < 0.95) {
            $this->update([
                'importance_score' => min(0.95, $this->importance_score + 0.02)
            ]);
        }
    }

    /**
     * Update importance score
     */
    public function updateImportance(float $newScore): void
    {
        $this->update([
            'importance_score' => max(0, min(1, $newScore))
        ]);
    }

    /**
     * Store new context or update existing
     */
    public static function storeContext(int $childId, string $contextType, string $contextKey, array $content, array $metadata = [], float $importance = 0.5): self
    {
        return self::updateOrCreate(
            [
                'child_id' => $childId,
                'context_type' => $contextType,
                'context_key' => $contextKey
            ],
            [
                'content' => $content,
                'metadata' => array_merge($metadata, ['updated_at' => now()->toISOString()]),
                'importance_score' => $importance,
                'access_count' => 0,
                'last_accessed' => now()
            ]
        );
    }

    /**
     * Get relevant context for agent processing
     */
    public static function getRelevantContext(int $childId, array $contextTypes = [], float $minImportance = 0.3, int $limit = 10): array
    {
        $query = self::where('child_id', $childId)
                    ->where('importance_score', '>=', $minImportance)
                    ->orderBy('importance_score', 'desc')
                    ->orderBy('last_accessed', 'desc')
                    ->orderBy('created_at', 'desc');
                    
        if (!empty($contextTypes)) {
            $query->whereIn('context_type', $contextTypes);
        }
        
        $contexts = $query->take($limit)->get();
        
        // Update access tracking for retrieved contexts
        foreach ($contexts as $context) {
            $context->recordAccess();
        }
        
        return $contexts->toArray();
    }

    /**
     * Get learning patterns for a child
     */
    public static function getLearningPatterns(int $childId, int $limit = 5): array
    {
        $patterns = self::where('child_id', $childId)
                       ->whereIn('context_type', ['struggle_pattern', 'success_pattern', 'preference'])
                       ->where('importance_score', '>=', self::IMPORTANCE_MEDIUM)
                       ->orderBy('importance_score', 'desc')
                       ->take($limit)
                       ->get();
                       
        $result = [];
        foreach ($patterns as $pattern) {
            $pattern->recordAccess();
            $result[] = [
                'type' => $pattern->context_type,
                'pattern' => $pattern->content,
                'importance' => $pattern->importance_score,
                'last_seen' => $pattern->last_accessed->diffForHumans()
            ];
        }
        
        return $result;
    }

    /**
     * Store learning interaction
     */
    public static function storeLearningInteraction(int $childId, array $interactionData, float $importance = 0.6): self
    {
        $contextKey = 'interaction_' . now()->timestamp;
        
        return self::storeContext(
            $childId,
            'lesson',
            $contextKey,
            $interactionData,
            [
                'interaction_type' => $interactionData['type'] ?? 'unknown',
                'timestamp' => now()->toISOString()
            ],
            $importance
        );
    }

    /**
     * Store struggle pattern
     */
    public static function storeStrugglePattern(int $childId, string $topicArea, array $details, float $importance = 0.8): self
    {
        return self::storeContext(
            $childId,
            'struggle_pattern',
            'struggle_' . strtolower(str_replace(' ', '_', $topicArea)),
            array_merge($details, ['topic_area' => $topicArea]),
            [
                'pattern_type' => 'academic_struggle',
                'topic' => $topicArea,
                'identified_at' => now()->toISOString()
            ],
            $importance
        );
    }

    /**
     * Store success pattern
     */
    public static function storeSuccessPattern(int $childId, string $topicArea, array $details, float $importance = 0.7): self
    {
        return self::storeContext(
            $childId,
            'success_pattern',
            'success_' . strtolower(str_replace(' ', '_', $topicArea)),
            array_merge($details, ['topic_area' => $topicArea]),
            [
                'pattern_type' => 'academic_success',
                'topic' => $topicArea,
                'identified_at' => now()->toISOString()
            ],
            $importance
        );
    }

    /**
     * Compress old context data (cleanup method)
     */
    public static function compressOldContexts(int $daysOld = 30): int
    {
        $oldContexts = self::where('created_at', '<', now()->subDays($daysOld))
                          ->where('importance_score', '<', self::IMPORTANCE_HIGH)
                          ->where('access_count', '<', 3);
                          
        $compressed = 0;
        
        // Group by child and context type for compression
        $grouped = $oldContexts->get()->groupBy(['child_id', 'context_type']);
        
        foreach ($grouped as $childId => $childContexts) {
            foreach ($childContexts as $contextType => $contexts) {
                if ($contexts->count() > 5) {
                    // Keep the most important ones, compress the rest
                    $toKeep = $contexts->sortByDesc('importance_score')->take(3);
                    $toCompress = $contexts->whereNotIn('id', $toKeep->pluck('id'));
                    
                    if ($toCompress->count() > 0) {
                        // Create a compressed summary
                        $summary = self::createCompressedSummary($toCompress);
                        
                        // Store the compressed version
                        self::storeContext(
                            $childId,
                            $contextType . '_compressed',
                            'compressed_' . now()->timestamp,
                            $summary,
                            [
                                'compression_date' => now()->toISOString(),
                                'original_count' => $toCompress->count()
                            ],
                            self::IMPORTANCE_MEDIUM
                        );
                        
                        // Delete the originals
                        self::whereIn('id', $toCompress->pluck('id'))->delete();
                        $compressed += $toCompress->count();
                    }
                }
            }
        }
        
        return $compressed;
    }

    /**
     * Create compressed summary from multiple contexts
     */
    private static function createCompressedSummary($contexts): array
    {
        $summary = [
            'type' => 'compressed_summary',
            'original_count' => $contexts->count(),
            'date_range' => [
                'start' => $contexts->min('created_at'),
                'end' => $contexts->max('created_at')
            ],
            'patterns' => [],
            'key_insights' => []
        ];
        
        // Extract common patterns
        $topicAreas = [];
        $interactionTypes = [];
        
        foreach ($contexts as $context) {
            $content = $context->content;
            
            if (isset($content['topic_area'])) {
                $topicAreas[] = $content['topic_area'];
            }
            
            if (isset($content['interaction_type'])) {
                $interactionTypes[] = $content['interaction_type'];
            }
        }
        
        $summary['patterns'] = [
            'common_topics' => array_count_values($topicAreas),
            'interaction_types' => array_count_values($interactionTypes),
            'avg_importance' => $contexts->avg('importance_score')
        ];
        
        return $summary;
    }

    /**
     * Get context type display name
     */
    public function getContextTypeNameAttribute(): string
    {
        return self::CONTEXT_TYPES[$this->context_type] ?? ucfirst(str_replace('_', ' ', $this->context_type));
    }

    /**
     * Get importance level as string
     */
    public function getImportanceLevelAttribute(): string
    {
        if ($this->importance_score >= self::IMPORTANCE_CRITICAL) {
            return 'Critical';
        } elseif ($this->importance_score >= self::IMPORTANCE_HIGH) {
            return 'High';
        } elseif ($this->importance_score >= self::IMPORTANCE_MEDIUM) {
            return 'Medium';
        }
        return 'Low';
    }

    /**
     * Scope for high importance contexts
     */
    public function scopeHighImportance($query)
    {
        return $query->where('importance_score', '>=', self::IMPORTANCE_HIGH);
    }

    /**
     * Scope for specific context type
     */
    public function scopeForContextType($query, string $contextType)
    {
        return $query->where('context_type', $contextType);
    }

    /**
     * Scope for child
     */
    public function scopeForChild($query, int $childId)
    {
        return $query->where('child_id', $childId);
    }

    /**
     * Scope for recent contexts
     */
    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
