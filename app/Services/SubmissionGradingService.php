<?php

namespace App\Services;

use App\Models\Question;
use Illuminate\Support\Facades\Log;

class SubmissionGradingService
{
    private AIGradingService $aiGradingService;

    public function __construct(AIGradingService $aiGradingService)
    {
        $this->aiGradingService = $aiGradingService;
    }
    /**
     * Auto-gradable question types with their expected answer formats
     */
    private const QUESTION_TYPE_FORMATS = [
        'mcq' => 'selected_options',
        'cloze' => 'answers', 
        'matching' => 'pairs',
        'ordering' => 'order',
        'image_grid_mcq' => 'selected_images',
        'comprehension' => 'sub_answers'
    ];

    /**
     * Manual grading question types
     */
    private const MANUAL_GRADING_TYPES = [
        'long_answer',
        'essay', 
        'short_answer'
    ];

    /**
     * Grade a bank question according to database schema with proper answer format handling
     */
    public function gradeQuestionResponse(Question $question, array $studentAnswer, int $questionIndex): array
    {
        try {
            $questionType = $question->question_type;
            
            Log::info('Grading bank question with enhanced processing', [
                'question_id' => $question->id,
                'question_type' => $questionType,
                'raw_student_answer' => $studentAnswer,
                'answer_schema' => $question->answer_schema
            ]);

            // Ensure answer is never null (database constraint)
            $answer = !empty($studentAnswer) ? $studentAnswer : ['no_answer' => true];

            // Check if this is a manual grading type - try AI grading first
            if (in_array($questionType, self::MANUAL_GRADING_TYPES)) {
                return $this->handleManualGradingType($question, $answer, $questionType);
            }

            // Check if this is an auto-gradable type
            if (!array_key_exists($questionType, self::QUESTION_TYPE_FORMATS)) {
                return $this->createUnsupportedTypeResult($question, $answer);
            }

            // Convert and validate answer format for this question type
            $formattedAnswer = $this->formatAnswerForQuestionType($questionType, $studentAnswer, $question);
            
            if ($formattedAnswer === null) {
                return $this->createInvalidAnswerResult($question, $answer);
            }

            // Grade using the appropriate question type handler
            $result = $question->gradeResponse($formattedAnswer);
            
            // Normalize and enhance the result
            return $this->normalizeGradingResult($question, $answer, $result, $questionType);
            
        } catch (\Exception $e) {
            Log::error('Error grading question with enhanced service', [
                'question_id' => $question->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'student_answer' => $studentAnswer
            ]);
            
            return $this->createFailedGradingResult($question, $studentAnswer, $e->getMessage());
        }
    }

    /**
     * Format student answer according to question type requirements
     */
    private function formatAnswerForQuestionType(string $questionType, array $studentAnswer, Question $question): ?array
    {
        $questionData = $question->question_data;
        $answerSchema = $question->answer_schema;

        try {
            switch ($questionType) {
                case 'mcq':
                    return $this->formatMcqAnswer($studentAnswer, $questionData, $answerSchema);
                
                case 'cloze':
                    return $this->formatClozeAnswer($studentAnswer, $questionData, $answerSchema);
                
                case 'matching':
                    return $this->formatMatchingAnswer($studentAnswer, $questionData, $answerSchema);
                
                case 'ordering':
                    return $this->formatOrderingAnswer($studentAnswer, $questionData, $answerSchema);
                
                case 'image_grid_mcq':
                    return $this->formatImageGridMcqAnswer($studentAnswer, $questionData, $answerSchema);
                
                case 'comprehension':
                    return $this->formatComprehensionAnswer($studentAnswer, $questionData, $answerSchema);
                
                default:
                    Log::warning("Unhandled question type in formatAnswerForQuestionType: {$questionType}");
                    return null;
            }
        } catch (\Exception $e) {
            Log::error("Answer formatting failed for question type {$questionType}", [
                'error' => $e->getMessage(),
                'student_answer' => $studentAnswer
            ]);
            return null;
        }
    }

