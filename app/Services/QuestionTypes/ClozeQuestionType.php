<?php

namespace App\Services\QuestionTypes;

class ClozeQuestionType implements QuestionTypeInterface
{
    public static function validate(array $questionData): bool
    {
        $required = ['passage', 'blanks'];
        
        foreach ($required as $field) {
            if (!isset($questionData[$field])) {
                return false;
            }
        }

        // Validate blanks structure
        if (!is_array($questionData['blanks']) || empty($questionData['blanks'])) {
            return false;
        }

        foreach ($questionData['blanks'] as $blank) {
            if (!isset($blank['id']) || !isset($blank['correct_answers'])) {
                return false;
            }
        }

        return true;
    }

    public static function grade(array $questionData, array $answerSchema, array $response): array
    {
        $blanks = $questionData['blanks'];
        $studentAnswers = $response['answers'] ?? [];
        $totalBlanks = count($blanks);
        $correctBlanks = 0;
        $feedback = [];
        $details = [];

        foreach ($blanks as $blank) {
            $blankId = $blank['id'];
            $correctAnswers = $blank['correct_answers'];
            $studentAnswer = trim($studentAnswers[$blankId] ?? '');
            $caseSensitive = $blank['case_sensitive'] ?? false;
            $acceptPartial = $blank['accept_partial'] ?? false;

            $isCorrect = false;
            $partialCredit = 0;

            if (!empty($studentAnswer)) {
                foreach ($correctAnswers as $correctAnswer) {
                    $compareStudent = $caseSensitive ? $studentAnswer : strtolower($studentAnswer);
                    $compareCorrect = $caseSensitive ? $correctAnswer : strtolower($correctAnswer);

                    if ($compareStudent === $compareCorrect) {
                        $isCorrect = true;
                        $partialCredit = 1;
                        break;
                    } elseif ($acceptPartial) {
                        // Check for partial matches
                        $similarity = similar_text($compareStudent, $compareCorrect, $percent);
                        if ($percent >= 70) {
                            $partialCredit = 0.5;
                        } elseif ($percent >= 50) {
                            $partialCredit = 0.3;
                        }
                    }
                }
            }

            if ($isCorrect) {
                $correctBlanks++;
                $feedback[] = "Blank {$blankId}: Correct";
            } elseif ($partialCredit > 0) {
                $feedback[] = "Blank {$blankId}: Partially correct";
            } else {
                $feedback[] = "Blank {$blankId}: Incorrect";
            }

            $details[$blankId] = [
                'student_answer' => $studentAnswer,
                'correct_answers' => $correctAnswers,
                'is_correct' => $isCorrect,
                'partial_credit' => $partialCredit,
            ];
        }

        $totalScore = array_sum(array_column($details, 'partial_credit'));
        $maxScore = $totalBlanks;
        $percentage = $maxScore > 0 ? ($totalScore / $maxScore) * 100 : 0;

        return [
            'score' => $totalScore,
            'max_score' => $maxScore,
            'percentage' => round($percentage, 2),
            'is_correct' => $correctBlanks === $totalBlanks,
            'feedback' => implode('; ', $feedback),
            'details' => $details,
            'summary' => [
                'correct_blanks' => $correctBlanks,
                'total_blanks' => $totalBlanks,
                'partial_credit_blanks' => count(array_filter($details, function($d) { return $d['partial_credit'] > 0 && !$d['is_correct']; }))
            ]
        ];
    }

    public static function renderForStudent(array $questionData): array
    {
        // Remove correct answers from blanks for student view
        $blanks = array_map(function($blank) {
            return [
                'id' => $blank['id'],
                'placeholder' => $blank['placeholder'] ?? '',
                'max_length' => $blank['max_length'] ?? 50,
            ];
        }, $questionData['blanks']);

        return [
            'passage' => $questionData['passage'],
            'blanks' => $blanks,
            'instructions' => $questionData['instructions'] ?? 'Fill in the blanks with appropriate words.',
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
            'passage' => '',
            'blanks' => [],
            'instructions' => 'Fill in the blanks with appropriate words.',
        ];
    }

    public static function getDefaultAnswerSchema(): array
    {
        return [
            'partial_credit_enabled' => true,
            'partial_credit_threshold' => 50, // percentage similarity for partial credit
            'case_sensitive_default' => false,
            'allow_synonyms' => false,
            'synonym_list' => [],
        ];
    }

    public static function getResponseFormat(): array
    {
        return [
            'answers' => 'object', // Object with blank_id => answer pairs
        ];
    }

    public static function validateResponse(array $response): bool
    {
        return isset($response['answers']) && is_array($response['answers']);
    }

    /**
     * Helper method to parse passage and extract blank placeholders
     */
    public static function extractBlanksFromPassage(string $passage): array
    {
        preg_match_all('/\{([^}]+)\}/', $passage, $matches);
        
        $blanks = [];
        foreach ($matches[1] as $blankId) {
            $blanks[] = [
                'id' => $blankId,
                'correct_answers' => [''],
                'case_sensitive' => false,
                'accept_partial' => false,
                'placeholder' => '',
                'max_length' => 50,
            ];
        }
        
        return $blanks;
    }
}
