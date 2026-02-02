<?php

namespace App\Services\QuestionTypes;

class McqQuestionType implements QuestionTypeInterface
{
    public static function validate(array $questionData): bool
    {
        $required = ['question_text', 'options'];
        
        foreach ($required as $field) {
            if (!isset($questionData[$field])) {
                return false;
            }
        }

        // Validate options structure
        if (!is_array($questionData['options']) || empty($questionData['options'])) {
            return false;
        }

        // Check if each option has required fields
        foreach ($questionData['options'] as $option) {
            if (!isset($option['id']) || !isset($option['text']) || !isset($option['is_correct'])) {
                return false;
            }
        }

        return true;
    }

    public static function grade(array $questionData, array $answerSchema, array $response): array
    {
        $correctOptions = [];
        $totalOptions = count($questionData['options']);
        
        // Ensure all options have 'id' fields - generate if missing (for comprehension sub-questions)
        $processedOptions = [];
        foreach ($questionData['options'] as $index => $option) {
            $processedOption = $option;
            if (!isset($option['id'])) {
                $processedOption['id'] = chr(97 + $index); // Generate 'a', 'b', 'c', 'd'
            }
            $processedOptions[] = $processedOption;
        }
        
        // Get correct options - handle both string and boolean values
        foreach ($processedOptions as $option) {
            $isCorrect = $option['is_correct'];
            // Handle string values "0", "1" or boolean true/false
            if ($isCorrect === true || $isCorrect === 1 || $isCorrect === '1' || $isCorrect === 'true') {
                $correctOptions[] = $option['id'];
            }
        }

        $selectedOptions = $response['selected_options'] ?? [];
        $isMultiple = ($questionData['allow_multiple'] ?? false) || count($correctOptions) > 1;

        if ($isMultiple) {
            // Multiple choice scoring
            $correctlySelected = array_intersect($selectedOptions, $correctOptions);
            $incorrectlySelected = array_diff($selectedOptions, $correctOptions);
            $missedCorrect = array_diff($correctOptions, $selectedOptions);

            $correctCount = count($correctlySelected);
            $incorrectCount = count($incorrectlySelected);
            $totalCorrect = count($correctOptions);

            // Scoring: +1 for each correct, -0.5 for each incorrect (but not below 0)
            $score = max(0, $correctCount - ($incorrectCount * 0.5));
            $maxScore = $totalCorrect;
            $percentage = $maxScore > 0 ? ($score / $maxScore) * 100 : 0;

            $feedback = [];
            if ($correctCount === $totalCorrect && $incorrectCount === 0) {
                $feedback[] = "Perfect! All correct options selected.";
            } else {
                if ($correctCount > 0) {
                    $feedback[] = "Correctly selected: " . implode(', ', $correctlySelected);
                }
                if ($incorrectCount > 0) {
                    $feedback[] = "Incorrectly selected: " . implode(', ', $incorrectlySelected);
                }
                if (count($missedCorrect) > 0) {
                    $feedback[] = "Missed correct options: " . implode(', ', $missedCorrect);
                }
            }

            return [
                'score' => $score,
                'max_score' => $maxScore,
                'percentage' => round($percentage, 2),
                'is_correct' => $score === $maxScore,
                'feedback' => implode(' ', $feedback),
                'details' => [
                    'correct_selected' => $correctlySelected,
                    'incorrect_selected' => $incorrectlySelected,
                    'missed_correct' => $missedCorrect,
                    'total_correct' => $totalCorrect
                ]
            ];
        } else {
            // Single choice scoring
            $selectedOption = $selectedOptions[0] ?? null;
            $isCorrect = in_array($selectedOption, $correctOptions);
            
            // Get correct answer text
            $correctAnswerId = $correctOptions[0] ?? null;
            $correctAnswerText = null;
            if ($correctAnswerId) {
                $correctOption = collect($processedOptions)->firstWhere('id', $correctAnswerId);
                $correctAnswerText = $correctOption['text'] ?? null;
            }
            
            // Get selected answer text
            $selectedAnswerText = null;
            if ($selectedOption) {
                $selectedOptionData = collect($processedOptions)->firstWhere('id', $selectedOption);
                $selectedAnswerText = $selectedOptionData['text'] ?? null;
            }
            
            return [
                'score' => $isCorrect ? 1 : 0,
                'max_score' => 1,
                'percentage' => $isCorrect ? 100 : 0,
                'is_correct' => $isCorrect,
                'feedback' => $isCorrect ? 'Correct answer!' : 'Incorrect answer.',
                'details' => [
                    'selected' => $selectedOption,
                    'selected_text' => $selectedAnswerText,
                    'correct_answer' => $correctAnswerId,
                    'correct_answer_text' => $correctAnswerText
                ]
            ];
        }
    }

    public static function renderForStudent(array $questionData): array
    {
        // Remove correct answer information for student view
        $options = array_map(function($option, $index) {
            return [
                'id' => $option['id'] ?? chr(97 + $index), // Generate 'a', 'b', 'c', 'd' if missing
                'text' => $option['text'] ?? '',
                'image' => $option['image'] ?? null,
            ];
        }, $questionData['options'], array_keys($questionData['options']));

        return [
            'question_text' => $questionData['question_text'],
            'question_image' => $questionData['question_image'] ?? null,
            'options' => $options,
            'allow_multiple' => $questionData['allow_multiple'] ?? false,
            'shuffle_options' => $questionData['shuffle_options'] ?? false,
        ];
    }

    public static function renderForAdmin(array $questionData, array $answerSchema): array
    {
        return [
            'question_text' => $questionData['question_text'],
            'question_image' => $questionData['question_image'] ?? null,
            'options' => $questionData['options'],
            'allow_multiple' => $questionData['allow_multiple'] ?? false,
            'shuffle_options' => $questionData['shuffle_options'] ?? false,
            'answer_schema' => $answerSchema,
        ];
    }

    public static function getDefaultData(): array
    {
        return [
            'question_text' => '',
            'question_image' => null,
            'options' => [
                ['id' => 'a', 'text' => '', 'is_correct' => false, 'image' => null],
                ['id' => 'b', 'text' => '', 'is_correct' => false, 'image' => null],
                ['id' => 'c', 'text' => '', 'is_correct' => false, 'image' => null],
                ['id' => 'd', 'text' => '', 'is_correct' => false, 'image' => null],
            ],
            'allow_multiple' => false,
            'shuffle_options' => false,
        ];
    }

    public static function getDefaultAnswerSchema(): array
    {
        return [
            'scoring_method' => 'all_or_nothing', // or 'partial_credit'
            'negative_marking' => false,
            'negative_mark_value' => 0.25,
            'explanation' => '',
            'hints' => [],
        ];
    }

    public static function getResponseFormat(): array
    {
        return [
            'selected_options' => 'array', // Array of selected option IDs
        ];
    }

    public static function validateResponse(array $response): bool
    {
        return isset($response['selected_options']) && is_array($response['selected_options']);
    }
}
