<?php

namespace App\Services\QuestionTypes;

class ShortAnswerQuestionType implements QuestionTypeInterface
{
    public static function validate(array $questionData): bool
    {
        return isset($questionData['question_text']) && !empty($questionData['question_text']);
    }

    public static function grade(array $questionData, array $answerSchema, array $response): array
    {
        $studentAnswer = trim($response['answer'] ?? '');
        $modelAnswer = $answerSchema['model_answer'] ?? '';
        $keyPoints = $answerSchema['key_points'] ?? [];
        $caseSensitive = $answerSchema['case_sensitive'] ?? false;
        $exactMatch = $answerSchema['exact_match'] ?? false;

        if (empty($studentAnswer)) {
            return [
                'score' => 0,
                'max_score' => $answerSchema['max_marks'] ?? 1,
                'percentage' => 0,
                'is_correct' => false,
                'feedback' => 'No answer provided.',
                'requires_manual_grading' => false,
                'details' => []
            ];
        }

        if ($exactMatch) {
            // Exact match grading
            $compareStudent = $caseSensitive ? $studentAnswer : strtolower($studentAnswer);
            $compareModel = $caseSensitive ? $modelAnswer : strtolower($modelAnswer);
            
            $isCorrect = $compareStudent === $compareModel;
            
            return [
                'score' => $isCorrect ? ($answerSchema['max_marks'] ?? 1) : 0,
                'max_score' => $answerSchema['max_marks'] ?? 1,
                'percentage' => $isCorrect ? 100 : 0,
                'is_correct' => $isCorrect,
                'feedback' => $isCorrect ? 'Correct answer!' : 'Incorrect answer.',
                'requires_manual_grading' => false,
                'details' => [
                    'expected' => $modelAnswer,
                    'provided' => $studentAnswer
                ]
            ];
        }

        if (!empty($keyPoints)) {
            // Key points-based grading
            $foundPoints = [];
            $studentLower = strtolower($studentAnswer);
            
            foreach ($keyPoints as $point) {
                $pointLower = strtolower($point);
                if (strpos($studentLower, $pointLower) !== false) {
                    $foundPoints[] = $point;
                }
            }
            
            $score = count($foundPoints);
            $maxScore = count($keyPoints);
            $percentage = $maxScore > 0 ? ($score / $maxScore) * 100 : 0;
            
            return [
                'score' => $score,
                'max_score' => $maxScore,
                'percentage' => round($percentage, 2),
                'is_correct' => $score === $maxScore,
                'feedback' => $score > 0 
                    ? "Found {$score} out of {$maxScore} key points: " . implode(', ', $foundPoints)
                    : "No key points identified in the answer.",
                'requires_manual_grading' => $answerSchema['requires_manual_review'] ?? true,
                'details' => [
                    'key_points_found' => $foundPoints,
                    'key_points_missed' => array_diff($keyPoints, $foundPoints),
                    'total_key_points' => $maxScore
                ]
            ];
        }

        // Default: requires manual grading
        return [
            'score' => 0,
            'max_score' => $answerSchema['max_marks'] ?? 1,
            'percentage' => 0,
            'is_correct' => false,
            'feedback' => 'This answer requires manual grading.',
            'requires_manual_grading' => true,
            'details' => [
                'student_answer' => $studentAnswer,
                'model_answer' => $modelAnswer
            ]
        ];
    }

    public static function renderForStudent(array $questionData): array
    {
        return [
            'question_text' => $questionData['question_text'],
            'question_image' => $questionData['question_image'] ?? null,
            'max_length' => $questionData['max_length'] ?? 500,
            'min_length' => $questionData['min_length'] ?? 1,
            'placeholder' => $questionData['placeholder'] ?? 'Enter your answer here...',
        ];
    }

    public static function renderForAdmin(array $questionData, array $answerSchema): array
    {
        return array_merge($questionData, [
            'answer_schema' => $answerSchema,
        ]);
    }

    public static function getDefaultData(): array
    {
        return [
            'question_text' => '',
            'question_image' => null,
            'max_length' => 500,
            'min_length' => 1,
            'placeholder' => 'Enter your answer here...',
        ];
    }

    public static function getDefaultAnswerSchema(): array
    {
        return [
            'model_answer' => '',
            'key_points' => [],
            'max_marks' => 1,
            'case_sensitive' => false,
            'exact_match' => false,
            'requires_manual_review' => true,
            'grading_rubric' => [
                'excellent' => ['min_points' => 3, 'marks' => 1.0],
                'good' => ['min_points' => 2, 'marks' => 0.7],
                'fair' => ['min_points' => 1, 'marks' => 0.4],
                'poor' => ['min_points' => 0, 'marks' => 0.0],
            ],
        ];
    }

    public static function getResponseFormat(): array
    {
        return [
            'answer' => 'string',
        ];
    }

    public static function validateResponse(array $response): bool
    {
        return isset($response['answer']) && is_string($response['answer']);
    }
}
