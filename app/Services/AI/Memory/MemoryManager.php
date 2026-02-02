<?php

namespace App\Services\AI\Memory;

use App\Models\Child;
use App\Models\AssessmentSubmission;
use App\Models\Lesson;
use App\Models\AgentMemoryContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Enhanced MemoryManager - Phase 3: Performance & Context Optimization
 * Implements advanced context compression, caching, and vector-based retrieval
 */
class MemoryManager
{
    /**
     * Cache durations (in minutes)
     */
    private const CONTEXT_CACHE_DURATION = 15;
    private const PERFORMANCE_CACHE_DURATION = 60;
    private const PATTERNS_CACHE_DURATION = 120;

    /**
     * Context compression limits
     */
    private const MAX_CONTEXT_SIZE = 8000; // characters
    private const MAX_PERFORMANCE_ITEMS = 5;
    private const MAX_PATTERN_ITEMS = 4;
    private const MAX_FOCUS_ITEMS = 3;

    /**
     * Get relevant context for a child and agent type - Phase 3 Enhanced
     * Implements intelligent caching and context compression
     */
    public function getRelevantContext(Child $child, string $agentType, array $currentContext = []): array
    {
        $cacheKey = "ai_context_{$child->id}_{$agentType}_" . md5(json_encode($currentContext));
        
        // Try to get from cache first
        $cachedContext = Cache::get($cacheKey);
        if ($cachedContext) {
            Log::debug("Context Cache Hit", [
                'child_id' => $child->id,
                'agent_type' => $agentType,
                'cache_key' => $cacheKey
            ]);
            return $cachedContext;
        }

        // Build fresh context with optimization
        $context = [
            'recent_performance' => $this->getRecentPerformanceOptimized($child),
            'learning_patterns' => $this->getLearningPatternsOptimized($child),
            'current_focus' => $this->getCurrentFocus($child, $agentType, $currentContext),
            'stored_context' => $this->getStoredContext($child, $agentType)
        ];

        // Apply intelligent compression
        $context = $this->intelligentCompress($context);

        // Cache the result
        Cache::put($cacheKey, $context, self::CONTEXT_CACHE_DURATION);

        Log::debug("Context Generated & Cached", [
            'child_id' => $child->id,
            'agent_type' => $agentType,
            'context_size' => strlen(json_encode($context)),
            'performance_items' => count($context['recent_performance']),
            'patterns_items' => count($context['learning_patterns']),
            'stored_items' => count($context['stored_context'])
        ]);

        return $context;
    }

