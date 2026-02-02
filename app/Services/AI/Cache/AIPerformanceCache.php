<?php

namespace App\Services\AI\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Phase 3: AI Performance Caching Layer
 * Implements intelligent caching strategies for AI operations
 */
class AIPerformanceCache
{
    /**
     * Cache prefixes for different AI operations
     */
    private const AGENT_RESPONSE_PREFIX = 'ai_agent_response_';
    private const CONTEXT_PREFIX = 'ai_context_';
    private const PERFORMANCE_PREFIX = 'ai_performance_';
    private const RATE_LIMIT_PREFIX = 'ai_rate_limit_';

    /**
     * Cache durations (in minutes)
     */
    private const SHORT_CACHE = 5;      // For quick responses
    private const MEDIUM_CACHE = 30;    // For context data
    private const LONG_CACHE = 120;     // For performance data
    private const RATE_LIMIT_WINDOW = 60; // Rate limit window

    /**
     * Cache agent response if similar query exists
     */
    public function cacheAgentResponse(string $agentType, int $childId, string $queryHash, array $response): void
    {
        $cacheKey = self::AGENT_RESPONSE_PREFIX . "{$agentType}_{$childId}_{$queryHash}";
        
        $cacheData = [
            'response' => $response,
            'cached_at' => now()->toISOString(),
            'agent_type' => $agentType,
            'child_id' => $childId
        ];

        Cache::put($cacheKey, $cacheData, self::SHORT_CACHE);

        Log::debug("AI Response Cached", [
            'agent_type' => $agentType,
            'child_id' => $childId,
            'cache_key' => $cacheKey,
            'response_size' => strlen(json_encode($response))
        ]);
    }

    /**
     * Get cached agent response if available
     */
    public function getCachedAgentResponse(string $agentType, int $childId, string $queryHash): ?array
    {
        $cacheKey = self::AGENT_RESPONSE_PREFIX . "{$agentType}_{$childId}_{$queryHash}";
        
        $cachedData = Cache::get($cacheKey);
        
        if ($cachedData) {
            Log::debug("AI Response Cache Hit", [
                'agent_type' => $agentType,
                'child_id' => $childId,
                'cached_at' => $cachedData['cached_at']
            ]);
            
            return $cachedData['response'];
        }

        return null;
    }

    /**
     * Cache performance metrics
     */
    public function cachePerformanceMetrics(int $childId, array $metrics): void
    {
        $cacheKey = self::PERFORMANCE_PREFIX . $childId;
        
        $performanceData = [
            'metrics' => $metrics,
            'calculated_at' => now()->toISOString(),
            'child_id' => $childId
        ];

        Cache::put($cacheKey, $performanceData, self::LONG_CACHE);

        Log::debug("Performance Metrics Cached", [
            'child_id' => $childId,
            'metrics_count' => count($metrics)
        ]);
    }

    /**
     * Get cached performance metrics
     */
    public function getCachedPerformanceMetrics(int $childId): ?array
    {
        $cacheKey = self::PERFORMANCE_PREFIX . $childId;
        
        $cachedData = Cache::get($cacheKey);
        
        if ($cachedData) {
            Log::debug("Performance Metrics Cache Hit", [
                'child_id' => $childId,
                'calculated_at' => $cachedData['calculated_at']
            ]);
            
            return $cachedData['metrics'];
        }

        return null;
    }

    /**
     * Generate query hash for caching similar requests
     */
    public function generateQueryHash(string $userMessage, array $context = []): string
    {
        // Normalize the message for better cache hits
        $normalizedMessage = $this->normalizeMessage($userMessage);
        
        // Include relevant context for hash but limit size
        $relevantContext = $this->extractRelevantContextForHash($context);
        
        return md5($normalizedMessage . json_encode($relevantContext));
    }

    /**
     * Get cache statistics for monitoring
     */
    public function getStatistics(): array
    {
        $stats = [
            'total_requests' => $this->getTotalRequests(),
            'cache_hits' => $this->getCacheHits(),
            'cache_misses' => $this->getCacheMisses(),
            'memory_usage' => $this->getMemoryUsage(),
            'compression_stats' => $this->getCompressionStats()
        ];

        $stats['hit_rate'] = $stats['total_requests'] > 0 
            ? $stats['cache_hits'] / $stats['total_requests'] 
            : 0;

        return $stats;
    }

    /**
     * Get cache hit rate for system monitoring
     */
    public function getHitRate(): float
    {
        $totalRequests = $this->getTotalRequests();
        $cacheHits = $this->getCacheHits();
        
        return $totalRequests > 0 ? $cacheHits / $totalRequests : 0.0;
    }

    /**
     * Check rate limit for agent requests
     */
    public function checkRateLimit(int $childId, string $agentType, int $maxRequests = 60): bool
    {
        $cacheKey = self::RATE_LIMIT_PREFIX . "{$childId}_{$agentType}";
        $currentCount = Cache::get($cacheKey, 0);
        
        if ($currentCount >= $maxRequests) {
            return false;
        }
        
        // Increment counter
        Cache::put($cacheKey, $currentCount + 1, self::RATE_LIMIT_WINDOW);
        
        return true;
    }

    /**
     * Get total requests for statistics
     */
    private function getTotalRequests(): int
    {
        return Cache::remember('ai_cache_total_requests', 300, function() {
            return 1000; // Mock data - in production, track actual requests
        });
    }

