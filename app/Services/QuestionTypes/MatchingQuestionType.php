<?php

namespace App\Services\QuestionTypes;

class MatchingQuestionType implements QuestionTypeInterface
{
    public static function validate(array $questionData): bool
    {
        return isset($questionData['question_text']) && 
               isset($questionData['matching_pairs']) &&
               is_array($questionData['matching_pairs']) &&
               !empty($questionData['matching_pairs']);
    }

    public static function grade(array $questionData, array $answerSchema, array $response): array
    {
        $matchingPairs = $questionData['matching_pairs'] ?? [];
        $studentMatches = $response['pairs'] ?? [];
        
        $totalMatches = count($matchingPairs);
        $correctCount = 0;
        $correctMatches = [];
        
        // Build correct matches from matching_pairs
        foreach ($matchingPairs as $pair) {
            $correctMatches[$pair['left']] = $pair['right'];
        }
        
        // Check student answers
        foreach ($studentMatches as $match) {
            $left = $match['left'] ?? null;
            $right = $match['right'] ?? null;
            if ($left && $right && isset($correctMatches[$left]) && $correctMatches[$left] === $right) {
                $correctCount++;
            }
        }
        
        $score = $totalMatches > 0 ? $correctCount / $totalMatches : 0;
        $percentage = $score * 100;

        return [
            'score' => $score,
            'max_score' => 1,
            'percentage' => round($percentage, 2),
            'is_correct' => $correctCount === $totalMatches,
            'feedback' => "You got {$correctCount} out of {$totalMatches} matches correct.",
            'details' => [
                'correct_matches' => $correctMatches,
                'student_matches' => $studentMatches,
                'correct_count' => $correctCount,
                'total_matches' => $totalMatches
            ]
        ];
    }

    public static function renderForStudent(array $questionData): array
    {
        $matchingPairs = $questionData['matching_pairs'] ?? [];
        $leftItems = [];
        $rightItems = [];
        
        // Extract left and right items from matching_pairs
        foreach ($matchingPairs as $pair) {
            $leftItems[] = $pair['left'];
            $rightItems[] = $pair['right'];
        }
        
        // Remove duplicates
        $leftItems = array_unique($leftItems);
        $rightItems = array_unique($rightItems);
        
        // Shuffle right items if requested
        if ($questionData['shuffle_right'] ?? true) {
            shuffle($rightItems);
        }

        return [
            'question_text' => $questionData['question_text'],
            'left_items' => array_values($leftItems),
            'right_items' => array_values($rightItems),
            'instructions' => $questionData['instructions'] ?? 'Match each item on the left with the correct item on the right.',
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
            'matching_pairs' => [
                ['left' => '', 'right' => ''],
                ['left' => '', 'right' => ''],
                ['left' => '', 'right' => '']
            ],
            'shuffle_right' => true,
            'instructions' => 'Match each item on the left with the correct item on the right.',
        ];
    }

    public static function getDefaultAnswerSchema(): array
    {
        return [
            'partial_credit' => true,
            'all_or_nothing' => false,
        ];
    }

    public static function getResponseFormat(): array
    {
        return ['matches' => 'object'];
    }

    public static function validateResponse(array $response): bool
    {
        return isset($response['matches']) && is_array($response['matches']);
    }
}