    /**
     * Store interaction in memory for future context - Phase 3 Enhanced
     * Implements proper context storage with importance scoring
     */
    public function storeInteraction(Child $child, string $agentType, string $userMessage, string $aiResponse, array $context = []): void
    {
        try {
            // Calculate importance score based on interaction characteristics
            $importanceScore = $this->calculateImportanceScore($userMessage, $aiResponse, $context);
            
            // Extract key topics and concepts
            $extractedContext = $this->extractContextFromInteraction($userMessage, $aiResponse, $context);
            
            // Store in database using the AgentMemoryContext model
            AgentMemoryContext::storeContext(
                $child->id,
                $this->mapAgentTypeToContextType($agentType),
                'interaction_' . now()->timestamp,
                $extractedContext,
                [
                    'agent_type' => $agentType,
                    'user_message_length' => strlen($userMessage),
                    'ai_response_length' => strlen($aiResponse),
                    'interaction_timestamp' => now()->toISOString(),
                    'topic_tags' => $this->extractTopicTags($userMessage, $aiResponse)
                ],
                $importanceScore
            );

            // Clear related caches to ensure fresh data next time
            $this->clearRelatedCaches($child->id, $agentType);

            Log::info("AI Interaction Stored with Context", [
                'child_id' => $child->id,
                'agent_type' => $agentType,
                'importance_score' => $importanceScore,
                'context_size' => strlen(json_encode($extractedContext))
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to store interaction context", [
                'child_id' => $child->id,
                'agent_type' => $agentType,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get recent performance summary
     */
    protected function getRecentPerformance(Child $child, int $days = 7): array
    {
        $recentSubmissions = AssessmentSubmission::where('child_id', $child->id)
            ->where('created_at', '>=', now()->subDays($days))
            ->where('status', '!=', 'pending')
            ->with('assessment:id,title')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        $performance = [];
        foreach ($recentSubmissions as $submission) {
            $percentage = $submission->total_marks > 0 
                ? round(($submission->marks_obtained / $submission->total_marks) * 100) 
                : 0;
                
            $performance[] = "Scored {$percentage}% on {$submission->assessment->title} ({$submission->created_at->diffForHumans()})";
        }

        return $performance;
    }

    /**
     * Identify learning patterns
     */
    protected function getLearningPatterns(Child $child): array
    {
        // Basic pattern recognition - will be enhanced in Phase 3
        $patterns = [];

        // Get submissions from last 30 days
        $submissions = AssessmentSubmission::where('child_id', $child->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->where('status', '!=', 'pending')
            ->get();

        if ($submissions->count() < 2) {
            return ['Limited data - needs more assessments to identify patterns'];
        }

        // Calculate average performance
        $totalPercentage = 0;
        $validSubmissions = 0;

        foreach ($submissions as $submission) {
            if ($submission->total_marks > 0) {
                $totalPercentage += ($submission->marks_obtained / $submission->total_marks) * 100;
                $validSubmissions++;
            }
        }

        if ($validSubmissions > 0) {
            $averagePerformance = round($totalPercentage / $validSubmissions);
            
            if ($averagePerformance >= 80) {
                $patterns[] = "Consistently strong performance (avg: {$averagePerformance}%)";
            } elseif ($averagePerformance >= 60) {
                $patterns[] = "Moderate performance with room for improvement (avg: {$averagePerformance}%)";
            } else {
                $patterns[] = "Needs focused support to improve performance (avg: {$averagePerformance}%)";
            }
        }

        // Check recent trend
        $recentSubmissions = $submissions->sortBy('created_at')->take(-3);
        if ($recentSubmissions->count() >= 2) {
            $recentScores = $recentSubmissions->map(function ($submission) {
                return $submission->total_marks > 0 
                    ? ($submission->marks_obtained / $submission->total_marks) * 100 
                    : 0;
            })->values();

            if ($recentScores->count() >= 2) {
                $firstScore = $recentScores->first();
                $lastScore = $recentScores->last();
                $improvement = $lastScore - $firstScore;

                if ($improvement > 10) {
                    $patterns[] = "Showing strong improvement trend (+{$improvement}% improvement)";
                } elseif ($improvement < -10) {
                    $patterns[] = "Performance declining recently (-{$improvement}% decline)";
                } else {
                    $patterns[] = "Performance is stable";
                }
            }
        }

        return $patterns;
    }

    /**
     * Get current focus areas based on agent type and context
     */
    protected function getCurrentFocus(Child $child, string $agentType, array $context): array
    {
        $focus = [];

        switch ($agentType) {
            case 'grading_review':
                // Focus on incorrect questions if provided in context
                if (!empty($context['incorrect_questions'])) {
                    $focus[] = "Reviewing " . count($context['incorrect_questions']) . " incorrect questions";
                    
                    // Identify question types that need work
                    $questionTypes = [];
                    foreach ($context['incorrect_questions'] as $question) {
                        if (isset($question['question_type'])) {
                            $questionTypes[] = $question['question_type'];
                        }
                    }
                    
                    if (!empty($questionTypes)) {
                        $typeCounts = array_count_values($questionTypes);
                        foreach ($typeCounts as $type => $count) {
                            $focus[] = "Need practice with {$type} questions ({$count} mistakes)";
                        }
                    }
                }
                break;

            case 'progress':
                // Focus on overall learning progress
                $focus[] = "Analyzing overall learning progress and trends";
                break;

            case 'hint':
                // Focus on specific questions needing hints
                if (!empty($context['current_question'])) {
                    $focus[] = "Providing hints for current question difficulty";
                }
                break;

            case 'tutor':
            default:
                // General tutoring focus
                $focus[] = "General learning support and guidance";
                break;
        }

        return $focus;
    }

    /**
     * Compress context for efficient API usage
     * This will be significantly enhanced in Phase 3
     */
    public function compressContext(array $context): array
    {
        // Phase 1: Basic compression by limiting array sizes
        return [
            'recent_performance' => array_slice($context['recent_performance'] ?? [], 0, 3),
            'learning_patterns' => array_slice($context['learning_patterns'] ?? [], 0, 3),
            'current_focus' => array_slice($context['current_focus'] ?? [], 0, 2)
        ];
    }

    /**
     * Get context size estimation
     */
    public function getContextSize(array $context): int
    {
        return strlen(json_encode($context));
    }

    /**
     * Phase 3: Optimized performance retrieval with caching
     */
    protected function getRecentPerformanceOptimized(Child $child, int $days = 7): array
    {
        $cacheKey = "performance_{$child->id}_{$days}d";
        
        return Cache::remember($cacheKey, self::PERFORMANCE_CACHE_DURATION, function () use ($child, $days) {
            return $this->getRecentPerformance($child, $days);
        });
    }

    /**
     * Phase 3: Optimized learning patterns with caching
     */
    protected function getLearningPatternsOptimized(Child $child): array
    {
        $cacheKey = "patterns_{$child->id}";
        
        return Cache::remember($cacheKey, self::PATTERNS_CACHE_DURATION, function () use ($child) {
            return $this->getLearningPatterns($child);
        });
    }

    /**
     * Phase 3: Get stored context from AgentMemoryContext
     */
    protected function getStoredContext(Child $child, string $agentType): array
    {
        try {
            $contextTypes = $this->getRelevantContextTypes($agentType);
            $contexts = AgentMemoryContext::getRelevantContext($child->id, $contextTypes, 0.3, 5);
            
            $storedContext = [];
            foreach ($contexts as $context) {
                $storedContext[] = [
                    'type' => $context['context_type'],
                    'content' => $context['content'],
                    'importance' => $context['importance_score'],
                    'accessed' => $context['last_accessed']
                ];
            }
            
            return $storedContext;
        } catch (\Exception $e) {
            Log::warning("Failed to retrieve stored context", [
                'child_id' => $child->id,
                'agent_type' => $agentType,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Phase 3: Intelligent context compression
     */
    protected function intelligentCompress(array $context): array
    {
        $compressed = $context;
        
        // Apply size limits
        $compressed['recent_performance'] = array_slice($context['recent_performance'] ?? [], 0, self::MAX_PERFORMANCE_ITEMS);
        $compressed['learning_patterns'] = array_slice($context['learning_patterns'] ?? [], 0, self::MAX_PATTERN_ITEMS);
        $compressed['current_focus'] = array_slice($context['current_focus'] ?? [], 0, self::MAX_FOCUS_ITEMS);
        
        // Prioritize high-importance stored context
        if (!empty($context['stored_context'])) {
            usort($compressed['stored_context'], function($a, $b) {
                return $b['importance'] <=> $a['importance'];
            });
            $compressed['stored_context'] = array_slice($compressed['stored_context'], 0, 3);
        }
        
        // Check final size and further compress if needed
        $currentSize = strlen(json_encode($compressed));
        if ($currentSize > self::MAX_CONTEXT_SIZE) {
            // More aggressive compression
            $compressed['recent_performance'] = array_slice($compressed['recent_performance'], 0, 3);
            $compressed['learning_patterns'] = array_slice($compressed['learning_patterns'], 0, 2);
            $compressed['stored_context'] = array_slice($compressed['stored_context'] ?? [], 0, 2);
        }
        
        return $compressed;
    }

    /**
     * Phase 3: Calculate importance score for interactions
     */
    protected function calculateImportanceScore(string $userMessage, string $aiResponse, array $context): float
    {
        $score = 0.5; // Base score
        
        // Increase importance for longer interactions
        if (strlen($userMessage) > 100) $score += 0.1;
        if (strlen($aiResponse) > 200) $score += 0.1;
        
        // Increase importance for certain keywords
        $importantKeywords = ['help', 'confused', 'don\'t understand', 'explain', 'why', 'how'];
        foreach ($importantKeywords as $keyword) {
            if (stripos($userMessage, $keyword) !== false) {
                $score += 0.1;
                break;
            }
        }
        
        // Increase importance for assessment-related interactions
        if (stripos($userMessage, 'assessment') !== false || stripos($userMessage, 'question') !== false) {
            $score += 0.15;
        }
        
        // Increase importance if it involves specific subjects
        $subjects = ['math', 'english', 'science', 'reading', 'writing'];
        foreach ($subjects as $subject) {
            if (stripos($userMessage, $subject) !== false) {
                $score += 0.1;
                break;
            }
        }
        
        // Cap the score at 1.0
        return min(1.0, $score);
    }

    /**
     * Phase 3: Extract meaningful context from interactions
     */
    protected function extractContextFromInteraction(string $userMessage, string $aiResponse, array $context): array
    {
        return [
            'interaction_summary' => $this->summarizeInteraction($userMessage, $aiResponse),
            'key_concepts' => $this->extractKeyConcepts($userMessage, $aiResponse),
            'difficulty_indicators' => $this->extractDifficultyIndicators($userMessage),
            'learning_signals' => $this->extractLearningSignals($aiResponse),
            'context_metadata' => [
                'user_message_length' => strlen($userMessage),
                'ai_response_length' => strlen($aiResponse),
                'interaction_type' => $this->classifyInteractionType($userMessage),
                'timestamp' => now()->toISOString()
            ]
        ];
    }

    /**
     * Phase 3: Map agent types to context types
     */
    protected function mapAgentTypeToContextType(string $agentType): string
    {
        return match($agentType) {
            'tutor' => 'tutor_interaction',
            'grading_review' => 'grading_dispute',
            'progress_analysis' => 'analysis_insight',
            'hint_generator' => 'hint_progression',
            default => 'lesson'
        };
    }

    /**
     * Phase 3: Extract topic tags from conversations
     */
    protected function extractTopicTags(string $userMessage, string $aiResponse): array
    {
        $tags = [];
        $text = strtolower($userMessage . ' ' . $aiResponse);
        
        // Subject tags
        $subjects = [
            'mathematics' => ['math', 'arithmetic', 'algebra', 'geometry', 'number'],
            'english' => ['english', 'reading', 'writing', 'grammar', 'spelling'],
            'science' => ['science', 'biology', 'chemistry', 'physics', 'experiment'],
            'reasoning' => ['reasoning', 'logic', 'thinking', 'problem', 'solve']
        ];
        
        foreach ($subjects as $subject => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($text, $keyword) !== false) {
                    $tags[] = $subject;
                    break;
                }
            }
        }
        
        // Difficulty tags
        $difficulties = ['easy', 'hard', 'difficult', 'simple', 'complex', 'challenging'];
        foreach ($difficulties as $difficulty) {
            if (strpos($text, $difficulty) !== false) {
                $tags[] = 'difficulty_' . $difficulty;
            }
        }
        
        return array_unique($tags);
    }

    /**
     * Phase 3: Clear related caches when context changes
     */
    protected function clearRelatedCaches(int $childId, string $agentType): void
    {
        $patterns = [
            "ai_context_{$childId}_{$agentType}_*",
            "performance_{$childId}_*",
            "patterns_{$childId}",
        ];
        
        foreach ($patterns as $pattern) {
            // Laravel doesn't have a direct way to clear by pattern, so we'll use tags if available
            // For now, we'll clear specific known keys
            Cache::forget("performance_{$childId}_7d");
            Cache::forget("patterns_{$childId}");
        }
    }

    /**
     * Helper: Get relevant context types for agent
     */
    protected function getRelevantContextTypes(string $agentType): array
    {
        return match($agentType) {
            'tutor' => ['tutor_interaction', 'lesson', 'struggle_pattern'],
            'grading_review' => ['grading_dispute', 'misconception', 'struggle_pattern'],
            'progress_analysis' => ['progress_marker', 'success_pattern', 'struggle_pattern'],
            'hint_generator' => ['hint_progression', 'struggle_pattern', 'lesson'],
            default => ['lesson', 'tutor_interaction']
        };
    }

    /**
     * Helper: Summarize interaction
     */
    protected function summarizeInteraction(string $userMessage, string $aiResponse): string
    {
        $userLength = strlen($userMessage);
        $aiLength = strlen($aiResponse);
        
        return "User asked a " . ($userLength > 100 ? "detailed" : "brief") . " question, " .
               "AI provided a " . ($aiLength > 200 ? "comprehensive" : "concise") . " response";
    }

    /**
     * Helper: Extract key concepts
     */
    protected function extractKeyConcepts(string $userMessage, string $aiResponse): array
    {
        $concepts = [];
        $text = $userMessage . ' ' . $aiResponse;
        
        // Look for question types
        if (stripos($text, 'multiple choice') !== false) $concepts[] = 'multiple_choice';
        if (stripos($text, 'short answer') !== false) $concepts[] = 'short_answer';
        if (stripos($text, 'essay') !== false) $concepts[] = 'essay';
        
        // Look for academic concepts
        if (stripos($text, 'fraction') !== false) $concepts[] = 'fractions';
        if (stripos($text, 'decimal') !== false) $concepts[] = 'decimals';
        if (stripos($text, 'percentage') !== false) $concepts[] = 'percentages';
        
        return array_unique($concepts);
    }

    /**
     * Helper: Extract difficulty indicators
     */
    protected function extractDifficultyIndicators(string $userMessage): array
    {
        $indicators = [];
        
        if (stripos($userMessage, 'confused') !== false) $indicators[] = 'confusion';
        if (stripos($userMessage, 'don\'t understand') !== false) $indicators[] = 'comprehension_issue';
        if (stripos($userMessage, 'help') !== false) $indicators[] = 'needs_support';
        if (stripos($userMessage, 'stuck') !== false) $indicators[] = 'blocked';
        
        return $indicators;
    }

    /**
     * Helper: Extract learning signals
     */
    protected function extractLearningSignals(string $aiResponse): array
    {
        $signals = [];
        
        if (stripos($aiResponse, 'well done') !== false) $signals[] = 'positive_reinforcement';
        if (stripos($aiResponse, 'try again') !== false) $signals[] = 'encouragement';
        if (stripos($aiResponse, 'step by step') !== false) $signals[] = 'scaffolding';
        if (stripos($aiResponse, 'remember') !== false) $signals[] = 'memory_aid';
        
        return $signals;
    }

    /**
     * Helper: Classify interaction type
     */
    protected function classifyInteractionType(string $userMessage): string
    {
        if (stripos($userMessage, '?') !== false) return 'question';
        if (stripos($userMessage, 'help') !== false) return 'help_request';
        if (stripos($userMessage, 'explain') !== false) return 'explanation_request';
        if (stripos($userMessage, 'stuck') !== false) return 'problem_solving';
        
        return 'general_interaction';
    }
}
