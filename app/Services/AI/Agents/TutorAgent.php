<?php

namespace App\Services\AI\Agents;

use App\Models\Child;
use App\Models\Assessment;
use App\Models\AssessmentSubmission;
use App\Models\Lesson;
use App\Services\AI\Memory\MemoryManager;
use App\Services\AI\DataRequirementsAnalyzer;
use App\Services\AI\ContextDataFetcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

/**
 * TutorAgent - General purpose tutoring and homework help
 * Integrates with existing ChatWidget.jsx in parent portal
 */
class TutorAgent extends AbstractAgent
{
    protected string $agentType = 'tutor';
    
    protected array $tools = [
        'homework_help',
        'concept_explanation', 
        'study_guidance',
        'motivation_support',
        'learning_strategies'
    ];

    /**
     * Main processing method - required by AbstractAgent
     * Uses two-stage AI system: 1) Analyze data requirements 2) Fetch & respond
     */
    public function process(Child $child, array $context = []): array
    {
        $startTime = microtime(true);
        
        try {
            $message = $context['message'] ?? '';
            
            if (empty($message)) {
                return $this->createErrorResponse('No message provided');
            }

            // Get session for this child and agent
            $session = $this->getOrCreateSession($child);
            
            // LOG: Request initiated
            Log::info('═══════════════════════════════════════════════════');
            Log::info('[AI TUTOR] Request Initiated', [
                'child_id' => $child->id,
                'child_name' => $child->child_name,
                'user_message' => $message,
                'message_length' => strlen($message),
                'session_id' => $session->id,
                'timestamp' => now()->toISOString()
            ]);

            // Get conversation history for context-aware analysis
            $conversationHistory = $this->getConversationHistory($session, 10);
            
            // STAGE 1: Analyze data requirements (with conversation history)
            $analyzer = app(DataRequirementsAnalyzer::class);
            $requirements = $analyzer->analyze($message, $child, $conversationHistory);
            
            // LOG: Stage 1 - Data Requirements Analysis
            Log::info('[AI TUTOR] Stage 1: Data Requirements Analysis', [
                'child_id' => $child->id,
                'user_message' => $message,
                'required_data' => $requirements['required_data'],
                'filters' => $requirements['filters'] ?? [],
                'reasoning' => $requirements['reasoning'],
                'analysis_timestamp' => now()->toISOString()
            ]);
            
            // STAGE 2: Fetch only required data
            $fetcher = app(ContextDataFetcher::class);
            $specificContext = $fetcher->fetch($child, $requirements);
            
            // LOG: Stage 2 - Context Data Fetched (with actual data)
            Log::info('[AI TUTOR] Stage 2: Context Data Fetched', [
                'child_id' => $child->id,
                'data_sources' => array_keys($specificContext),
                'fetched_data' => $specificContext,
                'total_size' => strlen(json_encode($specificContext)),
                'fetch_timestamp' => now()->toISOString()
            ]);
            
            // Get session for this child and agent
            $session = $this->getOrCreateSession($child);
            
            // Build enhanced context with ONLY what's needed
            $enhancedContext = [
                'child_name' => $child->child_name,
                'data_sources' => $requirements['required_data'],
                'fetched_data' => $specificContext,
                'reasoning' => $requirements['reasoning']
            ];

            // Generate AI response
            $aiResponse = $this->generateAIResponse($child, $message, $enhancedContext);

            // Calculate processing time
            $processingTime = microtime(true) - $startTime;
            
            // LOG: Request completed successfully
            Log::info('[AI TUTOR] Request Completed Successfully', [
                'child_id' => $child->id,
                'user_message' => $message,
                'ai_response' => $aiResponse,
                'data_sources_used' => $requirements['required_data'],
                'filters_applied' => $requirements['filters'] ?? [],
                'context_size_bytes' => strlen(json_encode($specificContext)),
                'message_length' => strlen($message),
                'response_length' => strlen($aiResponse),
                'processing_time_seconds' => round($processingTime, 3),
                'timestamp' => now()->toISOString()
            ]);
            Log::info('═══════════════════════════════════════════════════');

            return [
                'success' => true,
                'response' => $aiResponse,
                'agent_type' => $this->agentType,
                'child_id' => $child->id,
                'session_id' => $session->id,
                'metadata' => [
                    'data_sources_used' => $requirements['required_data'],
                    'response_type' => 'tutoring',
                    'confidence' => 0.9,
                    'filters_applied' => $requirements['filters'] ?? [],
                    'processing_time' => round($processingTime, 3)
                ]
            ];

        } catch (\Exception $e) {
            $processingTime = microtime(true) - $startTime;
            
            Log::error('[AI TUTOR] Request Failed', [
                'child_id' => $child->id,
                'message' => $context['message'] ?? '',
                'error' => $e->getMessage(),
                'processing_time_seconds' => round($processingTime, 3),
                'trace' => $e->getTraceAsString()
            ]);
            Log::info('═══════════════════════════════════════════════════');
            Log::error('TutorAgent error', [
                'child_id' => $child->id,
                'message' => $context['message'] ?? '',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->createErrorResponse('Something went wrong. Please try again.');
        }
    }

    /**
     * Generate system prompt for the tutor agent - required by AbstractAgent
     */
    protected function getSystemPrompt(): string
    {
        return "You are a helpful AI tutor for primary school students (ages 6-11). Your role is to:

TEACHING APPROACH:
- Provide clear, age-appropriate explanations
- Break down complex topics into simple, manageable steps
- Use encouraging and supportive language
- Ask questions to check understanding
- Provide examples and analogies that children can relate to
- Celebrate learning progress and efforts

TUTORING GUIDELINES:
1. Always address the student by their name when possible
2. Keep explanations simple but accurate
3. Use step-by-step breakdowns for problem-solving
4. Provide practice suggestions and examples
5. If helping with homework, guide them to discover answers rather than giving direct solutions
6. End responses with encouragement or a follow-up question
7. Adapt your language to the child's grade level and understanding

SUBJECT AREAS:
- Mathematics (arithmetic, basic algebra, geometry)
- English (reading, writing, grammar, vocabulary)
- Science (basic concepts, experiments, natural world)
- Humanities (history, geography, cultures)
- Reasoning skills (verbal and non-verbal)

RESPONSE STYLE:
- Friendly and encouraging
- Clear and concise
- Educational but fun
- Supportive of the learning process
- Motivational and confidence-building";
    }

    /**
     * Get comprehensive academic context (enhanced from old ChatController)
     */
    private function getRecentPerformance(Child $child): array
    {
        try {
            // Enhanced assessment submissions with detailed context
            $recentSubmissions = AssessmentSubmission::with('assessment:id,title,questions_json')
                ->where('child_id', $child->id)
                ->where('status', 'graded')
                ->where('created_at', '>=', now()->subWeeks(4))
                ->orderBy('created_at', 'desc')
                ->take(10)
                ->get();

            $now = now();

            // Get access records for this child (correct approach from PortalController)
            $accessRecords = \App\Models\Access::where('child_id', $child->id)
                ->where('access', true)
                ->where('payment_status', 'paid')
                ->get();

            // Extract lesson and assessment IDs from access records
            $lessonIds = collect();
            $assessmentIds = collect();
            foreach ($accessRecords as $access) {
                if ($access->lesson_id) {
                    $lessonIds->push($access->lesson_id);
                }
                if ($access->lesson_ids) {
                    foreach ((array) $access->lesson_ids as $lid) {
                        $lessonIds->push($lid);
                    }
                }
                if ($access->assessment_id) {
                    $assessmentIds->push($access->assessment_id);
                }
                if ($access->assessment_ids) {
                    foreach ((array) $access->assessment_ids as $aid) {
                        $assessmentIds->push($aid);
                    }
                }
            }
            $lessonIds = $lessonIds->unique()->values();
            $assessmentIds = $assessmentIds->unique()->values();
            Log::info('Access records fetched', [
                'child_id' => $child->id,
                'lesson_ids' => $lessonIds,
                'assessment_ids' => $assessmentIds
            ]);
            // Get upcoming lessons based on access
            $upcomingLessons = Lesson::whereIn('id', $lessonIds)
                ->where('start_time', '>=', $now)
                ->orderBy('start_time')
                ->limit(5)
                ->get(['title', 'start_time', 'description', 'lesson_type', 'lesson_mode']);

            // Get upcoming assessments based on access  
            $upcomingAssessments = Assessment::whereIn('id', $assessmentIds)
                ->where('availability', '>=', $now)
                ->orderBy('availability')
                ->limit(5)
                ->get(['title', 'availability', 'description', 'type', 'deadline']);

            Log::info('Enhanced context fetched', [
                'child_id' => $child->id,
                'submissions_count' => $recentSubmissions->count(),
                'lessons_count' => $upcomingLessons->count(),
                'assessments_count' => $upcomingAssessments->count()
            ]);

            // Build comprehensive performance profile (similar to old ChatController)
            $profileLines = ["Child {$child->child_name} with id #{$child->id} Academic Overview:\n"];
            
            // Assessment History
            foreach ($recentSubmissions as $sub) {
                $profileLines[] = "- {$sub->assessment->title} "
                  . "(Scored {$sub->marks_obtained}/{$sub->total_marks} "
                  . "on {$sub->finished_at->toDateTimeString()}):";
                
                // Include questions context if available
                if ($sub->assessment->questions_json) {
                    $profileLines[] = "  Questions: " . json_encode($sub->assessment->questions_json);
                }
                if ($sub->answers_json) {
                    $profileLines[] = "  Student Answers: " . json_encode($sub->answers_json) . "\n";
                }
            }

            // Upcoming Lessons
            $profileLines[] = "\nUpcoming Lessons:\n";
            foreach ($upcomingLessons as $lesson) {
                $profileLines[] = "- {$lesson->title} on " . $lesson->start_time->toDateTimeString();
                if ($lesson->description) {
                    $profileLines[] = "  Details: {$lesson->description}";
                }
            }

            // Upcoming Assessments
            $profileLines[] = "\nUpcoming Assessments:\n";
            foreach ($upcomingAssessments as $assessment) {
                $profileLines[] = "- {$assessment->title} on " . $assessment->availability->toDateTimeString();
                if ($assessment->description) {
                    $profileLines[] = "  Details: {$assessment->description}";
                }
            }
            Log::info('profile lines', ['lines' => $profileLines]);
            // Calculate performance statistics
            $totalScore = 0;
            $totalMarks = 0;
            $topics = [];

            foreach ($recentSubmissions as $submission) {
                if ($submission->marks_obtained !== null && $submission->total_marks > 0) {
                    $totalScore += $submission->marks_obtained;
                    $totalMarks += $submission->total_marks;
                }
                
                if ($submission->assessment && $submission->assessment->title) {
                    $topics[] = $submission->assessment->title;
                }
            }

            $performanceData = [
                'comprehensive_profile: ' . implode("\n", $profileLines),
                'average_score: ' . ($totalMarks > 0 ? round(($totalScore / $totalMarks) * 100, 1) . '%' : 'No data'),
                'recent_topics: ' . implode(', ', array_unique(array_slice($topics, 0, 3))),
                'submissions_count: ' . $recentSubmissions->count(),
                'upcoming_lessons: ' . $upcomingLessons->count(),
                'upcoming_assessments: ' . $upcomingAssessments->count(),
                'last_activity: ' . ($recentSubmissions->first()?->created_at?->diffForHumans() ?? 'None')
            ];

            return $performanceData;

        } catch (\Exception $e) {
            Log::warning('Failed to get enhanced performance data', [
                'child_id' => $child->id,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Extract learning patterns from child's history
     */
    private function extractLearningPatterns(Child $child): array
    {
        // This would be enhanced with memory context in future iterations
        return [
            'learning_style: Visual and step-by-step explanations work well',
            'engagement: Responds well to encouragement and examples',
            'challenge_areas: Identified from recent performance patterns'
        ];
    }

    /**
     * Identify current focus areas from the message
     */
    private function identifyCurrentFocus(string $message): array
    {
        $topics = $this->extractTopics($message);
        $focus = [];
        
        foreach ($topics as $topic) {
            $focus[] = "Currently working on: {$topic}";
        }
        
        if (empty($focus)) {
            $focus[] = 'General learning support and guidance';
        }
        
        return $focus;
    }


    /**
     * Extract topic areas from the message
     */
    private function extractTopics(string $message): array
    {
        $message = strtolower($message);
        $topics = [];
        
        // Define topic keywords
        $topicMap = [
            'mathematics' => ['math', 'mathematics', 'algebra', 'geometry', 'arithmetic', 'calculation', 'numbers'],
            'english' => ['english', 'reading', 'writing', 'grammar', 'vocabulary', 'essay', 'comprehension'],
            'science' => ['science', 'biology', 'chemistry', 'physics', 'experiment', 'hypothesis'],
            'history' => ['history', 'historical', 'ancient', 'medieval', 'modern', 'timeline'],
            'geography' => ['geography', 'countries', 'capitals', 'maps', 'continents', 'climate'],
            'verbal_reasoning' => ['verbal reasoning', 'analogies', 'synonyms', 'antonyms', 'word relationships'],
            'non_verbal_reasoning' => ['non-verbal', 'patterns', 'sequences', 'shapes', 'spatial']
        ];
        
        foreach ($topicMap as $topic => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($message, $keyword) !== false) {
                    $topics[] = $topic;
                    break;
                }
            }
        }
        
        return array_unique($topics);
    }

    /**
     * Assess difficulty level of the question
     */
    private function assessDifficulty(string $message): string
    {
        $message = strtolower($message);
        
        // Simple heuristics for difficulty assessment
        if (preg_match('/\b(help|don\'t understand|confused|stuck|hard|difficult)\b/', $message)) {
            return 'high';
        }
        
        if (preg_match('/\b(explain|how|what|why|when|where)\b/', $message)) {
            return 'medium';
        }
        
        return 'low';
    }

    /**
     * Identify the learning focus area
     */
    private function identifyLearningFocus(string $message): string
    {
        $message = strtolower($message);
        
        if (preg_match('/\b(homework|assignment|practice)\b/', $message)) {
            return 'homework_support';
        }
        
        if (preg_match('/\b(explain|understand|concept|theory)\b/', $message)) {
            return 'concept_explanation';
        }
        
        if (preg_match('/\b(study|prepare|exam|test|revision)\b/', $message)) {
            return 'exam_preparation';
        }
        
        if (preg_match('/\b(solve|problem|solution|method)\b/', $message)) {
            return 'problem_solving';
        }
        
        return 'general_inquiry';
    }

    /**
     * Create error response
     */
    private function createErrorResponse(string $message): array
    {
        return [
            'success' => false,
            'error' => $message,
            'response' => "I'm having trouble right now, but don't worry! Please try asking your question again in a moment.",
            'agent_type' => $this->agentType
        ];
    }
}
