<?php

namespace App\Services\AI\Agents;

use App\Models\AIGradingFlag;
use App\Models\AssessmentSubmissionItem;
use App\Models\Child;
use App\Models\User;
use App\Services\AI\Memory\MemoryManager;
use Illuminate\Support\Facades\Log;

/**
 * Phase 5: Review Chat Agent - Specialized for Grading Disputes
 * 
 * This agent handles interactive conversations about AI grading decisions,
 * providing explanations, clarifications, and helping resolve disputes
 * through structured dialogue.
 */
class ReviewChatAgent extends AbstractAgent
{
    protected string $agentType = 'review_chat';
    protected string $displayName = 'Review Chat Assistant';
    protected array $capabilities = [
        'dispute_analysis',
        'grading_explanation', 
        'conversation_flow',
        'resolution_guidance',
        'context_awareness'
    ];

    /**
     * Main processing method required by AbstractAgent
     */
    public function process(Child $child, array $context = []): array
    {
        $message = $context['message'] ?? 'I need help with a grading question.';
        return $this->chat($message, $child->id, $context);
    }

    /**
     * Get system prompt for this agent
     */
    protected function getSystemPrompt(): string
    {
        return "You are a specialized AI Review Chat Assistant helping resolve grading disputes and concerns. 

Your role is to:
1. Explain AI grading decisions clearly and fairly
2. Help students and parents understand assessment criteria
3. Guide productive conversations toward resolution
4. Identify valid concerns and suggest appropriate actions
5. Maintain neutrality while being helpful and educational

Key principles:
- Be empathetic and understanding
- Explain grading logic step-by-step
- Acknowledge when concerns might be valid
- Suggest constructive next steps
- Encourage learning from the discussion

Respond in a helpful, educational tone that promotes understanding and constructive dialogue.";
    }