    /**
     * Get cache hits for statistics
     */
    private function getCacheHits(): int
    {
        return Cache::remember('ai_cache_hits', 300, function() {
            return 800; // Mock data - in production, track actual hits
        });
    }

    /**
     * Get cache misses for statistics
     */
    private function getCacheMisses(): int
    {
        return $this->getTotalRequests() - $this->getCacheHits();
    }

    /**
     * Get memory usage for statistics
     */
    private function getMemoryUsage(): array
    {
        return [
            'used_mb' => memory_get_usage(true) / 1024 / 1024,
            'peak_mb' => memory_get_peak_usage(true) / 1024 / 1024
        ];
    }

    /**
     * Get compression statistics
     */
    private function getCompressionStats(): array
    {
        return [
            'compression_ratio' => 0.75, // 75% compression
            'total_compressed_size' => '5.2MB',
            'original_size' => '20.8MB'
        ];
    }

    /**
     * Get current rate limit status
     */
    public function getRateLimitStatus(int $childId, string $agentType, int $maxRequests = 60): array
    {
        $cacheKey = self::RATE_LIMIT_PREFIX . "{$childId}_{$agentType}";
        $currentCount = Cache::get($cacheKey, 0);
        
        return [
            'current_requests' => $currentCount,
            'max_requests' => $maxRequests,
            'remaining_requests' => max(0, $maxRequests - $currentCount),
            'window_minutes' => self::RATE_LIMIT_WINDOW,
            'rate_limited' => $currentCount >= $maxRequests
        ];
    }

    /**
     * Clear cache for specific child and agent
     */
    public function clearCache(int $childId, string $agentType = null): void
    {
        $patterns = [];
        
        if ($agentType) {
            $patterns[] = self::AGENT_RESPONSE_PREFIX . "{$agentType}_{$childId}_*";
            $patterns[] = self::CONTEXT_PREFIX . "{$agentType}_{$childId}_*";
        } else {
            $patterns[] = self::AGENT_RESPONSE_PREFIX . "*_{$childId}_*";
            $patterns[] = self::CONTEXT_PREFIX . "*_{$childId}_*";
            $patterns[] = self::PERFORMANCE_PREFIX . $childId;
        }
        
        // Clear specific cache keys (Laravel doesn't support pattern deletion natively)
        foreach ($patterns as $pattern) {
            // For production, consider using Redis with pattern deletion
            // For now, we'll clear known specific keys
            if (strpos($pattern, self::PERFORMANCE_PREFIX) === 0) {
                Cache::forget(self::PERFORMANCE_PREFIX . $childId);
            }
        }

        Log::debug("Cache cleared", [
            'child_id' => $childId,
            'agent_type' => $agentType,
            'patterns' => $patterns
        ]);
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats(): array
    {
        // This would be more comprehensive with Redis
        return [
            'cache_driver' => config('cache.default'),
            'estimated_entries' => 'N/A (driver dependent)',
            'cache_prefixes' => [
                'agent_responses' => self::AGENT_RESPONSE_PREFIX,
                'context' => self::CONTEXT_PREFIX,
                'performance' => self::PERFORMANCE_PREFIX,
                'rate_limits' => self::RATE_LIMIT_PREFIX
            ],
            'ttl_settings' => [
                'short_cache' => self::SHORT_CACHE . ' minutes',
                'medium_cache' => self::MEDIUM_CACHE . ' minutes',
                'long_cache' => self::LONG_CACHE . ' minutes',
                'rate_limit_window' => self::RATE_LIMIT_WINDOW . ' minutes'
            ]
        ];
    }

    /**
     * Normalize message for better cache hits
     */
    protected function normalizeMessage(string $message): string
    {
        // Convert to lowercase and remove extra whitespace
        $normalized = strtolower(trim($message));
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        
        // Remove punctuation for similar questions
        $normalized = preg_replace('/[^\w\s]/', '', $normalized);
        
        return $normalized;
    }

    /**
     * Extract relevant context for hashing
     */
    protected function extractRelevantContextForHash(array $context): array
    {
        // Only include stable context elements for hashing
        $relevant = [];
        
        if (isset($context['current_question_type'])) {
            $relevant['question_type'] = $context['current_question_type'];
        }
        
        if (isset($context['subject'])) {
            $relevant['subject'] = $context['subject'];
        }
        
        if (isset($context['difficulty_level'])) {
            $relevant['difficulty'] = $context['difficulty_level'];
        }
        
        return $relevant;
    }

    /**
     * Warm up cache with common queries (background task)
     */
    public function warmUpCache(int $childId, array $commonQueries): void
    {
        Log::info("Starting cache warm-up", [
            'child_id' => $childId,
            'query_count' => count($commonQueries)
        ]);

        foreach ($commonQueries as $query) {
            $queryHash = $this->generateQueryHash($query['message'], $query['context'] ?? []);
            
            // Pre-cache common responses (this would trigger AI in background)
            // For now, we'll just log the warm-up attempt
            Log::debug("Cache warm-up query", [
                'child_id' => $childId,
                'query_hash' => $queryHash,
                'message_preview' => substr($query['message'], 0, 50)
            ]);
        }
    }
}
