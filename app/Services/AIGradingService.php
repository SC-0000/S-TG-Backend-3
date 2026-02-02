<?php

namespace App\Services;

use App\Models\Question;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

class AIGradingService
{
    private string $apiKey;
    private string $model;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey = Config::get('openai.api_key');
        $this->model = Config::get('openai.connections.main.model', 'gpt-5-nano');
        $this->timeout = Config::get('openai.connections.main.timeout', 60);
    }

    /**
     * Grade a subjective answer using AI
     */
    public function gradeSubjectiveAnswer(Question $question, array $studentAnswer, string $questionType): array
    {
        try {
            Log::info('AI Grading: Starting evaluation', [
                'question_id' => $question->id,
                'question_type' => $questionType,
                'student_answer' => $studentAnswer
            ]);

            // Extract student's actual text answer
            $studentText = $this->extractStudentAnswerText($studentAnswer, $questionType);
            
            if (empty($studentText)) {
                return $this->createEmptyAnswerResult($question, $questionType);
            }

            // Generate AI prompt based on question type
            $prompt = $this->generatePrompt($question, $studentText, $questionType);
            
            // Call OpenAI API
            $aiResponse = $this->callOpenAI($prompt);
            
            if (!$aiResponse) {
                return $this->createAIErrorResult($question, $studentAnswer, $questionType);
            }

            // Parse and validate AI response
            $gradingResult = $this->parseAIResponse($aiResponse, $question);
            
            // Apply confidence-based routing
            return $this->applyConfidenceRouting($gradingResult, $question, $studentAnswer, $questionType);

        } catch (\Exception $e) {
            Log::error('AI Grading: Exception occurred', [
                'question_id' => $question->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->createAIErrorResult($question, $studentAnswer, $questionType, $e->getMessage());
        }
    }

    /**
     * Extract student's answer text from various formats
     */
    private function extractStudentAnswerText(array $studentAnswer, string $questionType): string
    {
        // Handle different possible answer formats
        if (isset($studentAnswer['response']) && is_string($studentAnswer['response'])) {
            return trim($studentAnswer['response']);
        }
        
        if (isset($studentAnswer['answer']) && is_string($studentAnswer['answer'])) {
            return trim($studentAnswer['answer']);
        }
        
        // For arrays, try to extract meaningful text
        if (isset($studentAnswer['response']) && is_array($studentAnswer['response'])) {
            if (isset($studentAnswer['response']['answer'])) {
                return trim($studentAnswer['response']['answer']);
            }
        }
        
        // Fallback: convert entire array to string (excluding system keys)
        $filteredAnswer = array_diff_key($studentAnswer, ['no_answer' => true, 'time_spent' => true]);
        if (!empty($filteredAnswer)) {
            return trim(implode(' ', array_filter($filteredAnswer, 'is_string')));
        }
        
        return '';
    }

    /**
     * Generate AI prompt based on question type and data
     */
    private function generatePrompt(Question $question, string $studentText, string $questionType): string
    {
        $questionData = $question->question_data;
        $answerSchema = $question->answer_schema;
        $questionText = $questionData['question_text'] ?? $question->title;
        
        switch ($questionType) {
            case 'short_answer':
                return $this->generateShortAnswerPrompt($questionText, $studentText, $answerSchema, $question);
                
            case 'long_answer':
            case 'essay':
                return $this->generateLongAnswerPrompt($questionText, $studentText, $answerSchema, $question);
                
            default:
                return $this->generateGenericPrompt($questionText, $studentText, $answerSchema, $question);
        }
    }

    /**
     * Generate prompt for short answer questions
     */
    private function generateShortAnswerPrompt(string $questionText, string $studentText, array $answerSchema, Question $question): string
    {
        $modelAnswer = $answerSchema['model_answer'] ?? '';
        $keyPoints = $answerSchema['key_points'] ?? [];
        $maxMarks = $question->marks ?? 1;
        
        $keyPointsText = !empty($keyPoints) ? "\nKey Points to Look For: " . implode(', ', $keyPoints) : '';
        $modelAnswerText = !empty($modelAnswer) ? "\nModel Answer: {$modelAnswer}" : '';
        
        return "You are an expert educator grading a short answer question. Be fair but thorough in your evaluation.

QUESTION: {$questionText}
{$modelAnswerText}
{$keyPointsText}

STUDENT ANSWER: {$studentText}

GRADING CRITERIA:
- Accuracy of content (40%)
- Coverage of key concepts (30%)
- Clarity of explanation (20%)
- Completeness (10%)

INSTRUCTIONS:
1. Evaluate the student's answer against the model answer and key points
2. Consider partial credit for partially correct responses
3. Be lenient with minor spelling/grammar errors unless they affect meaning
4. Focus on conceptual understanding over exact wording
6. The graded marks should reflect the quality of the answer based on the criteria above
7. The graded makrs should be whole numbers only and in 0.5 increments (e.g., 0, 0.5, 1, 1.5, ..., up to the maximum marks for the question)


RESPONSE FORMAT (JSON only, no other text):
{
    \"score_percentage\": 85,
    \"confidence_percentage\": 90,
    \"is_correct\": true,
    \"feedback\": \"Good understanding of the concept. Covered most key points clearly.\",
    \"reasoning\": \"The student demonstrated solid grasp of the main concepts and included 3 out of 4 key points. The explanation was clear and showed good understanding.\",
    \"key_points_covered\": [\"point1\", \"point2\"],
    \"key_points_missed\": [\"point3\"],
    \"suggestions\": \"Consider mentioning point3 for a more complete answer.\"
}";
    }

    /**
     * Generate prompt for long answer/essay questions
     */
    private function generateLongAnswerPrompt(string $questionText, string $studentText, array $answerSchema, Question $question): string
    {
        $modelAnswer = $answerSchema['model_answer'] ?? '';
        $gradingRubric = $answerSchema['grading_rubric'] ?? [];
        $maxMarks = $question->marks ?? 1;
        
        $modelAnswerText = !empty($modelAnswer) ? "\nModel Answer/Guidelines: {$modelAnswer}" : '';
        $rubricText = !empty($gradingRubric) ? "\nGrading Rubric: " . json_encode($gradingRubric, JSON_PRETTY_PRINT) : '';
        
        return "You are an expert educator grading a long answer/essay question. Provide comprehensive evaluation.

QUESTION: {$questionText}
{$modelAnswerText}
{$rubricText}

STUDENT ANSWER: {$studentText}

EVALUATION CRITERIA:
- Content accuracy and depth (35%)
- Logical structure and organization (20%)
- Use of examples and evidence (20%)
- Critical thinking and analysis (15%)
- Writing clarity and coherence (10%)

INSTRUCTIONS:
1. Assess the depth of understanding demonstrated
2. Evaluate the logical flow and organization of ideas
3. Consider the use of relevant examples or evidence
4. Look for original thinking and critical analysis
5. Be constructive in feedback while maintaining academic standards
6. The graded marks should reflect the quality of the answer based on the criteria above
7. The graded makrs should be whole numbers only and in 0.5 increments (e.g., 0, 0.5, 1, 1.5, ..., up to the maximum marks for the question)

RESPONSE FORMAT (JSON only, no other text):
{
    \"score_percentage\": 78,
    \"confidence_percentage\": 85,
    \"is_correct\": true,
    \"feedback\": \"Strong answer with good analysis. Well-structured arguments with relevant examples.\",
    \"reasoning\": \"The student provided a comprehensive response covering the main aspects of the question. The analysis was thoughtful and supported with appropriate examples.\",
    \"strengths\": [\"Clear structure\", \"Good examples\", \"Critical thinking\"],
    \"areas_for_improvement\": [\"Could elaborate more on point X\", \"Minor organizational issues\"],
    \"content_coverage\": \"85%\",
    \"writing_quality\": \"Good\"
}";
    }

    /**
     * Generate generic prompt for other question types
     */
    private function generateGenericPrompt(string $questionText, string $studentText, array $answerSchema, Question $question): string
    {
        $modelAnswer = $answerSchema['model_answer'] ?? '';
        $maxMarks = $question->marks ?? 1;
        
        return "You are an expert educator. Evaluate this student's answer fairly and thoroughly.

QUESTION: {$questionText}
MODEL ANSWER: {$modelAnswer}
STUDENT ANSWER: {$studentText}

Provide a fair assessment considering:
- Accuracy of the response
- Understanding demonstrated
- Completeness of the answer
- Quality of explanation

RESPONSE FORMAT (JSON only):
{
    \"score_percentage\": 75,
    \"confidence_percentage\": 80,
    \"is_correct\": true,
    \"feedback\": \"Good answer with minor gaps.\",
    \"reasoning\": \"Student shows understanding but missed some details.\",
    \"suggestions\": \"Consider adding more detail about X.\"
}";
    }

    /**
     * Call OpenAI API with the generated prompt
     */
    private function callOpenAI(string $prompt): ?array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $this->model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are an expert educational assessment AI. Always respond with valid JSON only, no other text or formatting.'
                        ],
                        [
                            'role' => 'user', 
                            'content' => $prompt
                        ]
                    ],
                    'temperature' => 0.3, // Lower temperature for more consistent grading
                    'max_tokens' => 1000,
                    'top_p' => 0.9,
                ]);

            if (!$response->successful()) {
                Log::error('OpenAI API Error', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return null;
            }

            $data = $response->json();
            
            if (!isset($data['choices'][0]['message']['content'])) {
                Log::error('Invalid OpenAI response structure', ['data' => $data]);
                return null;
            }

            // Extract and parse JSON from the response
            $content = trim($data['choices'][0]['message']['content']);
            
            // Clean up any markdown code block formatting
            $content = preg_replace('/^```json\s*|\s*```$/m', '', $content);
            $content = trim($content);
            
            $parsedResponse = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Failed to parse OpenAI JSON response', [
                    'content' => $content,
                    'json_error' => json_last_error_msg()
                ]);
                return null;
            }

            return $parsedResponse;

        } catch (\Exception $e) {
            Log::error('OpenAI API call failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Parse and validate AI response
     */
    private function parseAIResponse(array $aiResponse, Question $question): array
    {
        // Validate required fields
        $score = max(0, min(100, (float) ($aiResponse['score_percentage'] ?? 0)));
        $confidence = max(0, min(100, (float) ($aiResponse['confidence_percentage'] ?? 50)));
        $isCorrect = $score >= 50; // Consider 50%+ as correct
        
        // Calculate actual marks based on percentage
        $maxMarks = (float) $question->marks;
        $marksAwarded = round(($score / 100) * $maxMarks, 2);
        
        return [
            'score' => $marksAwarded,
            'max_score' => $maxMarks,
            'percentage' => $score,
            'is_correct' => $isCorrect,
            'confidence' => $confidence,
            'feedback' => $aiResponse['feedback'] ?? 'AI evaluation completed.',
            'ai_reasoning' => $aiResponse['reasoning'] ?? '',
            'ai_suggestions' => $aiResponse['suggestions'] ?? '',
            'ai_raw_response' => $aiResponse,
            'details' => [
                'ai_model' => $this->model,
                'grading_timestamp' => now()->toISOString(),
                'confidence_level' => $confidence,
                'ai_feedback' => $aiResponse['feedback'] ?? '',
                'ai_reasoning' => $aiResponse['reasoning'] ?? '',
            ]
        ];
    }

    /**
     * Apply confidence-based routing logic
     */
    private function applyConfidenceRouting(array $gradingResult, Question $question, array $studentAnswer, string $questionType): array
    {
        $confidence = $gradingResult['confidence'];
        $requiresManualReview = false;
        $routingReason = '';
        
        // Confidence-based routing thresholds
        if ($confidence < 70) {
            $requiresManualReview = true;
            $routingReason = 'Low AI confidence requires human review';
        } elseif ($confidence < 90 && $gradingResult['score'] == 0) {
            // Be extra careful with zero scores
            $requiresManualReview = true;
            $routingReason = 'Zero score with medium confidence requires verification';
        }

        // Build final result in your existing format
        return [
            'question_type' => 'bank',
            'bank_question_id' => $question->id,
            'inline_question_index' => null,
            'question_data' => $this->formatQuestionData($question),
            'answer' => $studentAnswer,
            'is_correct' => $gradingResult['is_correct'],
            'marks_awarded' => $requiresManualReview ? null : $gradingResult['score'],
            'grading_metadata' => [
                'auto_graded' => !$requiresManualReview,
                'grading_method' => 'ai_powered',
                'ai_model' => $this->model,
                'confidence_level' => $confidence,
                'requires_human_review' => $requiresManualReview,
                'routing_reason' => $routingReason,
                'question_type_used' => $questionType,
                'ai_raw_response' => $gradingResult['ai_raw_response'],
                'ai_reasoning' => $gradingResult['ai_reasoning'],
                'flaggable_by_student' => !$requiresManualReview, // Only allow flagging if AI actually graded it
                'grading_timestamp' => now()->toISOString(),
                'percentage' => $gradingResult['percentage'],
                'handler_details' => $gradingResult['details'],
            ],
            'detailed_feedback' => $this->formatDetailedFeedback($gradingResult, $requiresManualReview),
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
     * Format detailed feedback
     */
    private function formatDetailedFeedback(array $gradingResult, bool $requiresManualReview): string
    {
        if ($requiresManualReview) {
            return "This answer requires manual review due to AI uncertainty. Score: {$gradingResult['percentage']}% (Confidence: {$gradingResult['confidence']}%)";
        }

        $feedback = [$gradingResult['feedback']];
        
        if (!empty($gradingResult['ai_suggestions'])) {
            $feedback[] = "Suggestions: " . $gradingResult['ai_suggestions'];
        }
        
        $feedback[] = "Score: {$gradingResult['percentage']}% (AI Confidence: {$gradingResult['confidence']}%)";
        
        return implode(' | ', $feedback);
    }

    /**
     * Create result for empty answers
     */
    private function createEmptyAnswerResult(Question $question, string $questionType): array
    {
        return [
            'question_type' => 'bank',
            'bank_question_id' => $question->id,
            'inline_question_index' => null,
            'question_data' => $this->formatQuestionData($question),
            'answer' => ['no_answer' => true],
            'is_correct' => false,
            'marks_awarded' => 0,
            'grading_metadata' => [
                'auto_graded' => true,
                'grading_method' => 'ai_powered',
                'ai_model' => $this->model,
                'confidence_level' => 100,
                'requires_human_review' => false,
                'routing_reason' => 'No answer provided',
                'question_type_used' => $questionType,
                'flaggable_by_student' => false,
            ],
            'detailed_feedback' => 'No answer provided.',
            'time_spent' => null,
        ];
    }

    /**
     * Create result for AI errors
     */
    private function createAIErrorResult(Question $question, array $studentAnswer, string $questionType, string $error = null): array
    {
        return [
            'question_type' => 'bank',
            'bank_question_id' => $question->id,
            'inline_question_index' => null,
            'question_data' => $this->formatQuestionData($question),
            'answer' => $studentAnswer,
            'is_correct' => null,
            'marks_awarded' => null,
            'grading_metadata' => [
                'auto_graded' => false,
                'grading_method' => 'ai_error_fallback',
                'ai_model' => $this->model,
                'confidence_level' => 0,
                'requires_human_review' => true,
                'routing_reason' => 'AI grading failed - requires manual review',
                'question_type_used' => $questionType,
                'error' => $error ?? 'AI service temporarily unavailable',
                'flaggable_by_student' => false,
            ],
            'detailed_feedback' => 'AI grading temporarily unavailable - this answer will be reviewed manually.',
            'time_spent' => null,
        ];
    }

    /**
     * Check if AI grading is enabled and properly configured
     */
    public function isAvailable(): bool
    {
        return !empty($this->apiKey) && !empty($this->model);
    }

    /**
     * Get AI grading statistics for monitoring
     */
    public function getGradingStats(): array
    {
        // This could be expanded to include actual statistics from database
        return [
            'model' => $this->model,
            'api_configured' => !empty($this->apiKey),
            'service_available' => $this->isAvailable(),
            'supported_question_types' => ['short_answer', 'long_answer', 'essay']
        ];
    }
}
