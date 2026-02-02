<?php

namespace App\Services\AI\Agents;

use App\Models\Child;
use App\Models\AssessmentSubmission;
use App\Models\AssessmentSubmissionItem;
use App\Models\Question;
use Illuminate\Support\Facades\Log;

/**
 * GradingReviewAgent - Explains incorrect answers and mistakes
 * Integrates with existing Submissions/Show.jsx in parent portal
 */
class GradingReviewAgent extends AbstractAgent
{
    protected string $agentType = 'grading_review';
    // Increased from 400 to 4000 - GPT-5 returns empty content when hitting token limit,
    // and detailed question context with options requires more space for a complete response
    protected int $maxTokens = 4000;
    protected float $temperature = 0.4;
    
    protected array $tools = [
        'mistake_analysis',
        'correct_solution_explanation', 
        'learning_gap_identification',
        'improvement_suggestions',
        'conceptual_clarification'
    ];

    /**
     * Keep short history to avoid bloated prompts for grading review.
     */
    protected function getConversationHistoryLimit(): int
    {
        return 2;
    }

    /**
     * Main processing method - required by AbstractAgent
     */
    public function process(Child $child, array $context = []): array
    {
        try {
            $submissionId = $context['submission_id'] ?? null;
            $questionId = $context['question_id'] ?? null;
            $message = $context['message'] ?? '';
            $wrongAnswers = $context['wrong_answers'] ?? [];

            // Handle new multi-question format
            if (!empty($wrongAnswers)) {
                // Build context for multiple wrong answers
                $reviewContext = $this->buildMultiQuestionReviewContext($child, $wrongAnswers, $context, $message);
                
                // IMPORTANT: Do NOT add _lightweight_context when we have wrong_answers
                // The full question context is needed for the AI to explain the mistakes.
                // The AbstractAgent will use lightweight context for follow-ups if it exists,
                // but we want it to use the FULL context with question details every time
                // wrong_answers are provided (which indicates an initial explanation request).
                
                // Generate AI explanation using the base class method
                $aiResponse = $this->generateAIResponse($child, $message, $reviewContext);
                
                return [
                    'success' => true,
                    'response' => $aiResponse,
                    'agent_type' => $this->agentType,
                    'child_id' => $child->id,
                    'metadata' => [
                        'submission_id' => $submissionId,
                        'wrong_answers_count' => count($wrongAnswers),
                        'review_type' => 'multi_question_review',
                        'confidence' => 0.85,
                    ]
                ];
            } else {
                // Fallback to single question format
                if (!$submissionId && !$questionId) {
                    return $this->createErrorResponse('No submission or question specified for review');
                }

                $submissionItem = $this->getSubmissionItem($submissionId, $questionId);
                
                if (!$submissionItem) {
                    return $this->createErrorResponse('Submission item not found');
                }

                $reviewContext = $this->buildReviewContext($child, $submissionItem, $message);
                
                // Add lightweight context for follow-ups
                $reviewContext['_lightweight_context'] = [
                    'current_focus' => [
                        'Question being discussed',
                        'Continue the conversation naturally based on previous messages'
                    ]
                ];
                
                // Generate AI explanation using the base class method
                $aiResponse = $this->generateAIResponse($child, $message, $reviewContext);

                return [
                    'success' => true,
                    'response' => $aiResponse,
                    'agent_type' => $this->agentType,
                    'child_id' => $child->id,
                    'metadata' => [
                        'submission_id' => $submissionId,
                        'question_id' => $questionId,
                        'review_type' => $this->determineReviewType($submissionItem),
                        'confidence' => 0.85,
                        'mistake_category' => $this->categorizeMistake($submissionItem),
                    ]
                ];
            }

        } catch (\Exception $e) {
            Log::error('GradingReviewAgent error', [
                'child_id' => $child->id,
                'error' => $e->getMessage()
            ]);

            return $this->createErrorResponse('Something went wrong while analyzing your answer.');
        }
    }