    /**
     * Format MCQ answer to expected format: {'selected_options': ['a', 'b']}
     */
    private function formatMcqAnswer(array $studentAnswer, array $questionData, array $answerSchema): array
    {
        $options = $questionData['options'] ?? [];
        
        // Handle different possible input formats
        if (isset($studentAnswer['selected_options'])) {
            // Already in correct format
            $selectedOptions = $studentAnswer['selected_options'];
        } elseif (isset($studentAnswer['answer'])) {
            // Single answer or comma-separated
            $answer = $studentAnswer['answer'];
            $selectedOptions = is_array($answer) ? $answer : explode(',', trim($answer));
        } elseif (isset($studentAnswer['response'])) {
            // Response format - could be numerical index or option ID
            $response = $studentAnswer['response'];
            $selectedOptions = is_array($response) ? $response : [$response];
        } else {
            // Try to extract from any array values
            $selectedOptions = array_values(array_filter($studentAnswer));
        }

        // Convert numerical indices to option IDs if needed
        $convertedOptions = [];
        foreach ($selectedOptions as $option) {
            $option = trim((string)$option);
            
            // If it's a numerical index, convert to option ID
            if (is_numeric($option)) {
                $index = (int)$option;
                if (isset($options[$index]) && isset($options[$index]['id'])) {
                    $convertedOptions[] = $options[$index]['id'];
                }
            } else {
                // Already an option ID (a, b, c, d, etc.)
                $convertedOptions[] = $option;
            }
        }

        // Clean and validate options
        $convertedOptions = array_filter($convertedOptions);
        
        return ['selected_options' => array_values($convertedOptions)];
    }

    /**
     * Format Cloze answer to expected format: {'answers': {'blank1': 'answer1', 'blank2': 'answer2'}}
     */
    private function formatClozeAnswer(array $studentAnswer, array $questionData, array $answerSchema): array
    {
        if (isset($studentAnswer['answers']) && is_array($studentAnswer['answers'])) {
            // Already in correct format
            return ['answers' => $studentAnswer['answers']];
        }
        
        // Try to construct from other formats
        $answers = [];
        $blanks = $questionData['blanks'] ?? [];
        $blankIds = array_column($blanks, 'id');
        
        if (isset($studentAnswer['response']) && is_array($studentAnswer['response'])) {
            // Array of responses indexed by blank id
            foreach ($blanks as $blank) {
                $blankId = $blank['id'];
                $answers[$blankId] = $studentAnswer['response'][$blankId] ?? '';
            }
        } else {
            // Handle direct key-value format: {'blank1': 'answer1', 'blank2': 'answer2'}
            // This is common format from frontend
            foreach ($studentAnswer as $key => $value) {
                if (in_array($key, $blankIds) && !in_array($key, ['answers', 'response'], true)) {
                    $answers[$key] = (string)$value;
                }
            }
            
            // Fallback: Try to map any provided answers to blanks by position
            if (empty($answers)) {
                $responses = array_values(array_filter($studentAnswer, function($value, $key) {
                    return !in_array($key, ['answers', 'response'], true);
                }, ARRAY_FILTER_USE_BOTH));
                
                foreach ($blankIds as $index => $blankId) {
                    $answers[$blankId] = $responses[$index] ?? '';
                }
            }
        }
        
        return ['answers' => $answers];
    }

    /**
     * Format Matching answer to expected format: {'pairs': [{'left': 'A', 'right': '1'}]}
     */
    private function formatMatchingAnswer(array $studentAnswer, array $questionData, array $answerSchema): array
    {
        if (isset($studentAnswer['pairs']) && is_array($studentAnswer['pairs'])) {
            // Already in correct format
            return ['pairs' => $studentAnswer['pairs']];
        }
        
        // Try to construct pairs from other formats
        $pairs = [];
        
        if (isset($studentAnswer['matches']) && is_array($studentAnswer['matches'])) {
            // Format: {'matches': {'A': '1', 'B': '2'}}
            foreach ($studentAnswer['matches'] as $left => $right) {
                $pairs[] = ['left' => $left, 'right' => $right];
            }
        } elseif (isset($studentAnswer['response']) && is_array($studentAnswer['response'])) {
            // Format: {'response': [{'left': 'A', 'right': '1'}]}
            $pairs = $studentAnswer['response'];
        } else {
            // Handle direct key-value format: {'2': '4', '7': '49', '9': '81'}
            // This is the most common format from frontend
            foreach ($studentAnswer as $key => $value) {
                if ($key !== 'pairs' && $key !== 'matches' && $key !== 'response') {
                    $pairs[] = ['left' => (string)$key, 'right' => (string)$value];
                }
            }
        }
        
        return ['pairs' => $pairs];
    }

