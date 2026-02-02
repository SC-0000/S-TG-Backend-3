<?php

namespace App\Http\Controllers;

use App\Models\Child;
use App\Models\AIAgentSession;
use App\Models\Assessment;
use App\Models\AssessmentSubmission;
use App\Models\Question;
use App\Services\AI\AgentOrchestrator;
use App\Services\AI\Cache\AIPerformanceCache;
use App\Jobs\ProcessHeavyAITask;
use App\Services\AI\Agents\TutorAgent;
use App\Services\AI\Agents\GradingReviewAgent;
use App\Services\AI\Agents\ProgressAnalysisAgent;
use App\Services\AI\Agents\HintGeneratorAgent;
use App\Http\Requests\AIGradingReviewRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

/**
 * AIAgentController - Main API for AI agent interactions
 * Integrates with existing frontend components
 */
class AIAgentController extends Controller
{
    protected AgentOrchestrator $orchestrator;
    protected AIPerformanceCache $cache;

    public function __construct(AgentOrchestrator $orchestrator, AIPerformanceCache $cache)
    {
        $this->orchestrator = $orchestrator;
        $this->cache = $cache;
    }

    /**
     * General tutoring chat endpoint
     * Route: POST /ai/tutor/chat
     * Frontend: ChatWidget.jsx
     */
    public function tutorChat(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'child_id' => 'required|integer|exists:children,id',
            'message' => 'required|string|max:2000',
            'session_id' => 'nullable|integer|exists:ai_agent_sessions,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid input',
                'details' => $validator->errors()
            ], 422);
        }

        try {
            $child = Child::findOrFail($request->child_id);
            
            // Check child access permissions
            if (!$this->canAccessChild($child)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Access denied'
                ], 403);
            }

            $agent = new TutorAgent();
            $context = [
                'message' => $request->message,
                'session_id' => $request->session_id
            ];

            $result = $agent->process($child, $context);

            return response()->json($result);

        } catch (\Exception $e) {
            Log::error('Tutor chat error', [
                'child_id' => $request->child_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Something went wrong. Please try again.',
                'response' => "I'm having trouble right now, but don't worry! Please try asking your question again in a moment."
            ], 500);
        }
    }

    /**
     * Grading review endpoint for explaining mistakes
     * Route: POST /ai/grading/review
     * Frontend: Submissions/Show.jsx
     */
    public function gradingReview(AIGradingReviewRequest $request): JsonResponse
    {
        try {
            $child = Child::findOrFail($request->child_id);
            
            if (!$this->canAccessChild($child)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Access denied'
                ], 403);
            }

            // Use validated and sanitized context
            $contextualData = $request->sanitizedContext();
            
            // Validate ownership - ensure child_id in context matches request
            if (isset($contextualData['child_id']) && $contextualData['child_id'] != $request->child_id) {
                Log::warning('Child ID mismatch', [
                    'request' => $request->child_id,
                    'context' => $contextualData['child_id']
                ]);
                
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid context data'
                ], 422);
            }
            
            // Validate submission ownership if provided
            if (isset($contextualData['submission_id'])) {
                $submission = AssessmentSubmission::find($contextualData['submission_id']);
                if (!$submission || $submission->child_id != $request->child_id) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Invalid submission access'
                    ], 403);
                }
            }

            $agent = new GradingReviewAgent();
            
            // Build safe context
            $context = [
                'submission_id' => $contextualData['submission_id'] ?? null,
                'message' => $request->message ?? $contextualData['message'] ?? 'Please explain this answer to me',
                // Include only validated context data
                ...$contextualData
            ];

            $result = $agent->process($child, $context);

            return response()->json($result);

        } catch (\Exception $e) {
            Log::error('Grading review error', [
                'child_id' => $request->child_id ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Something went wrong while analyzing your answer.',
                'response' => "I'm having trouble analyzing this answer right now. Please try again in a moment, or ask your teacher for help."
            ], 500);
        }
    }

    /**
     * Progress analysis endpoint for learning insights
     * Route: POST /ai/progress/analyze
     * Frontend: MySubmissions.jsx, Assessment Reports
     */
    public function progressAnalysis(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'child_id' => 'required|integer|exists:children,id',
            'analysis_type' => 'nullable|string|in:overall,subject,timeframe,trend',
            'timeframe' => 'nullable|string|in:1week,2weeks,4weeks,8weeks,3months,6months',
            'subject' => 'nullable|string|max:100',
            'message' => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid input',
                'details' => $validator->errors()
            ], 422);
        }

        try {
            $child = Child::findOrFail($request->child_id);
            
            if (!$this->canAccessChild($child)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Access denied'
                ], 403);
            }

            $agent = new ProgressAnalysisAgent();
            $context = [
                'analysis_type' => $request->analysis_type ?? 'overall',
                'timeframe' => $request->timeframe ?? '4weeks',
                'subject' => $request->subject,
                'message' => $request->message ?? 'Please analyze my learning progress'
            ];

            $result = $agent->process($child, $context);

            return response()->json($result);

        } catch (\Exception $e) {
            Log::error('Hint generation error', [
                'child_id' => $request->child_id ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Something went wrong while analyzing your progress.',
                'response' => "I'm having trouble analyzing your progress right now. Please try again in a moment."
            ], 500);
        }
    }

    /**
     * Hint generation endpoint for contextual help
     * Route: POST /ai/hints/generate
     * Frontend: HintLoop.jsx
     */
    public function generateHint(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'child_id' => 'required|integer|exists:children,id',
            'question_id' => 'required|integer|exists:questions,id',
            'current_answer' => 'nullable|string|max:1000',
            'hint_level' => 'nullable|integer|min:1|max:3',
            'previous_hints' => 'nullable|array',
            'previous_hints.*' => 'string|max:500',
            'message' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid input',
                'details' => $validator->errors()
            ], 422);
        }

        try {
            $child = Child::findOrFail($request->child_id);
            
            if (!$this->canAccessChild($child)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Access denied'
                ], 403);
            }

            $agent = new HintGeneratorAgent();
            
            // Build enhanced context with all the rich data from frontend
            $context = [
                'question_id' => $request->question_id,
                'current_answer' => $request->current_answer ?? '',
                'hint_level' => $request->hint_level ?? 1,
                'previous_hints' => $request->previous_hints ?? [],
                'message' => $request->message ?? '',
                // Add the rich context data from frontend
                'question_text' => $request->input('context.question_text', ''),
                'question_type' => $request->input('context.question_type', ''),
                'student_answer' => $request->input('context.student_answer', ''),
                'correct_answer' => $request->input('context.correct_answer', ''),
                'current_attempt' => $request->input('context.current_attempt', '')
            ];

            $result = $agent->process($child, $context);

            return response()->json($result);

        } catch (\Exception $e) {
            Log::error('Hint generation error', [
                'child_id' => $request->child_id ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Something went wrong while generating your hint.',
                'response' => "I'm having trouble processing your request right now. Please try again in a moment, or contact support if the issue continues.",
                'debug_info' => [
                    'error_type' => get_class($e),
                    'error_message' => $e->getMessage(),
                    'error_file' => $e->getFile(),
                    'error_line' => $e->getLine()
                ]
            ], 500);
        }
    }

    /**
     * Get data options for AI Console data selection
     * Route: GET /ai/data-options/{child_id}
     * Frontend: DataSelectionPanel.jsx
     */
    public function getDataOptions(int $childId): JsonResponse
    {
        try {
            $child = Child::findOrFail($childId);
            
            Log::info('AI Data Options Request', [
                'child_id' => $childId,
                'child_name' => $child->name ?? 'Unknown',
                'user_id' => Auth::id(),
                'user_role' => Auth::user()?->role
            ]);
            
            if (!$this->canAccessChild($child)) {
                Log::warning('Access denied for child', [
                    'child_id' => $childId,
                    'user_id' => Auth::id()
                ]);
                return response()->json([
                    'success' => false,
                    'error' => 'Access denied'
                ], 403);
            }

            // Get assessments for this child
            $assessments = Assessment::whereIn('id', function($query) use ($childId) {
                $query->select('assessment_id')
                      ->from('assessment_submissions')
                      ->where('child_id', $childId);
            })
            ->with(['category', 'lesson']) // Load relationships for subject info
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get(['id', 'title', 'journey_category_id', 'lesson_id', 'created_at'])
            ->map(function($assessment) {
                // Get subject from category or lesson, fallback to 'General'
                $subject = 'General';
                if ($assessment->category) {
                    $subject = $assessment->category->name ?? 'General';
                } elseif ($assessment->lesson) {
                    $subject = $assessment->lesson->title ?? 'General';
                }
                
                return [
                    'id' => $assessment->id,
                    'title' => $assessment->title,
                    'subject' => $subject,
                    'created_at' => $assessment->created_at->toISOString()
                ];
            });

            // Get submissions for this child
            $submissions = AssessmentSubmission::where('child_id', $childId)
                ->with('assessment:id,title')
                ->orderBy('finished_at', 'desc')
                ->limit(100)
                ->get(['id', 'assessment_id', 'total_marks', 'marks_obtained', 'status', 'created_at', 'finished_at'])
                ->map(function($submission) {
                    // Calculate percentage from marks
                    $percentage = 0;
                    if ($submission->total_marks && $submission->total_marks > 0) {
                        $percentage = round(($submission->marks_obtained / $submission->total_marks) * 100, 1);
                    }
                    
                    return [
                        'id' => $submission->id,
                        'assessment_id' => $submission->assessment_id,
                        'assessment_title' => $submission->assessment?->title ?? 'Unknown Assessment',
                        'percentage' => $percentage,
                        'total_marks' => $submission->total_marks,
                        'marks_obtained' => $submission->marks_obtained,
                        'status' => $submission->status ?? 'completed',
                        'created_at' => $submission->created_at->toISOString(),
                        'finished_at' => $submission->finished_at?->toISOString()
                    ];
                });

            // Get questions from assessments this child has attempted
            $questions = Question::whereIn('id', function($query) use ($childId) {
                $query->select('question_id')
                      ->from('assessment_submission_items')
                      ->whereIn('submission_id', function($subQuery) use ($childId) {
                          $subQuery->select('id')
                                   ->from('assessment_submissions')
                                   ->where('child_id', $childId);
                      })
                      ->whereNotNull('question_id');
            })
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get(['id', 'title', 'difficulty_level', 'category'])
            ->map(function($question) {
                return [
                    'id' => $question->id,
                    'title' => $question->title,
                    'difficulty_level' => $question->difficulty_level ?? 1,
                    'category' => $question->category ?? 'General'
                ];
            });

            // Log actual query results
            Log::info('Database query results', [
                'child_id' => $childId,
                'assessments_count' => $assessments->count(),
                'submissions_count' => $submissions->count(),
                'questions_count' => $questions->count()
            ]);

            // If no real data exists, add some sample data for testing
            if ($assessments->isEmpty() && $submissions->isEmpty() && $questions->isEmpty()) {
                Log::info('No real data found, adding sample data for testing', ['child_id' => $childId]);
                
                // Add sample assessments
                $assessments = collect([
                    [
                        'id' => 1,
                        'title' => 'Sample Math Assessment',
                        'subject' => 'Mathematics',
                        'created_at' => now()->toISOString()
                    ],
                    [
                        'id' => 2,
                        'title' => 'English Reading Comprehension',
                        'subject' => 'English',
                        'created_at' => now()->subDays(2)->toISOString()
                    ]
                ]);

                // Add sample submissions
                $submissions = collect([
                    [
                        'id' => 1,
                        'assessment_id' => 1,
                        'assessment_title' => 'Sample Math Assessment',
                        'percentage' => 85,
                        'status' => 'completed',
                        'created_at' => now()->toISOString(),
                        'finished_at' => now()->toISOString()
                    ],
                    [
                        'id' => 2,
                        'assessment_id' => 2,
                        'assessment_title' => 'English Reading Comprehension',
                        'percentage' => 78,
                        'status' => 'completed',
                        'created_at' => now()->subDays(1)->toISOString(),
                        'finished_at' => now()->subDays(1)->toISOString()
                    ]
                ]);

                // Add sample questions
                $questions = collect([
                    [
                        'id' => 1,
                        'title' => 'What is 2 + 2?',
                        'difficulty_level' => 1,
                        'category' => 'Mathematics'
                    ],
                    [
                        'id' => 2,
                        'title' => 'Explain the main theme of the story',
                        'difficulty_level' => 3,
                        'category' => 'English'
                    ]
                ]);
            }

            // Get unique subjects from assessments and questions
            $subjects = collect()
                ->merge($assessments->pluck('subject'))
                ->merge($questions->pluck('category'))
                ->filter()
                ->unique()
                ->values()
                ->sort()
                ->take(20);

            $response = [
                'success' => true,
                'assessments' => $assessments->values()->toArray(),
                'submissions' => $submissions->values()->toArray(),
                'questions' => $questions->values()->toArray(),
                'subjects' => $subjects->toArray(),
                'counts' => [
                    'assessments' => $assessments->count(),
                    'submissions' => $submissions->count(),
                    'questions' => $questions->count(),
                    'subjects' => $subjects->count()
                ],
                'debug_info' => [
                    'child_id' => $childId,
                    'child_name' => $child->name ?? 'Unknown',
                    'timestamp' => now()->toISOString(),
                    'has_sample_data' => $assessments->count() > 0 && $assessments->first()['id'] === 1
                ]
            ];

            Log::info('Returning AI data options response', [
                'child_id' => $childId,
                'response_counts' => $response['counts']
            ]);

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('Get data options error', [
                'child_id' => $childId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve data options',
                'debug' => [
                    'child_id' => $childId,
                    'error_message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Get conversation history for timeline display
     * Route: GET /ai/conversations/{child_id}
     * Frontend: ConversationTimeline.jsx
     */
    public function getConversations(int $childId): JsonResponse
    {
        try {
            $child = Child::findOrFail($childId);
            
            if (!$this->canAccessChild($child)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Access denied'
                ], 403);
            }

            $agentType = request('agent_type');
            $timeRange = request('time_range', '7days');
            $limit = min((int)request('limit', 20), 100);

            // Calculate date range
            $days = [
                '1day' => 1,
                '3days' => 3,
                '7days' => 7,
                '30days' => 30,
                '90days' => 90
            ];
            $dateFrom = now()->subDays($days[$timeRange] ?? 7);

            // Build query
            $query = AIAgentSession::where('child_id', $childId)
                ->where('last_interaction', '>=', $dateFrom)
                ->when($agentType, function($q) use ($agentType) {
                    $q->where('agent_type', $agentType);
                });

            $sessions = $query->orderBy('last_interaction', 'desc')
                ->limit($limit)
                ->get();

            $conversations = $sessions->map(function($session) {
                $metadata = $session->session_metadata ?? [];
                $messages = $metadata['messages'] ?? [];
                
                $lastMessage = '';
                $messageCount = count($messages);
                
                if ($messageCount > 0) {
                    $lastMsg = end($messages);
                    $lastMessage = $lastMsg['role'] === 'user' 
                        ? $lastMsg['content'] 
                        : ($messages[count($messages)-2]['content'] ?? '');
                }

                return [
                    'id' => $session->id,
                    'agent_type' => $session->agent_type,
                    'last_interaction' => $session->last_interaction,
                    'last_message' => $lastMessage,
                    'message_count' => $messageCount,
                    'topic' => $metadata['topic'] ?? null,
                    'context_items' => count($metadata['context'] ?? [])
                ];
            });

            return response()->json([
                'success' => true,
                'conversations' => $conversations,
                'total' => $conversations->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Get conversations error', [
                'child_id' => $childId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve conversation history'
            ], 500);
        }
    }

    /**
     * Get session information and statistics
     * Route: GET /ai/sessions/{child_id}
     * Frontend: General session management
     */
    public function getSessions(int $childId): JsonResponse
    {
        try {
            $child = Child::findOrFail($childId);
            
            if (!$this->canAccessChild($child)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Access denied'
                ], 403);
            }

            $sessions = AIAgentSession::where('child_id', $childId)
                ->where('is_active', true)
                ->orderBy('last_interaction', 'desc')
                ->get();

            $sessionData = [];
            foreach ($sessions as $session) {
                $sessionData[] = [
                    'id' => $session->id,
                    'agent_type' => $session->agent_type,
                    'is_active' => $session->is_active,
                    'last_interaction' => $session->last_interaction,
                    'created_at' => $session->created_at,
                    'session_metadata' => $session->session_metadata
                ];
            }

            return response()->json([
                'success' => true,
                'sessions' => $sessionData,
                'total_active_sessions' => $sessions->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Get sessions error', [
                'child_id' => $childId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve session information'
            ], 500);
        }
    }

    /**
     * Get available agent types and their capabilities
     * Route: GET /ai/agents/capabilities
     * Frontend: UI configuration and feature detection
     */
    public function getCapabilities(): JsonResponse
    {
        try {
            $capabilities = [
                'tutor' => [
                    'name' => 'General Tutor',
                    'description' => 'Homework help and concept explanation',
                    'tools' => (new TutorAgent())->getAvailableTools(),
                    'endpoint' => '/ai/tutor/chat',
                    'integration' => 'ChatWidget.jsx'
                ],
                'grading_review' => [
                    'name' => 'Grading Reviewer',
                    'description' => 'Explains mistakes and grading decisions',
                    'tools' => (new GradingReviewAgent())->getAvailableTools(),
                    'endpoint' => '/ai/grading/review',
                    'integration' => 'Submissions/Show.jsx'
                ],
                'progress_analysis' => [
                    'name' => 'Progress Analyst',
                    'description' => 'Learning insights and recommendations',
                    'tools' => (new ProgressAnalysisAgent())->getAvailableTools(),
                    'endpoint' => '/ai/progress/analyze',
                    'integration' => 'MySubmissions.jsx, Reports'
                ],
                'hint_generator' => [
                    'name' => 'Hint Generator',
                    'description' => 'Contextual hints for current problems',
                    'tools' => (new HintGeneratorAgent())->getAvailableTools(),
                    'endpoint' => '/ai/hints/generate',
                    'integration' => 'HintLoop.jsx'
                ]
            ];

            return response()->json([
                'success' => true,
                'agents' => $capabilities,
                'version' => '2.0',
                'phase' => 'Phase 2 Complete'
            ]);

        } catch (\Exception $e) {
            Log::error('Get capabilities error', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve agent capabilities'
            ], 500);
        }
    }

    /**
     * Enhanced session persistence helper methods
     */
    private function getOrCreateTutorSession(Child $child, $sessionId = null): AIAgentSession
    {
        if ($sessionId) {
            $session = AIAgentSession::where('id', $sessionId)
                ->where('child_id', $child->id)
                ->where('agent_type', 'tutor')
                ->first();
            
            if ($session) {
                return $session;
            }
        }

        return AIAgentSession::create([
            'child_id' => $child->id,
            'agent_type' => 'tutor',
            'is_active' => true,
            'last_interaction' => now(),
            'session_metadata' => [
                'messages' => [],
                'created_via' => 'chat_widget'
            ]
        ]);
    }

    private function getOrCreateAgentSession(Child $child, string $agentType, $sessionId = null): AIAgentSession
    {
        if ($sessionId) {
            $session = AIAgentSession::where('id', $sessionId)
                ->where('child_id', $child->id)
                ->where('agent_type', $agentType)
                ->first();
            
            if ($session) {
                return $session;
            }
        }

        return AIAgentSession::create([
            'child_id' => $child->id,
            'agent_type' => $agentType,
            'is_active' => true,
            'last_interaction' => now(),
            'session_metadata' => [
                'messages' => [],
                'created_via' => 'ai_system'
            ]
        ]);
    }

    private function getChatHistory(AIAgentSession $session): array
    {
        $metadata = $session->session_metadata ?? [];
        return $metadata['messages'] ?? [];
    }

    private function storeChatMessage(AIAgentSession $session, string $role, string $content): void
    {
        $metadata = $session->session_metadata ?? [];
        $messages = $metadata['messages'] ?? [];
        
        $messages[] = [
            'role' => $role,
            'content' => $content,
            'timestamp' => now()->toISOString()
        ];
        
        $metadata['messages'] = $messages;
        $session->session_metadata = $metadata;
        $session->last_interaction = now();
        $session->save();
    }

    /**
     * Check if current user can access the child
     */
    private function canAccessChild(Child $child): bool
    {
        $user = Auth::user();
        
        if (!$user) {
            return false;
        }

        // Admin can access all children
        if ($user->role === 'admin') {
            return true;
        }

        // Parent can access their own children
        if ($user->role === 'parent') {
            return $child->user_id === $user->id;
        }

        // Teachers can access children in their organization (if applicable)
        if ($user->role === 'teacher' && isset($child->organization_id) && isset($user->current_organization_id)) {
            return $child->organization_id === $user->current_organization_id;
        }

        return false;
    }

    /**
     * Phase 3: Rate limiting check for AI agent requests
     */
    private function checkRateLimit(int $childId, string $agentType): ?JsonResponse
    {
        // Check rate limits using the performance cache
        if (!$this->cache->checkRateLimit($childId, $agentType)) {
            $rateLimitStatus = $this->cache->getRateLimitStatus($childId, $agentType);
            
            return response()->json([
                'success' => false,
                'error' => 'Rate limit exceeded',
                'rate_limit' => $rateLimitStatus,
                'response' => 'You\'ve asked a lot of questions recently! Please wait a moment before asking again.'
            ], 429);
        }

        return null; // No rate limit hit
    }

    /**
     * Phase 3: Check for cached response before processing
     */
    private function getCachedResponse(int $childId, string $agentType, string $message, array $context = []): ?array
    {
        $queryHash = $this->cache->generateQueryHash($message, $context);
        return $this->cache->getCachedAgentResponse($agentType, $childId, $queryHash);
    }

    /**
     * Phase 3: Cache successful response for future use
     */
    private function cacheResponse(int $childId, string $agentType, string $message, array $context, array $response): void
    {
        $queryHash = $this->cache->generateQueryHash($message, $context);
        $this->cache->cacheAgentResponse($agentType, $childId, $queryHash, $response);
    }

    /**
     * Get performance data for analytics
     * Route: GET /ai/performance/{child_id}
     */
    public function getPerformanceData(int $childId): JsonResponse
    {
        try {
            $child = Child::findOrFail($childId);
            
            if (!$this->canAccessChild($child)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Access denied'
                ], 403);
            }

            // Placeholder implementation - will be enhanced later
            return response()->json([
                'success' => true,
                'performance_data' => [
                    'overall_progress' => 75,
                    'recent_scores' => [85, 78, 92, 88, 76],
                    'subject_performance' => [
                        'Math' => 82,
                        'English' => 78,
                        'Science' => 85
                    ],
                    'trends' => [
                        'improving' => ['Math', 'Science'],
                        'stable' => ['English'],
                        'declining' => []
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve performance data'
            ], 500);
        }
    }

    /**
     * Get AI recommendations for child
     * Route: GET /ai/recommendations/{child_id}
     */
    public function getRecommendations(int $childId): JsonResponse
    {
        try {
            $child = Child::findOrFail($childId);
            
            if (!$this->canAccessChild($child)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Access denied'
                ], 403);
            }

            // Placeholder implementation
            return response()->json([
                'success' => true,
                'recommendations' => [
                    [
                        'id' => 1,
                        'type' => 'practice',
                        'title' => 'Focus on Math Problem Solving',
                        'description' => 'Based on recent assessments, additional practice with word problems would be beneficial.',
                        'priority' => 'high',
                        'actionable' => true
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve recommendations'
            ], 500);
        }
    }

    /**
     * Execute a recommendation
     * Route: POST /ai/recommendations/execute
     */
    public function executeRecommendation(Request $request): JsonResponse
    {
        // Placeholder implementation
        return response()->json([
            'success' => true,
            'message' => 'Recommendation executed successfully'
        ]);
    }

    /**
     * Dismiss a recommendation
     * Route: POST /ai/recommendations/{id}/dismiss
     */
    public function dismissRecommendation(int $id): JsonResponse
    {
        // Placeholder implementation
        return response()->json([
            'success' => true,
            'message' => 'Recommendation dismissed'
        ]);
    }

    /**
     * Get weakness analysis for child
     * Route: GET /ai/weakness-analysis/{child_id}
     */
    public function getWeaknessAnalysis(int $childId): JsonResponse
    {
        try {
            $child = Child::findOrFail($childId);
            
            if (!$this->canAccessChild($child)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Access denied'
                ], 403);
            }

            // Placeholder implementation
            return response()->json([
                'success' => true,
                'weaknesses' => [
                    [
                        'subject' => 'Math',
                        'topic' => 'Algebra',
                        'confidence' => 0.85,
                        'suggested_interventions' => ['Practice more word problems', 'Review basic concepts']
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to analyze weaknesses'
            ], 500);
        }
    }

    /**
     * Execute an intervention
     * Route: POST /ai/interventions/execute
     */
    public function executeIntervention(Request $request): JsonResponse
    {
        // Placeholder implementation
        return response()->json([
            'success' => true,
            'message' => 'Intervention executed successfully'
        ]);
    }

    /**
     * Generate contextual prompts
     * Route: POST /ai/contextual-prompts/generate
     */
    public function generateContextualPrompts(Request $request): JsonResponse
    {
        // Placeholder implementation
        return response()->json([
            'success' => true,
            'prompts' => [
                'Tell me about the key concepts in this lesson',
                'Explain this problem step by step',
                'What are some real-world applications of this topic?'
            ]
        ]);
    }

    /**
     * Get prompt library for child
     * Route: GET /ai/prompt-library/{child_id}
     */
    public function getPromptLibrary(int $childId): JsonResponse
    {
        // Placeholder implementation
        return response()->json([
            'success' => true,
            'library' => [
                'categories' => [
                    'explanation' => ['Explain this concept', 'Break down this problem'],
                    'practice' => ['Give me practice questions', 'Test my understanding'],
                    'application' => ['Show real-world examples', 'How is this used?']
                ]
            ]
        ]);
    }

    /**
     * Generate custom prompt
     * Route: POST /ai/prompts/custom-generate
     */
    public function generateCustomPrompt(Request $request): JsonResponse
    {
        // Placeholder implementation
        return response()->json([
            'success' => true,
            'prompt' => 'Generated custom prompt based on your criteria'
        ]);
    }

    /**
     * Execute a prompt
     * Route: POST /ai/prompts/execute
     */
    public function executePrompt(Request $request): JsonResponse
    {
        // Placeholder implementation
        return response()->json([
            'success' => true,
            'response' => 'This is a response to your prompt'
        ]);
    }

    /**
     * Get learning paths for child
     * Route: GET /ai/learning-paths/{child_id}
     */
    public function getLearningPaths(int $childId): JsonResponse
    {
        // Placeholder implementation
        return response()->json([
            'success' => true,
            'paths' => [
                [
                    'id' => 1,
                    'title' => 'Algebra Mastery Path',
                    'description' => 'Complete algebra fundamentals',
                    'progress' => 45,
                    'estimated_duration' => '2 weeks'
                ]
            ]
        ]);
    }

    /**
     * Get learning path progress
     * Route: GET /ai/learning-paths/{child_id}/progress
     */
    public function getPathProgress(int $childId): JsonResponse
    {
        // Placeholder implementation
        return response()->json([
            'success' => true,
            'progress' => [
                'completed_steps' => 5,
                'total_steps' => 12,
                'current_step' => 'Practice Problems Set 2'
            ]
        ]);
    }

    /**
     * Generate a learning path
     * Route: POST /ai/learning-paths/generate
     */
    public function generateLearningPath(Request $request): JsonResponse
    {
        // Placeholder implementation
        return response()->json([
            'success' => true,
            'path_id' => 123,
            'message' => 'Learning path generated successfully'
        ]);
    }

    /**
     * Start a learning path
     * Route: POST /ai/learning-paths/{path_id}/start
     */
    public function startLearningPath(int $pathId): JsonResponse
    {
        // Placeholder implementation
        return response()->json([
            'success' => true,
            'message' => 'Learning path started successfully'
        ]);
    }

    /**
     * Execute a learning path step
     * Route: POST /ai/learning-paths/{path_id}/steps/{step_id}/execute
     */
    public function executePathStep(int $pathId, int $stepId): JsonResponse
    {
        // Placeholder implementation
        return response()->json([
            'success' => true,
            'message' => 'Learning path step executed successfully'
        ]);
    }

    /**
     * Phase 5: Initiate review chat for grading disputes
     * Route: POST /ai/review/initiate
     * Frontend: ReviewChatDialog.jsx
     */
    public function initiateReviewChat(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'flag_id' => 'required|integer|exists:ai_grading_flags,id',
                'child_id' => 'required|integer|exists:children,id'
            ]);

            // Get the ReviewChatAgent
            $agent = app(\App\Services\AI\Agents\ReviewChatAgent::class);
            
            // Initiate flag discussion
            $result = $agent->initiateFlagDiscussion(
                $validated['flag_id'], 
                $validated['child_id']
            );

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Failed to initiate review chat'
                ], 500);
            }

            return response()->json([
                'success' => true,
                'response' => $result['response'],
                'agent_type' => 'review_chat',
                'flag_id' => $validated['flag_id'],
                'resolution_suggestion' => $result['resolution_suggestion'] ?? null,
                'metadata' => $result['metadata'] ?? []
            ]);

        } catch (\Exception $e) {
            Log::error('Review chat initiation error', [
                'error' => $e->getMessage(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to initiate review chat'
            ], 500);
        }
    }

    /**
     * Phase 5: Handle review chat conversation for grading disputes
     * Route: POST /ai/review/chat
     * Frontend: ReviewChatDialog.jsx
     */
    public function reviewChat(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'message' => 'required|string|max:2000',
                'child_id' => 'required|integer|exists:children,id',
                'flag_id' => 'sometimes|integer|exists:ai_grading_flags,id'
            ]);

            // Get the ReviewChatAgent
            $agent = app(\App\Services\AI\Agents\ReviewChatAgent::class);
            
            // Build context
            $context = [];
            if (isset($validated['flag_id'])) {
                $context['flag_id'] = $validated['flag_id'];
            }
            
            // Send chat message
            $result = $agent->chat(
                $validated['message'],
                $validated['child_id'],
                $context
            );

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Failed to process chat message'
                ], 500);
            }

            return response()->json([
                'success' => true,
                'response' => $result['response'],
                'agent_type' => 'review_chat',
                'flag_id' => $validated['flag_id'] ?? null,
                'resolution_suggestion' => $result['resolution_suggestion'] ?? null,
                'metadata' => $result['metadata'] ?? []
            ]);

        } catch (\Exception $e) {
            Log::error('Review chat error', [
                'error' => $e->getMessage(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to process chat message'
            ], 500);
        }
    }

    /**
     * Get wrong questions from a submission for Review Chat Agent
     * Route: GET /ai/review-chat/wrong-questions/{submission_id}
     */
    public function getWrongQuestions(int $submissionId): JsonResponse
    {
        try {
            $submission = AssessmentSubmission::with(['child', 'assessment', 'items.question'])
                ->findOrFail($submissionId);
            
            if (!$this->canAccessChild($submission->child)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Access denied'
                ], 403);
            }

            // Get questions that were answered incorrectly
            $wrongQuestions = $submission->items()
                ->with('question')
                ->where('is_correct', false)
                ->whereNotNull('question_id')
                ->get()
                ->map(function($item) {
                    return [
                        'question_id' => $item->question_id,
                        'title' => $item->question?->title ?? 'Question',
                        'student_answer' => $item->student_answer,
                        'correct_answer' => $item->correct_answer,
                        'marks_obtained' => $item->marks_obtained,
                        'marks_total' => $item->marks_total,
                        'question_text' => $item->question?->question_data['question_text'] ?? '',
                        'question_type' => $item->question?->question_type ?? 'unknown'
                    ];
                });

            return response()->json([
                'success' => true,
                'submission' => [
                    'id' => $submission->id,
                    'assessment_title' => $submission->assessment?->title ?? 'Unknown',
                    'percentage' => $submission->total_marks ? round(($submission->marks_obtained / $submission->total_marks) * 100, 1) : 0,
                    'total_questions' => $submission->items()->count(),
                    'wrong_questions_count' => $wrongQuestions->count()
                ],
                'wrong_questions' => $wrongQuestions->values()->toArray()
            ]);

        } catch (\Exception $e) {
            Log::error('Get wrong questions error', [
                'submission_id' => $submissionId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve wrong questions'
            ], 500);
        }
    }

    /**
     * Get all questions from a submission for Grading Review Agent
     * Route: GET /ai/grading-review/submission-questions/{submission_id}
     */
    public function getSubmissionQuestions(int $submissionId): JsonResponse
    {
        try {
            $submission = AssessmentSubmission::with(['child', 'assessment', 'items.question'])
                ->findOrFail($submissionId);
            
            if (!$this->canAccessChild($submission->child)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Access denied'
                ], 403);
            }

            // Get all questions from this submission
            $questions = $submission->items()
                ->with('question')
                ->whereNotNull('question_id')
                ->get()
                ->map(function($item) {
                    return [
                        'question_id' => $item->question_id,
                        'submission_item_id' => $item->id,
                        'title' => $item->question?->title ?? 'Question',
                        'student_answer' => $item->student_answer,
                        'correct_answer' => $item->correct_answer,
                        'marks_obtained' => $item->marks_obtained,
                        'marks_total' => $item->marks_total,
                        'is_correct' => $item->is_correct,
                        'grader_notes' => $item->grader_notes,
                        'is_ai_graded' => $item->graded_by_ai ?? false,
                        'question_type' => $item->question?->question_type ?? 'unknown',
                        'difficulty_level' => $item->question?->difficulty_level ?? 1
                    ];
                });

            return response()->json([
                'success' => true,
                'submission' => [
                    'id' => $submission->id,
                    'assessment_title' => $submission->assessment?->title ?? 'Unknown',
                    'percentage' => $submission->total_marks ? round(($submission->marks_obtained / $submission->total_marks) * 100, 1) : 0,
                    'total_questions' => $questions->count(),
                    'finished_at' => $submission->finished_at?->toISOString()
                ],
                'questions' => $questions->values()->toArray()
            ]);

        } catch (\Exception $e) {
            Log::error('Get submission questions error', [
                'submission_id' => $submissionId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve submission questions'
            ], 500);
        }
    }

    /**
     * Get detailed grading information for a specific question in a submission
     * Route: GET /ai/grading-review/question-details/{submission_id}/{question_id}
     */
    public function getQuestionGradingDetails(int $submissionId, int $questionId): JsonResponse
    {
        try {
            $submission = AssessmentSubmission::with('child')->findOrFail($submissionId);
            
            if (!$this->canAccessChild($submission->child)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Access denied'
                ], 403);
            }

            // Get the specific submission item
            $item = $submission->items()
                ->with('question')
                ->where('question_id', $questionId)
                ->first();

            if (!$item) {
                return response()->json([
                    'success' => false,
                    'error' => 'Question not found in this submission'
                ], 404);
            }

            // Get AI grading flags if any
            $flags = \App\Models\AIGradingFlag::where('submission_item_id', $item->id)->get();

            return response()->json([
                'success' => true,
                'question_details' => [
                    'question_id' => $item->question_id,
                    'submission_item_id' => $item->id,
                    'title' => $item->question?->title ?? 'Question',
                    'question_text' => $item->question?->question_data['question_text'] ?? '',
                    'question_type' => $item->question?->question_type ?? 'unknown',
                    'student_answer' => $item->student_answer,
                    'correct_answer' => $item->correct_answer,
                    'marks_obtained' => $item->marks_obtained,
                    'marks_total' => $item->marks_total,
                    'is_correct' => $item->is_correct,
                    'grader_notes' => $item->grader_notes,
                    'is_ai_graded' => $item->graded_by_ai ?? false,
                    'grading_details' => $item->grading_details ?? [],
                    'ai_explanation' => $item->ai_explanation ?? null,
                    'flags' => $flags->map(function($flag) {
                        return [
                            'id' => $flag->id,
                            'flag_type' => $flag->flag_type,
                            'description' => $flag->description,
                            'status' => $flag->status,
                            'created_at' => $flag->created_at->toISOString()
                        ];
                    })
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Get question grading details error', [
                'submission_id' => $submissionId,
                'question_id' => $questionId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve question grading details'
            ], 500);
        }
    }
}