    /**
     * Handle review chat conversation for grading disputes
     */
    public function chat(string $message, int $childId, ?array $context = []): array
    {
        try {
            // Find the child
            $child = Child::findOrFail($childId);
            
            // Extract flag context if provided
            $flagId = $context['flag_id'] ?? null;
            $flag = $flagId ? AIGradingFlag::with(['submissionItem.question', 'child', 'user'])->find($flagId) : null;
            
            // Build enhanced context for the prompt
            $enhancedContext = $this->buildEnhancedContext($flag, $context);
            
            // Generate AI response using parent method
            $response = $this->generateAIResponse($child, $message, $enhancedContext);
            
            // Analyze if this conversation might lead to resolution
            $resolutionSuggestion = $this->analyzeForResolution($response, $flag);
            
            return [
                'success' => true,
                'response' => $response,
                'agent_type' => $this->agentType,
                'capabilities_used' => ['dispute_analysis', 'grading_explanation'],
                'flag_id' => $flagId,
                'resolution_suggestion' => $resolutionSuggestion,
                'metadata' => [
                    'child_id' => $childId,
                    'dispute_context' => $flag ? 'flag_based' : 'general'
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('ReviewChatAgent error', [
                'message' => $e->getMessage(),
                'child_id' => $childId,
                'flag_id' => $flagId ?? null
            ]);
            
            return [
                'success' => false,
                'error' => 'Failed to process review chat request',
                'agent_type' => $this->agentType
            ];
        }
    }

    /**
     * Build enhanced context for grading disputes (matches AbstractAgent context format)
     */
    private function buildEnhancedContext(?AIGradingFlag $flag, array $context): array
    {
        $enhancedContext = [
            'conversation_type' => 'grading_dispute_review',
            'context_provided' => $context
        ];

        if ($flag) {
            $submissionItem = $flag->submissionItem;
            $question = $submissionItem->question ?? null;

            // Add dispute-specific context for the AI
            $enhancedContext['current_focus'] = [
                "Grading dispute discussion",
                "Flag reason: " . $flag->reason_label,
                "Student explanation: " . $flag->student_explanation,
                "Original grade: {$flag->original_grade}/{$submissionItem->points_possible}",
                "Status: " . $flag->status
            ];

            if ($question) {
                $enhancedContext['current_focus'][] = "Question type: " . $question->type;
                $enhancedContext['current_focus'][] = "Student answer: " . substr($submissionItem->answer, 0, 200) . "...";
                
                if ($submissionItem->ai_feedback) {
                    $enhancedContext['current_focus'][] = "Original AI feedback: " . $submissionItem->ai_feedback;
                }
            }

            if ($flag->status === 'resolved') {
                $enhancedContext['current_focus'][] = "RESOLVED: " . ($flag->grade_changed 
                    ? "Grade changed to {$flag->final_grade}" 
                    : "Original grade upheld");
            }
        } else {
            $enhancedContext['current_focus'] = [
                "General grading discussion",
                "No specific flag context provided"
            ];
        }

        return $enhancedContext;
    }

    /**
     * Build specialized prompt for review chat conversations
     */
    private function buildReviewChatPrompt(array $context, array $memory, string $message): string
    {
        $basePrompt = "You are a specialized AI Review Chat Assistant helping resolve grading disputes and concerns. 

Your role is to:
1. Explain AI grading decisions clearly and fairly
2. Help students and parents understand assessment criteria
3. Guide productive conversations toward resolution
4. Identify valid concerns and suggest appropriate actions
5. Maintain neutrality while being helpful and educational

Key principles:
- Be empathetic and understanding
- Explain grading logic step-by-step
- Acknowledge when concerns might be valid
- Suggest constructive next steps
- Encourage learning from the discussion

";

        // Add dispute context if available
        if (isset($context['flag_details'])) {
            $flag = $context['flag_details'];
            $submission = $context['submission_details'];
            $question = $context['question_details'] ?? [];

            $basePrompt .= "\n## Current Dispute Context:\n";
            $basePrompt .= "**Concern Type**: {$flag['reason_label']}\n";
            $basePrompt .= "**Student's Explanation**: {$flag['student_explanation']}\n";
            $basePrompt .= "**Original AI Grade**: {$flag['original_grade']}/{$submission['points_possible']}\n";
            
            if (isset($submission['ai_feedback'])) {
                $basePrompt .= "**Original AI Feedback**: {$submission['ai_feedback']}\n";
            }

            $basePrompt .= "\n## Question Context:\n";
            if (isset($question['question_text'])) {
                $basePrompt .= "**Question**: {$question['question_text']}\n";
            }
            if (isset($question['correct_answer'])) {
                $basePrompt .= "**Expected Answer**: {$question['correct_answer']}\n";
            }

            $basePrompt .= "\n## Student's Answer:\n";
            $basePrompt .= "\"{$submission['student_answer']}\"\n";

            if ($flag['status'] === 'resolved') {
                $basePrompt .= "\n## Resolution Status:\n";
                $basePrompt .= "This dispute has been resolved. ";
                if ($flag['grade_changed']) {
                    $basePrompt .= "The grade was changed to {$flag['final_grade']}/{$submission['points_possible']}.";
                } else {
                    $basePrompt .= "The original grade was upheld.";
                }
            }
        }

        // Add conversation history
        if (!empty($memory)) {
            $basePrompt .= "\n## Conversation History:\n";
            foreach (array_slice($memory, -5) as $entry) { // Last 5 exchanges
                $basePrompt .= "**Student/Parent**: {$entry['user_message']}\n";
                $basePrompt .= "**You**: {$entry['ai_response']}\n\n";
            }
        }

        $basePrompt .= "\n## Current Message:\n";
        $basePrompt .= "**Student/Parent**: {$message}\n\n";

        $basePrompt .= "Please respond helpfully and constructively to address their concern. If this is a valid dispute that requires human review, suggest they submit it for admin review. If the grading appears correct, explain why clearly and suggest ways to improve future performance.";

        return $basePrompt;
    }

    /**
     * Create a summary of flag context for memory storage
     */
    private function summarizeFlagContext(AIGradingFlag $flag): array
    {
        return [
            'flag_id' => $flag->id,
            'reason' => $flag->flag_reason,
            'status' => $flag->status,
            'original_grade' => $flag->original_grade,
            'question_type' => $flag->submissionItem->question->type ?? 'unknown'
        ];
    }

    /**
     * Determine the current stage of the dispute conversation
     */
    private function determineDisputeStage(string $response): string
    {
        // Simple keyword-based analysis
        $lowerResponse = strtolower($response);
        
        if (strpos($lowerResponse, 'submit') !== false && strpos($lowerResponse, 'review') !== false) {
            return 'recommend_admin_review';
        } elseif (strpos($lowerResponse, 'understand') !== false && strpos($lowerResponse, 'grading') !== false) {
            return 'explaining_criteria';
        } elseif (strpos($lowerResponse, 'agree') !== false || strpos($lowerResponse, 'valid concern') !== false) {
            return 'acknowledging_concern';
        } elseif (strpos($lowerResponse, 'correct') !== false && strpos($lowerResponse, 'grade') !== false) {
            return 'upholding_grade';
        } else {
            return 'general_discussion';
        }
    }

    /**
     * Analyze conversation for potential resolution paths
     */
    private function analyzeForResolution(string $response, ?AIGradingFlag $flag): ?array
    {
        if (!$flag) {
            return null;
        }

        $lowerResponse = strtolower($response);
        $suggestion = null;

        // Check for keywords that suggest different resolution paths
        if (strpos($lowerResponse, 'submit for review') !== false || 
            strpos($lowerResponse, 'admin review') !== false) {
            $suggestion = [
                'type' => 'admin_review_recommended',
                'confidence' => 0.8,
                'action' => 'The AI assistant is recommending human review',
                'next_steps' => ['Contact administration', 'Provide additional evidence']
            ];
        } elseif (strpos($lowerResponse, 'grade appears correct') !== false || 
                  strpos($lowerResponse, 'grading is accurate') !== false) {
            $suggestion = [
                'type' => 'grade_upheld',
                'confidence' => 0.7,
                'action' => 'The AI assistant is explaining why the grade is correct',
                'next_steps' => ['Review feedback', 'Focus on improvement', 'Accept current grade']
            ];
        } elseif (strpos($lowerResponse, 'partial credit') !== false) {
            $suggestion = [
                'type' => 'partial_credit_discussion',
                'confidence' => 0.6,
                'action' => 'Discussing partial credit possibilities',
                'next_steps' => ['Clarify answer components', 'Consider regrade request']
            ];
        }

        return $suggestion;
    }

    /**
     * Get agent configuration for frontend
     */
    public function getConfiguration(): array
    {
        return [
            'agent_type' => $this->agentType,
            'display_name' => $this->displayName,
            'description' => 'Specialized assistant for discussing and resolving grading disputes through interactive conversation',
            'icon' => 'MessageCircleQuestion',
            'color' => 'orange',
            'capabilities' => $this->capabilities,
            'use_cases' => [
                'Explain AI grading decisions',
                'Discuss grading concerns',
                'Guide dispute resolution',
                'Provide assessment feedback clarification',
                'Suggest improvement strategies'
            ],
            'sample_prompts' => [
                "Can you explain why my answer received this grade?",
                "I think the AI grading missed an important part of my response",
                "This grade seems unfair compared to the rubric",
                "What can I do to improve my score on similar questions?"
            ]
        ];
    }

    /**
     * Handle flag-specific chat initiation
     */
    public function initiateFlagDiscussion(int $flagId, int $childId): array
    {
        try {
            $flag = AIGradingFlag::with(['submissionItem.question', 'child', 'user'])->findOrFail($flagId);
            
            // Create initial context message
            $initialMessage = "I'd like to discuss the grading concern I flagged: " . $flag->reason_label;
            if ($flag->student_explanation) {
                $initialMessage .= ". " . $flag->student_explanation;
            }
            
            // Start the conversation
            return $this->chat($initialMessage, $childId, ['flag_id' => $flagId, 'initiate' => true]);
            
        } catch (\Exception $e) {
            Log::error('ReviewChatAgent flag initiation error', [
                'flag_id' => $flagId,
                'child_id' => $childId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => 'Failed to initiate flag discussion',
                'agent_type' => $this->agentType
            ];
        }
    }
}