    /**
     * Format Ordering answer to expected format: {'order': ['item1', 'item2', 'item3']}
     */
    private function formatOrderingAnswer(array $studentAnswer, array $questionData, array $answerSchema): array
    {
        if (isset($studentAnswer['order'])) {
            // Already in correct format
            return ['order' => $studentAnswer['order']];
        }
        
        // Extract from other possible formats
        if (isset($studentAnswer['ordered_items'])) {
            return ['order' => $studentAnswer['ordered_items']];
        } elseif (isset($studentAnswer['response'])) {
            $response = $studentAnswer['response'];
            return ['order' => is_array($response) ? $response : [$response]];
        } else {
            // Handle direct array format like ["-12", "-3", "0", "4", "12"]
            // Use array_filter with callback to keep values that are not null, but preserve "0" and empty string
            $filteredOrder = array_values(array_filter($studentAnswer, function($value, $key) {
                // Only filter out null values and non-string keys, keep everything else including "0"
                return !is_null($value) && !in_array($key, ['order', 'ordered_items', 'response'], true);
            }, ARRAY_FILTER_USE_BOTH));
            
            return ['order' => $filteredOrder];
        }
    }

    /**
     * Format Image Grid MCQ answer to expected format: {'selected_images': ['img1', 'img2']}
     */
    private function formatImageGridMcqAnswer(array $studentAnswer, array $questionData, array $answerSchema): array
    {
        if (isset($studentAnswer['selected_images'])) {
            // Already in correct format
            return ['selected_images' => $studentAnswer['selected_images']];
        }
        
        // Try other formats
        if (isset($studentAnswer['selected_options'])) {
            return ['selected_images' => $studentAnswer['selected_options']];
        } elseif (isset($studentAnswer['response'])) {
            $response = $studentAnswer['response'];
            return ['selected_images' => is_array($response) ? $response : [$response]];
        }
        
        return ['selected_images' => []];
    }

    /**
     * Format Comprehension answer to expected format: {'sub_answers': [response1, response2, ...]}
     */
    private function formatComprehensionAnswer(array $studentAnswer, array $questionData, array $answerSchema): array
    {
        if (isset($studentAnswer['sub_answers']) && is_array($studentAnswer['sub_answers'])) {
            // Already in correct format
            return ['sub_answers' => $studentAnswer['sub_answers']];
        }
        
        // Try to construct from other formats
        $subAnswers = [];
        $subQuestions = $questionData['sub_questions'] ?? [];
        
        if (isset($studentAnswer['responses']) && is_array($studentAnswer['responses'])) {
            $subAnswers = $studentAnswer['responses'];
        } elseif (isset($studentAnswer['answers']) && is_array($studentAnswer['answers'])) {
            $subAnswers = $studentAnswer['answers'];
        } else {
            // Try to extract individual responses
            foreach ($subQuestions as $index => $subQuestion) {
                $subAnswers[$index] = $studentAnswer["answer_{$index}"] ?? $studentAnswer[$index] ?? ['response' => ''];
            }
        }
        
        return ['sub_answers' => array_values($subAnswers)];
    }

