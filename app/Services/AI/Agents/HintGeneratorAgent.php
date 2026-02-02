<?php

namespace App\Services\AI\Agents;

use App\Models\Child;
use App\Models\Question;
use App\Models\Assessment;
use App\Models\AssessmentSubmissionItem;
use Illuminate\Support\Facades\Log;

/**
 * HintGeneratorAgent - Contextual hints for current problems
 * Integrates with existing HintLoop.jsx in parent portal
 */
class HintGeneratorAgent extends AbstractAgent
{
    protected string $agentType = 'hint';
    
    // Increase token limit for hint generation with conversation history
    protected int $maxTokens = 2000;
    
    protected array $tools = [
        'contextual_hints',
        'progressive_disclosure', 
        'concept_breakdown',
        'example_generation',
        'step_guidance',
        'misconception_prevention'
    ];

    /**
     * Main processing method - required by AbstractAgent
     */
    public function process(Child $child, array $context = []): array
    {
        try {
            $questionId = $context['question_id'] ?? null;
            $currentAnswer = $context['current_answer'] ?? '';
            $hintLevel = $context['hint_level'] ?? 1;
            $previousHints = $context['previous_hints'] ?? [];
            $message = $context['message'] ?? '';
            
            if (!$questionId) {
                return $this->createErrorResponse('No question specified for hints');
            }
            Log::info('HintGeneratorAgent processing', [
                'child_id' => $child->id,
                'question_id' => $questionId,
                'hint_level' => $hintLevel,
                'has_current_answer' => !empty($currentAnswer),
                'previous_hints_count' => count($previousHints),
            ]);
            // Get question details
            $question = $this->getQuestion($questionId);
            
            if (!$question) {
                return $this->createErrorResponse('Question not found');
            }

            // Get child's previous attempts on similar questions
            $similarAttempts = $this->getSimilarAttempts($child, $question);

            // Build hint context
            $hintContext = $this->buildHintContext($child, $question, $currentAnswer, $hintLevel, $previousHints, $similarAttempts);
            
            // Generate contextual hint using the base class method
            $hintMessage = $this->constructHintMessage($question, $currentAnswer, $hintLevel, $previousHints, $message);
            $aiResponse = $this->generateAIResponse($child, $hintMessage, $hintContext);
            
            // Parse and structure the hint response
            $structuredHint = $this->structureHintResponse($aiResponse, $hintLevel, $question);
            
            // Log the hint activity
            $this->logActivity($child, 'hint_generation', [
                'question_id' => $questionId,
                'question_type' => $question->question_type ?? 'unknown',
                'hint_level' => $hintLevel,
                'has_current_answer' => !empty($currentAnswer),
                'previous_hints_count' => count($previousHints),
            ]);

            return [
                'success' => true,
                'response' => $aiResponse,
                'agent_type' => $this->agentType,
                'child_id' => $child->id,
                'metadata' => [
                    'question_id' => $questionId,
                    'hint_level' => $hintLevel,
                    'structured_hint' => $structuredHint,
                    'confidence' => 0.92,
                    'next_hint_available' => $hintLevel < 3,
                    'hint_category' => $this->categorizeHint($question, $hintLevel),
                ]
            ];

        } catch (\Exception $e) {
            Log::error('HintGeneratorAgent error', [
                'child_id' => $child->id,
                'question_id' => $context['question_id'] ?? null,
                'hint_level' => $context['hint_level'] ?? 1,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->createErrorResponse('Something went wrong while generating your hint.');
        }
    }

    /**
     * Generate system prompt for the hint generator agent - required by AbstractAgent
     */
    protected function getSystemPrompt(): string
    {
        return "You are an expert educational hint generator specializing in providing progressive, contextual hints for primary school students. Your role is to:

HINT PHILOSOPHY:
- Guide students to discover answers themselves rather than giving direct solutions
- Provide just enough help to move forward without removing the learning challenge
- Build confidence through incremental support and encouragement
- Adapt hints based on the student's current understanding and mistakes

HINT PROGRESSION LEVELS:
Level 1 (Gentle Nudge): Broad direction without revealing specific methods
- 'Think about what this question is really asking...'
- Point to relevant concepts or tools needed
- Encourage re-reading or careful observation

Level 2 (Method Guidance): More specific direction about approach
- Suggest specific strategies or steps to consider
- Provide conceptual framework or formula hints
- Offer relevant examples or analogies

Level 3 (Step-by-Step): Detailed guidance while preserving discovery
- Break down the problem into smaller parts
- Guide through specific steps with reasoning
- Provide worked examples with similar problems

HINT GUIDELINES:
1. Always be encouraging and supportive
2. Use age-appropriate language for primary school students
3. Reference the student's current answer if they've attempted one
4. Build on previous hints rather than repeating them
5. Celebrate partial understanding and progress
6. Ask guiding questions that promote thinking

QUESTION TYPE ADAPTATIONS:
- MCQ: Help eliminate wrong options and guide toward correct reasoning
- Short Answer: Break down concepts and guide thinking process
- Math Problems: Suggest relevant operations, formulas, or problem-solving strategies
- Comprehension: Guide attention to relevant text sections and key information
- Reasoning: Help identify patterns, relationships, or logical connections

RESPONSE STRUCTURE:
1. Acknowledge current progress (if any)
2. Provide the progressive hint appropriate to the level
3. Include encouraging motivation
4. End with a guiding question or next step suggestion

TONE:
- Encouraging and patient
- Curious and exploratory rather than directive
- Celebrates thinking process over just correct answers
- Maintains learning challenge while providing support";
    }

    /**
     * Get question details for hint generation
     */
    private function getQuestion($questionId): ?Question
    {
        try {
            return Question::find($questionId);
        } catch (\Exception $e) {
            Log::warning('Failed to get question for hints', [
                'question_id' => $questionId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get child's previous attempts on similar questions
     */
    private function getSimilarAttempts(Child $child, Question $question): array
    {
        try {
            // Need to join through assessment_submissions since assessment_submission_items doesn't have child_id
            $similarItems = AssessmentSubmissionItem::whereHas('submission', function ($query) use ($child) {
                    $query->where('child_id', $child->id);
                })
                ->where('bank_question_id', '!=', $question->id)
                ->whereHas('bankQuestion', function ($query) use ($question) {
                    $query->where('question_type', $question->question_type)
                          ->where('category', $question->category);
                })
                ->with(['bankQuestion', 'submission'])
                ->orderBy('created_at', 'desc')
                ->take(3)
                ->get();

            $attempts = [];
            foreach ($similarItems as $item) {
                $attempts[] = [
                    'question_type' => $item->bankQuestion->question_type ?? 'unknown',
                    'was_correct' => $item->is_correct ?? false,
                    'marks_awarded' => $item->marks_awarded ?? 0,
                    'difficulty' => $item->bankQuestion->difficulty_level ?? 'medium',
                ];
            }

            return $attempts;
        } catch (\Exception $e) {
            Log::warning('Failed to get similar attempts', [
                'child_id' => $child->id,
                'question_id' => $question->id,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Build comprehensive context for hint generation
     */
    private function buildHintContext(Child $child, Question $question, string $currentAnswer, int $hintLevel, array $previousHints, array $similarAttempts): array
    {
        // Handle both JSON string and already decoded array formats
        $questionData = is_array($question->question_data) 
            ? $question->question_data 
            : json_decode($question->question_data ?? '{}', true);
            
        $answerSchema = is_array($question->answer_schema) 
            ? $question->answer_schema 
            : json_decode($question->answer_schema ?? '{}', true);
        
        return [
            'recent_performance' => [
                'question_type: ' . ($question->question_type ?? 'unknown'),
                'question_difficulty: ' . ($question->difficulty_level ?? 'medium'),
                'question_category: ' . ($question->category ?? 'general'),
                'has_attempted_answer: ' . (!empty($currentAnswer) ? 'Yes' : 'No'),
                'current_attempt: ' . (!empty($currentAnswer) ? substr($currentAnswer, 0, 100) : 'None yet'),
                'similar_performance: ' . $this->summarizeSimilarAttempts($similarAttempts),
            ],
            'learning_patterns' => [
                'hint_level: ' . $hintLevel . ' of 3',
                'previous_hints_given: ' . count($previousHints),
                'question_marks: ' . ($question->marks ?? 1),
                'student_grade: ' . ($child->grade ?? 'Year 6'),
                'support_needed: Progressive hint level ' . $hintLevel,
            ],
            'current_focus' => [
                'hint_request: Generate level ' . $hintLevel . ' hint for this question',
                'learning_objective: Guide student to discover the solution independently',
                'avoid_giving: Direct answers or complete solutions',
                'encourage: Thinking process, reasoning, and gradual discovery',
            ]
        ];
    }

    /**
     * Construct hint-specific message for AI
     */
    private function constructHintMessage(Question $question, string $currentAnswer, int $hintLevel, array $previousHints, string $userMessage): string
    {
        // Handle both JSON string and already decoded array formats
        $questionData = is_array($question->question_data) 
            ? $question->question_data 
            : json_decode($question->question_data ?? '{}', true);
        $questionText = $questionData['question_text'] ?? $question->title ?? 'Question text not available';
        
        $message = "I need a level {$hintLevel} hint for this question:\n\n";
        $message .= "QUESTION: {$questionText}\n\n";
        
        if (!empty($currentAnswer)) {
            $message .= "MY CURRENT ANSWER: {$currentAnswer}\n\n";
        }
        
        if (!empty($previousHints)) {
            $message .= "PREVIOUS HINTS I'VE RECEIVED:\n";
            foreach ($previousHints as $i => $hint) {
                $message .= ($i + 1) . ". {$hint}\n";
            }
            $message .= "\n";
        }
        
        if (!empty($userMessage)) {
            $message .= "SPECIFIC REQUEST: {$userMessage}\n\n";
        }
        
        $message .= "Please give me a level {$hintLevel} hint that helps me think through this problem.";
        
        return $message;
    }

    /**
     * Summarize similar attempts for context
     */
    private function summarizeSimilarAttempts(array $attempts): string
    {
        if (empty($attempts)) {
            return 'No similar questions attempted recently';
        }
        
        $correct = count(array_filter($attempts, fn($a) => $a['was_correct']));
        $total = count($attempts);
        
        return "Similar questions: {$correct}/{$total} correct";
    }

    /**
     * Structure hint response for frontend use
     */
    private function structureHintResponse(string $aiResponse, int $hintLevel, Question $question): array
    {
        return [
            'hint_text' => $aiResponse,
            'hint_level' => $hintLevel,
            'hint_type' => $this->categorizeHint($question, $hintLevel),
            'encourages_thinking' => true,
            'reveals_answer' => false,
            'next_step_suggested' => true,
        ];
    }

    /**
     * Categorize the type of hint based on question and level
     */
    private function categorizeHint(Question $question, int $hintLevel): string
    {
        $questionType = $question->question_type ?? 'unknown';
        
        if ($hintLevel === 1) {
            return match($questionType) {
                'mcq', 'multiple_choice' => 'elimination_guidance',
                'short_answer', 'long_answer' => 'concept_direction',
                'matching' => 'pattern_recognition',
                'ordering' => 'sequence_logic',
                'cloze' => 'context_clues',
                default => 'general_guidance'
            };
        } elseif ($hintLevel === 2) {
            return match($questionType) {
                'mcq', 'multiple_choice' => 'method_suggestion',
                'short_answer', 'long_answer' => 'approach_guidance',
                'matching' => 'relationship_hints',
                'ordering' => 'logic_framework',
                'cloze' => 'vocabulary_support',
                default => 'strategy_guidance'
            };
        } else {
            return match($questionType) {
                'mcq', 'multiple_choice' => 'step_by_step_reasoning',
                'short_answer', 'long_answer' => 'structured_thinking',
                'matching' => 'detailed_analysis',
                'ordering' => 'systematic_approach',
                'cloze' => 'comprehensive_support',
                default => 'detailed_guidance'
            };
        }
    }

    /**
     * Create error response
     */
    private function createErrorResponse(string $message): array
    {
        return [
            'success' => false,
            'error' => $message,
            'response' => "I'm having trouble generating a hint right now. Try reading the question carefully again, or ask your teacher for help!",
            'agent_type' => $this->agentType
        ];
    }
}