    /**
     * Generate system prompt for the grading review agent - required by AbstractAgent
     */
    protected function getSystemPrompt(): string
    {
        return "You are an expert educational reviewer specializing in analyzing student mistakes and providing constructive feedback.

IMPORTANT: Adapt your response style based on conversation context:

FOR INITIAL EXPLANATION REQUESTS:
Use structured format with these guidelines:
1. Acknowledge the student's effort
2. Address EACH question specifically using the actual question text
3. For each question explain:
   (a) What the question asked
   (b) What the student answered
   (c) Why it was wrong/partially wrong
   (d) What the correct approach is
4. Provide tips to avoid similar mistakes
5. Encourage continued learning
6. Use supportive, age-appropriate language

FOR FOLLOW-UP CONVERSATIONS:
- Have a natural, friendly conversation
- Answer specific questions directly
- Provide clarifications when asked
- If the user is just acknowledging (\"ok\", \"thanks\", \"thankyou\"), respond briefly and warmly
- If asking for more detail on a specific question, focus on that question only
- Maintain context from previous messages in the conversation
- Don't repeat the full structured explanation unless explicitly asked

CONVERSATION DETECTION:
- \"ok\", \"thanks\", \"thankyou\", \"got it\" → Brief friendly response
- Specific question about a topic → Answer that topic naturally
- Request for clarification → Provide targeted clarification
- New explanation request → Use structured format

TONE:
- Always supportive and encouraging
- Educational and informative
- Patient and understanding
- Conversational for follow-ups
- Structured for initial explanations

CRITICAL: DO NOT repeat the full structured explanation format for every message. After the first explanation, engage in natural conversation!";
    }

    /**
     * Get submission item for analysis
     */
    private function getSubmissionItem($submissionId, $questionId): ?AssessmentSubmissionItem
    {
        try {
            $query = AssessmentSubmissionItem::query();
            
            if ($submissionId) {
                $query->where('submission_id', $submissionId);
            }
            
            if ($questionId) {
                $query->where('bank_question_id', $questionId);
            }
            
            return $query->with(['submission', 'bankQuestion'])->first();
            
        } catch (\Exception $e) {
            Log::warning('Failed to get submission item', [
                'submission_id' => $submissionId,
                'question_id' => $questionId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Build context for multiple wrong answers - ENHANCED with complete question data
     */
    private function buildMultiQuestionReviewContext(Child $child, array $wrongAnswers, array $context, string $message): array
    {
        $assessmentTitle = $context['assessment_title'] ?? 'Assessment';
        $submissionId = $context['submission_id'] ?? null;
        Log::info("wrong answers zaid-wrong",$wrongAnswers);
        // Format each question with COMPLETE details for AI understanding
        $questionsDetail = [];
        foreach ($wrongAnswers as $index => $answer) {
            $questionNum = $index + 1;
            $questionText = $answer['question_text'] ?? 'Question text not available';
            $studentAnswer = $answer['student_answer'] ?? 'No answer provided';
            $marksAwarded = $answer['marks_awarded'] ?? 0;
            $totalMarks = $answer['total_marks'] ?? 1;
            $questionType = $answer['question_type'] ?? 'unknown';
            $isCorrect = $answer['is_correct'] ?? false;
            $detailedFeedback = $answer['detailed_feedback'] ?? '';
            $questionData = $answer['question_data'] ?? null;
            
            // Build comprehensive question details
            $questionDetail = "━━━━━ Question $questionNum ━━━━━\n";
            $questionDetail .= "📝 QUESTION TEXT:\n$questionText\n\n";
            
            // Add detailed question data if available (options, sub-questions, etc.)
            if ($questionData && isset($questionData['question_data'])) {
                $qData = $questionData['question_data'];
                
                // Add question-type specific details
                switch ($questionType) {
                    case 'mcq':
                        if (isset($qData['options']) && is_array($qData['options'])) {
                            $questionDetail .= "📋 OPTIONS:\n";
                            foreach ($qData['options'] as $idx => $option) {
                                $letter = chr(65 + $idx);
                                $optIsCorrect = ($option['is_correct'] ?? '0') === '1';
                                $questionDetail .= "   $letter. " . ($option['text'] ?? $option) . ($optIsCorrect ? " ✓ (Correct)" : "") . "\n";
                            }
                            $questionDetail .= "\n";
                        }
                        break;
                        
                    case 'comprehension':
                        if (isset($qData['passage'])) {
                            $passage = is_array($qData['passage']) ? ($qData['passage']['content'] ?? json_encode($qData['passage'])) : $qData['passage'];
                            $questionDetail .= "📖 PASSAGE:\n$passage\n\n";
                        }
                        if (isset($qData['sub_questions']) && is_array($qData['sub_questions'])) {
                            $questionDetail .= "❓ SUB-QUESTIONS:\n";
                            foreach ($qData['sub_questions'] as $sqIdx => $subQ) {
                                $questionDetail .= "   Q" . ($sqIdx + 1) . ": " . ($subQ['question_text'] ?? '') . "\n";
                                if (isset($subQ['options']) && is_array($subQ['options'])) {
                                    foreach ($subQ['options'] as $optIdx => $opt) {
                                        $letter = chr(65 + $optIdx);
                                        $optIsCorrect = ($opt['is_correct'] ?? '0') === '1';
                                        $questionDetail .= "      $letter. " . ($opt['text'] ?? $opt) . ($optIsCorrect ? " ✓" : "") . "\n";
                                    }
                                }
                            }
                            $questionDetail .= "\n";
                        }
                        break;
                }
            }
            
            $questionDetail .= "👤 STUDENT'S ANSWER:\n$studentAnswer\n\n";
            $questionDetail .= "📊 GRADING:\n";
            $questionDetail .= "   • Score: $marksAwarded out of $totalMarks points\n";
            $questionDetail .= "   • Result: " . ($isCorrect ? "✓ Correct" : "✗ Incorrect") . "\n";
            $questionDetail .= "   • Question Type: $questionType\n";
            
            if (!empty($detailedFeedback)) {
                $questionDetail .= "\n💬 FEEDBACK PROVIDED:\n$detailedFeedback\n";
            }
            
            $questionsDetail[] = $questionDetail;
        }
        
        // Create comprehensive context with all question details
        return [
            'recent_performance' => [
                '📚 Assessment: ' . $assessmentTitle,
                '❌ Total Questions Incorrect: ' . count($wrongAnswers),
                '🆔 Submission ID: ' . $submissionId,
                '',
                '═══════════════════════════════════════════',
                '📋 COMPLETE QUESTION DETAILS BELOW',
                '═══════════════════════════════════════════',
                '',
                implode("\n", $questionsDetail),
            ],
            'learning_patterns' => [
                '🎓 Grade Level: ' . ($child->grade ?? 'Year 6'),
                '🔍 Common Mistake Patterns: ' . $this->identifyCommonMistakes($wrongAnswers),
                '📑 Question Types Affected: ' . $this->getQuestionTypes($wrongAnswers),
                '⚠️ Zero-Score Questions: ' . $this->countZeroScoreQuestions($wrongAnswers) . ' out of ' . count($wrongAnswers),
            ],
            'current_focus' => [
                '💭 Student Request: ' . (!empty($message) ? $message : 'Student wants explanation for multiple incorrect answers'),
                '🎯 Learning Objective: Help student understand each specific question, identify where they went wrong, and learn the correct approach',
                '🔑 Priority Areas: ' . $this->getPriorityAreas($wrongAnswers),
                '',
                '⚠️ CRITICAL INSTRUCTIONS:',
                '1. Address EACH question specifically using the actual question text provided above',
                '2. For EACH question, explain: (a) what the question asked, (b) what the student answered, (c) why it was wrong, (d) what the correct approach is',
                '3. DO NOT use generic placeholders like "(Insert question here)" - use the ACTUAL question text',
                '4. Number your responses to match the question numbers (Question 1, Question 2, etc.)',
                '5. Be specific and reference the actual content of each question and student answer',
            ]
        ];
    }
    
    /**
     * Count questions with zero score
     */
    private function countZeroScoreQuestions(array $wrongAnswers): int
    {
        return count(array_filter($wrongAnswers, function($answer) {
            return ($answer['marks_awarded'] ?? 0) == 0;
        }));
    }
    
    /**
     * Identify common mistake patterns
     */
    private function identifyCommonMistakes(array $wrongAnswers): string
    {
        $mistakes = [];
        foreach ($wrongAnswers as $answer) {
            $questionType = $answer['question_type'] ?? 'unknown';
            $marksAwarded = $answer['marks_awarded'] ?? 0;
            
            if ($marksAwarded == 0) {
                $mistakes[] = "complete_error_" . $questionType;
            } else {
                $mistakes[] = "partial_error_" . $questionType;
            }
        }
        
        $mistakeCounts = array_count_values($mistakes);
        arsort($mistakeCounts);
        
        return implode(', ', array_keys(array_slice($mistakeCounts, 0, 3)));
    }
    
    /**
     * Get question types from wrong answers
     */
    private function getQuestionTypes(array $wrongAnswers): string
    {
        $types = array_unique(array_map(function($answer) {
            return $answer['question_type'] ?? 'unknown';
        }, $wrongAnswers));
        
        return implode(', ', $types);
    }
    
    /**
     * Get priority areas for review
     */
    private function getPriorityAreas(array $wrongAnswers): string
    {
        $areas = [];
        
        foreach ($wrongAnswers as $answer) {
            if (($answer['marks_awarded'] ?? 0) == 0) {
                $areas[] = 'complete_understanding';
            } else {
                $areas[] = 'detailed_explanations';
            }
        }
        
        $areas = array_unique($areas);
        return implode(', ', $areas);
    }

    /**
     * Build comprehensive context for AI review
     */
    private function buildReviewContext(Child $child, AssessmentSubmissionItem $submissionItem, string $message): array
    {
        $questionData = $submissionItem->question_data ?? [];
        $studentAnswer = $submissionItem->answer ?? [];
        $correctAnswer = $questionData['answer_schema'] ?? [];
        
        return [
            'recent_performance' => [
                'question_type: ' . ($questionData['question_type'] ?? 'unknown'),
                'student_answer: ' . $this->formatAnswer($studentAnswer),
                'correct_answer: ' . $this->formatAnswer($correctAnswer),
                'marks_awarded: ' . ($submissionItem->marks_awarded ?? 0) . '/' . ($questionData['marks'] ?? 1),
                'was_correct: ' . ($submissionItem->is_correct ? 'Yes' : 'No'),
                'ai_graded: ' . ($this->wasAIGraded($submissionItem) ? 'Yes' : 'No'),
            ],
            'learning_patterns' => [
                'question_difficulty: ' . ($questionData['difficulty_level'] ?? 'medium'),
                'subject_area: ' . ($questionData['category'] ?? 'general'),
                'grade_level: ' . ($questionData['grade'] ?? $child->grade ?? 'Year 6'),
            ],
            'current_focus' => [
                'review_request: ' . (!empty($message) ? $message : 'Student wants explanation for this answer'),
                'mistake_type: ' . $this->categorizeMistake($submissionItem),
                'learning_objective: Help student understand the correct approach and learn from mistakes',
            ]
        ];
    }

    /**
     * Format answer for display in context
     */
    private function formatAnswer($answer): string
    {
        if (is_string($answer)) {
            return $answer;
        }
        
        if (is_array($answer)) {
            if (isset($answer['response'])) {
                return is_string($answer['response']) ? $answer['response'] : json_encode($answer['response']);
            }
            
            if (isset($answer['correct_answer'])) {
                return is_string($answer['correct_answer']) ? $answer['correct_answer'] : json_encode($answer['correct_answer']);
            }
            
            return json_encode($answer);
        }
        
        return (string) $answer;
    }

    /**
     * Check if this submission was AI graded
     */
    private function wasAIGraded(AssessmentSubmissionItem $submissionItem): bool
    {
        $metadata = $submissionItem->grading_metadata ?? [];
        return ($metadata['grading_method'] ?? '') === 'ai_powered';
    }

    /**
     * Determine the type of review needed
     */
    private function determineReviewType(AssessmentSubmissionItem $submissionItem): string
    {
        if ($submissionItem->is_correct) {
            return 'correct_answer_explanation';
        }
        
        if ($this->wasAIGraded($submissionItem)) {
            return 'ai_grading_review';
        }
        
        if ($submissionItem->marks_awarded === null) {
            return 'pending_review';
        }
        
        if ($submissionItem->marks_awarded > 0 && !$submissionItem->is_correct) {
            return 'partial_credit_explanation';
        }
        
        return 'incorrect_answer_analysis';
    }

    /**
     * Categorize the type of mistake made
     */
    private function categorizeMistake(AssessmentSubmissionItem $submissionItem): string
    {
        if ($submissionItem->is_correct) {
            return 'no_mistake';
        }
        
        $questionType = $submissionItem->question_data['question_type'] ?? 'unknown';
        $marksAwarded = $submissionItem->marks_awarded ?? 0;
        $totalMarks = $submissionItem->question_data['marks'] ?? 1;
        
        // Analyze based on question type and partial credit
        if ($marksAwarded == 0) {
            return match($questionType) {
                'mcq', 'multiple_choice' => 'wrong_option_selected',
                'short_answer', 'long_answer' => 'conceptual_misunderstanding',
                'matching' => 'incorrect_pairing',
                'ordering' => 'wrong_sequence',
                'cloze' => 'vocabulary_error',
                default => 'complete_error'
            };
        } elseif ($marksAwarded < $totalMarks) {
            return match($questionType) {
                'short_answer', 'long_answer' => 'incomplete_answer',
                'matching' => 'partial_matching_error',
                'ordering' => 'partial_sequence_error',
                'cloze' => 'some_blanks_incorrect',
                default => 'partial_understanding'
            };
        }
        
        return 'unknown_error';
    }

    /**
     * Create error response
     */
    private function createErrorResponse(string $message): array
    {
        return [
            'success' => false,
            'error' => $message,
            'response' => "I'm having trouble analyzing this answer right now. Please try again in a moment, or ask your teacher for help.",
            'agent_type' => $this->agentType
        ];
    }
}