    /**
     * Normalize grading result to consistent database format
     */
    private function normalizeGradingResult(Question $question, array $answer, array $result, string $questionType): array
    {
        // Calculate actual marks awarded based on question's total marks
        $maxMarks = (float) $question->marks;
        $scoreRatio = ($result['max_score'] ?? 1) > 0 ? 
            ($result['score'] ?? 0) / ($result['max_score'] ?? 1) : 0;
        $marksAwarded = round($maxMarks * $scoreRatio, 2);

        return [
            'question_type' => 'bank',
            'bank_question_id' => $question->id,
            'inline_question_index' => null,
            'question_data' => $this->formatQuestionData($question),
            'answer' => $answer,
            'is_correct' => $result['is_correct'] ?? false,
            'marks_awarded' => $marksAwarded,
            'grading_metadata' => [
                'auto_graded' => true,
                'grading_method' => 'enhanced_question_handler',
                'confidence_level' => $result['confidence'] ?? 1.0,
                'requires_human_review' => false,
                'question_type_used' => $questionType,
                'raw_score' => $result['score'] ?? 0,
                'max_raw_score' => $result['max_score'] ?? 1,
                'percentage' => $result['percentage'] ?? round($scoreRatio * 100, 2),
                'handler_details' => $result['details'] ?? null,
            ],
            'detailed_feedback' => $this->enhanceFeedback($result['feedback'] ?? 'Answer processed.', $result),
            'time_spent' => null,
        ];
    }

