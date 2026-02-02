<?php

namespace App\Services\QuestionTypes;

class OrderingQuestionType implements QuestionTypeInterface
{
    public static function validate(array $questionData): bool
    {
        return isset($questionData['question_text']) && 
               isset($questionData['items']) && 
               is_array($questionData['items']) && 
               !empty($questionData['items']);
    }

    public static function grade(array $questionData, array $answerSchema, array $response): array
    {
        $correctOrder = $questionData['correct_order'] ?? [];
        $studentOrder = $response['order'] ?? [];
        
        if (empty($correctOrder) || empty($studentOrder)) {
            return [
                'score' => 0,
                'max_score' => 1,
                'percentage' => 0,
                'is_correct' => false,
                'feedback' => 'Invalid response format.',
                'details' => []
            ];
        }

        $isCorrect = $correctOrder === $studentOrder;
        $score = $isCorrect ? 1 : 0;

        return [
            'score' => $score,
            'max_score' => 1,
            'percentage' => $isCorrect ? 100 : 0,
            'is_correct' => $isCorrect,
            'feedback' => $isCorrect ? 'Correct order!' : 'Incorrect order.',
            'details' => [
                'correct_order' => $correctOrder,
                'student_order' => $studentOrder
            ]
        ];
    }

    public static function renderForStudent(array $questionData): array
    {
        $items = $questionData['items'];
        if ($questionData['shuffle'] ?? true) {
            shuffle($items);
        }

        return [
            'question_text' => $questionData['question_text'],
            'items' => $items,
            'instructions' => $questionData['instructions'] ?? 'Arrange the items in the correct order.',
        ];
    }

    public static function renderForAdmin(array $questionData, array $answerSchema): array
    {
        return array_merge($questionData, ['answer_schema' => $answerSchema]);
    }

    public static function getDefaultData(): array
    {
        return [
            'question_text' => '',
            'items' => [
                          ],
            'correct_order' => [],
            'shuffle' => true,
            'instructions' => '',
        ];
    }

    public static function getDefaultAnswerSchema(): array
    {
        return [
            'partial_credit' => false,
            'strict_order' => true,
        ];
    }

    public static function getResponseFormat(): array
    {
        return ['order' => 'array'];
    }

    public static function validateResponse(array $response): bool
    {
        return isset($response['order']) && is_array($response['order']);
    }
}
