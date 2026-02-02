<?php

namespace App\Services\AI;

use App\Models\Child;
use App\Services\AI\Agents\AbstractAgent;
use App\Services\AI\Agents\TutorAgent;
use App\Services\AI\Agents\GradingReviewAgent;
use App\Services\AI\Agents\ProgressAnalysisAgent;
use App\Services\AI\Agents\HintGeneratorAgent;
use Illuminate\Support\Facades\Log;

class AgentOrchestrator
{
    protected array $agents = [];

    public function __construct()
    {
        // Register available agents
        // These will be created in Phase 2
        $this->agents = [
            'tutor' => TutorAgent::class,
            'grading_review' => GradingReviewAgent::class,
            'progress' => ProgressAnalysisAgent::class,
            'hint' => HintGeneratorAgent::class,
        ];
    }

    /**
     * Route request to appropriate agent
     */
    public function routeToAgent(string $agentType, Child $child, array $context = []): array
    {
        Log::info("Routing to AI Agent", [
            'agent_type' => $agentType,
            'child_id' => $child->id,
            'context_keys' => array_keys($context)
        ]);

        // Validate agent type
        if (!isset($this->agents[$agentType])) {
            Log::warning("Invalid agent type requested", [
                'requested_type' => $agentType,
                'available_types' => array_keys($this->agents)
            ]);

            // Default to tutor agent
            $agentType = 'tutor';
        }

        try {
            // Create agent instance
            $agentClass = $this->agents[$agentType];
            
            // For Phase 1, we'll create a basic implementation
            // The actual agent classes will be implemented in Phase 2
            if (!class_exists($agentClass)) {
                Log::info("Agent class not yet implemented, using basic response", [
                    'agent_type' => $agentType,
                    'agent_class' => $agentClass
                ]);
                
                return $this->getBasicResponse($agentType, $child, $context);
            }

            $agent = new $agentClass();
            
            if (!$agent instanceof AbstractAgent) {
                throw new \InvalidArgumentException("Agent must extend AbstractAgent");
            }

            // Process request through agent
            $response = $agent->process($child, $context);

            Log::info("Agent processing completed", [
                'agent_type' => $agentType,
                'child_id' => $child->id,
                'response_length' => strlen(json_encode($response))
            ]);

            return $response;

        } catch (\Exception $e) {
            Log::error("Agent orchestration failed", [
                'agent_type' => $agentType,
                'child_id' => $child->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'AI service temporarily unavailable',
                'agent_type' => $agentType,
                'retry_recommended' => true
            ];
        }
    }

    /**
     * Get available agent types
     */
    public function getAvailableAgents(): array
    {
        return array_keys($this->agents);
    }

    /**
     * Check if agent type is available
     */
    public function isAgentAvailable(string $agentType): bool
    {
        return isset($this->agents[$agentType]);
    }

    /**
     * Get agent capabilities and description
     */
    public function getAgentInfo(string $agentType): array
    {
        $agentInfo = [
            'tutor' => [
                'name' => 'General Tutor',
                'description' => 'Provides general tutoring help with homework, topics, and learning guidance',
                'capabilities' => [
                    'homework_help',
                    'topic_explanation',
                    'learning_guidance',
                    'study_tips'
                ],
                'icon' => '🎓',
                'color' => 'blue'
            ],
            'grading_review' => [
                'name' => 'Review Helper',
                'description' => 'Focuses on reviewing incorrect answers and helping understand mistakes',
                'capabilities' => [
                    'mistake_analysis',
                    'incorrect_question_review',
                    'pattern_identification',
                    'targeted_practice'
                ],
                'icon' => '📝',
                'color' => 'orange'
            ],
            'progress' => [
                'name' => 'Progress Coach',
                'description' => 'Analyzes learning progress and provides insights and recommendations',
                'capabilities' => [
                    'progress_analysis',
                    'learning_insights',
                    'performance_trends',
                    'improvement_recommendations'
                ],
                'icon' => '📊',
                'color' => 'green'
            ],
            'hint' => [
                'name' => 'Hint Generator',
                'description' => 'Provides contextual hints and guided learning for specific problems',
                'capabilities' => [
                    'contextual_hints',
                    'guided_learning',
                    'step_by_step_help',
                    'concept_clarification'
                ],
                'icon' => '💡',
                'color' => 'purple'
            ]
        ];

        return $agentInfo[$agentType] ?? [
            'name' => 'Unknown Agent',
            'description' => 'Agent type not recognized',
            'capabilities' => [],
            'icon' => '❓',
            'color' => 'gray'
        ];
    }

    /**
     * Basic response for Phase 1 (before actual agents are implemented)
     */
    protected function getBasicResponse(string $agentType, Child $child, array $context = []): array
    {
        $agentInfo = $this->getAgentInfo($agentType);
        
        $responses = [
            'tutor' => "Hi {$child->child_name}! I'm your AI tutor. I'm here to help you with your studies. What would you like to work on today?",
            'grading_review' => "Hello {$child->child_name}! I can help you understand any questions you got wrong. Let's review them together so you can learn from the experience.",
            'progress' => "Hi {$child->child_name}! I'm here to help you track your learning progress. Based on your recent work, I can provide insights about your strengths and areas for improvement.",
            'hint' => "Hello {$child->child_name}! I'm your hint helper. When you're stuck on a problem, I can provide gentle hints to guide you toward the answer without giving it away completely."
        ];

        return [
            'success' => true,
            'agent_type' => $agentType,
            'agent_info' => $agentInfo,
            'response' => $responses[$agentType] ?? $responses['tutor'],
            'child_name' => $child->child_name,
            'context_provided' => !empty($context),
            'implementation_status' => 'basic_phase1',
            'available_capabilities' => $agentInfo['capabilities'] ?? []
        ];
    }

    /**
     * Route based on intent detection
     */
    public function routeByIntent(string $userMessage, Child $child, array $context = []): array
    {
        $agentType = $this->detectIntent($userMessage, $context);
        return $this->routeToAgent($agentType, $child, $context);
    }

    /**
     * Simple intent detection (will be enhanced in Phase 2)
     */
    protected function detectIntent(string $userMessage, array $context = []): string
    {
        $message = strtolower($userMessage);

        // Check for specific context hints first
        if (!empty($context['incorrect_questions'])) {
            return 'grading_review';
        }

        if (!empty($context['need_hint'])) {
            return 'hint';
        }

        // Keyword-based intent detection
        if (preg_match('/\b(progress|performance|how am i doing|improvement)\b/', $message)) {
            return 'progress';
        }

        if (preg_match('/\b(hint|stuck|help me understand|clue)\b/', $message)) {
            return 'hint';
        }

        if (preg_match('/\b(wrong|mistake|incorrect|review|why did i get)\b/', $message)) {
            return 'grading_review';
        }

        // Default to tutor agent
        return 'tutor';
    }
}