    /**
     * Handle manual grading types - try AI grading first, fallback to manual
     */
    private function handleManualGradingType(Question $question, array $answer, string $questionType): array
    {
        // First, try AI grading if available and enabled
        if ($this->aiGradingService->isAvailable()) {
            try {
                Log::info('Attempting AI grading for manual question type', [
                    'question_id' => $question->id,
                    'question_type' => $questionType
                ]);

                $aiResult = $this->aiGradingService->gradeSubjectiveAnswer($question, $answer, $questionType);
                
                // If AI grading succeeded and doesn't require manual review, use it
                if ($aiResult && !($aiResult['grading_metadata']['requires_human_review'] ?? true)) {
                    Log::info('AI grading successful for manual question type', [
                        'question_id' => $question->id,
                        'confidence' => $aiResult['grading_metadata']['confidence_level'] ?? 0,
                        'score' => $aiResult['marks_awarded'] ?? 0
                    ]);
                    return $aiResult;
                }

                // If AI grading requires review, still use it but mark for manual review
                if ($aiResult && ($aiResult['grading_metadata']['requires_human_review'] ?? true)) {
                    Log::info('AI grading completed but requires manual review', [
                        'question_id' => $question->id,
                        'confidence' => $aiResult['grading_metadata']['confidence_level'] ?? 0,
                        'routing_reason' => $aiResult['grading_metadata']['routing_reason'] ?? 'Unknown'
                    ]);
                    return $aiResult;
                }

            } catch (\Exception $e) {
                Log::warning('AI grading failed, falling back to manual', [
                    'question_id' => $question->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Fallback to traditional manual grading
        return $this->createManualGradingResult($question, $answer, $questionType);
    }

    /**
     * Create manual grading result (fallback method)
     */
    private function createManualGradingResult(Question $question, array $answer, string $questionType): array
    {
        return [
            'question_type' => 'bank',
            'bank_question_id' => $question->id,
            'inline_question_index' => null,
            'question_data' => $this->formatQuestionData($question),
            'answer' => $answer,
            'is_correct' => null,
            'marks_awarded' => null, // Will be set manually by admin
            'grading_metadata' => [
                'auto_graded' => false,
                'grading_method' => 'manual_required',
                'requires_human_review' => true,
                'question_type_used' => $questionType,
                'manual_reason' => $this->getManualGradingReason($questionType),
                'ai_available' => $this->aiGradingService->isAvailable(),
                'ai_attempted' => $this->aiGradingService->isAvailable(),
            ],
            'detailed_feedback' => $this->aiGradingService->isAvailable() 
                ? 'This question requires manual grading by an administrator.'
                : 'This question requires manual grading (AI grading not available).',
            'time_spent' => null,
        ];
    }

    /**
     * Create result for unsupported question types
     */
    private function createUnsupportedTypeResult(Question $question, array $answer): array
    {
        return [
            'question_type' => 'bank',
            'bank_question_id' => $question->id,
            'inline_question_index' => null,
            'question_data' => $this->formatQuestionData($question),
            'answer' => $answer,
            'is_correct' => null,
            'marks_awarded' => null,
            'grading_metadata' => [
                'auto_graded' => false,
                'grading_method' => 'unsupported_type',
                'requires_human_review' => true,
                'question_type_used' => $question->question_type,
                'error' => 'Question type not supported for auto-grading',
            ],
            'detailed_feedback' => 'Question type not supported for auto-grading - requires manual review.',
            'time_spent' => null,
        ];
    }

    /**
     * Create result for invalid answer format
     */
    private function createInvalidAnswerResult(Question $question, array $answer): array
    {
        return [
            'question_type' => 'bank',
            'bank_question_id' => $question->id,
            'inline_question_index' => null,
            'question_data' => $this->formatQuestionData($question),
            'answer' => $answer,
            'is_correct' => null,
            'marks_awarded' => null,
            'grading_metadata' => [
                'auto_graded' => false,
                'grading_method' => 'invalid_answer_format',
                'requires_human_review' => true,
                'question_type_used' => $question->question_type,
                'error' => 'Answer format could not be converted for grading',
            ],
            'detailed_feedback' => 'Answer format invalid - requires manual review.',
            'time_spent' => null,
        ];
    }

    /**
     * Create failed grading result
     */
    private function createFailedGradingResult(Question $question, array $studentAnswer, string $error): array
    {
        $answer = !empty($studentAnswer) ? $studentAnswer : ['error' => 'Failed to process answer'];
        
        return [
            'question_type' => 'bank',
            'bank_question_id' => $question->id,
            'inline_question_index' => null,
            'question_data' => $this->formatQuestionData($question),
            'answer' => $answer,
            'is_correct' => null,
            'marks_awarded' => null,
            'grading_metadata' => [
                'auto_graded' => false,
                'grading_method' => 'failed',
                'error' => $error,
                'requires_human_review' => true,
                'question_type_used' => $question->question_type,
            ],
            'detailed_feedback' => 'Grading failed - requires manual review.',
            'time_spent' => null,
        ];
    }

    /**
     * Format question data for storage
     */
    private function formatQuestionData(Question $question): array
    {
        return [
            'id' => $question->id,
            'title' => $question->title,
            'question_type' => $question->question_type,
            'question_data' => $question->question_data,
            'answer_schema' => $question->answer_schema,
            'marks' => $question->marks,
            'category' => $question->category,
            'subcategory' => $question->subcategory,
            'grade' => $question->grade,
            'difficulty_level' => $question->difficulty_level,
            'hints' => $question->hints,
            'solutions' => $question->solutions,
        ];
    }

    /**
     * Enhance feedback with additional context
     */
    private function enhanceFeedback(string $baseFeedback, array $result): string
    {
        $feedback = [$baseFeedback];
        
        if (isset($result['percentage'])) {
            $feedback[] = "Score: {$result['percentage']}%";
        }
        
        if (isset($result['details']) && is_array($result['details'])) {
            // Add specific details based on question type
            $details = $result['details'];
            if (isset($details['correct_selected']) && isset($details['incorrect_selected'])) {
                // MCQ details
                if (!empty($details['correct_selected'])) {
                    $feedback[] = "Correctly selected: " . implode(', ', $details['correct_selected']);
                }
                if (!empty($details['incorrect_selected'])) {
                    $feedback[] = "Incorrectly selected: " . implode(', ', $details['incorrect_selected']);
                }
            }
        }
        
        return implode(' | ', array_filter($feedback));
    }

    /**
     * Get reason for manual grading
     */
    private function getManualGradingReason(string $questionType): string
    {
        return match($questionType) {
            'long_answer', 'essay' => 'Essay questions require human evaluation for content quality, structure, and critical thinking.',
            'short_answer' => 'Short answer questions may require contextual understanding and human judgment.',
            default => 'This question type requires manual review.'
        };
    }
}
