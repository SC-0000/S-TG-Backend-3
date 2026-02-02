<?php

namespace App\Services\QuestionTypes;

class ComprehensionQuestionType implements QuestionTypeInterface
{
    public static function validate(array $questionData): bool
    {
        return !empty($questionData['passage']['content']) && 
               !empty($questionData['sub_questions']) && 
               is_array($questionData['sub_questions']);
    }

    public static function grade(array $questionData, array $answerSchema, array $response): array
    {
        $totalScore = 0;
        $maxScore = 0;
        $feedback = [];
        $subQuestionResults = [];

        foreach ($questionData['sub_questions'] as $index => $subQuestion) {
            $subQuestionType = $subQuestion['type'] ?? 'short_answer';
            $subResponse = $response['sub_answers'][$index] ?? [];
            
            // Convert sub-response to the format expected by the specific question type handler
            if (!is_array($subResponse)) {
                // Handle different question type formats
                if ($subQuestionType === 'mcq') {
                    // Convert numerical index to option ID format
                    if (is_numeric($subResponse)) {
                        $optionIndex = (int)$subResponse;
                        $optionId = chr(97 + $optionIndex); // 0=a, 1=b, 2=c, 3=d
                        $subResponse = ['selected_options' => [$optionId]];
                    } else {
                        $subResponse = ['selected_options' => [$subResponse]];
                    }
                } else {
                    // Default format for other question types
                    $subResponse = ['response' => $subResponse];
                }
            }
            
            // Get the handler for this sub-question type
            $handlerClass = \App\Services\QuestionTypeRegistry::getHandler($subQuestionType);
            
            if ($handlerClass) {
                $subResult = $handlerClass::grade(
                    $subQuestion,
                    $subQuestion['answer_schema'] ?? [],
                    $subResponse
                );
                
                $subQuestionResults[] = $subResult;
                $totalScore += $subResult['score'] ?? 0;
                $maxScore += $subResult['max_score'] ?? 0;
                
                if (!empty($subResult['feedback'])) {
                    $feedback[] = "Q" . ($index + 1) . ": " . $subResult['feedback'];
                }
            }
        }

        return [
            'score' => $totalScore,
            'max_score' => $maxScore,
            'feedback' => implode(' | ', $feedback),
            'is_correct' => $maxScore > 0 ? ($totalScore / $maxScore) >= 0.6 : false,
            'sub_question_results' => $subQuestionResults,
            'percentage' => $maxScore > 0 ? round(($totalScore / $maxScore) * 100, 2) : 0
        ];
    }

    public static function renderForStudent(array $questionData): array
    {
        $subQuestions = [];
        
        foreach ($questionData['sub_questions'] as $index => $subQuestion) {
            $subQuestionType = $subQuestion['type'] ?? 'short_answer';
            $handlerClass = \App\Services\QuestionTypeRegistry::getHandler($subQuestionType);
            
            if ($handlerClass) {
                $renderedSubQuestion = $handlerClass::renderForStudent($subQuestion);
                $renderedSubQuestion['question_number'] = $index + 1;
                $renderedSubQuestion['marks'] = $subQuestion['marks'] ?? 1;
                $subQuestions[] = $renderedSubQuestion;
            }
        }

        return [
            'passage' => [
                'title' => $questionData['passage']['title'] ?? '',
                'content' => $questionData['passage']['content'] ?? '',
                'source' => $questionData['passage']['source'] ?? ''
            ],
            'instructions' => $questionData['instructions'] ?? 'Read the passage carefully and answer the questions that follow.',
            'sub_questions' => $subQuestions,
            'total_marks' => array_sum(array_column($questionData['sub_questions'], 'marks'))
        ];
    }

    public static function renderForAdmin(array $questionData, array $answerSchema): array
    {
        $subQuestions = [];
        
        foreach ($questionData['sub_questions'] as $index => $subQuestion) {
            $subQuestionType = $subQuestion['type'] ?? 'short_answer';
            $handlerClass = \App\Services\QuestionTypeRegistry::getHandler($subQuestionType);
            
            if ($handlerClass) {
                $renderedSubQuestion = $handlerClass::renderForAdmin(
                    $subQuestion, 
                    $subQuestion['answer_schema'] ?? []
                );
                $renderedSubQuestion['question_number'] = $index + 1;
                $renderedSubQuestion['marks'] = $subQuestion['marks'] ?? 1;
                $subQuestions[] = $renderedSubQuestion;
            }
        }

        return [
            'passage' => [
                'title' => $questionData['passage']['title'] ?? '',
                'content' => $questionData['passage']['content'] ?? '',
                'source' => $questionData['passage']['source'] ?? ''
            ],
            'instructions' => $questionData['instructions'] ?? '',
            'sub_questions' => $subQuestions,
            'total_marks' => array_sum(array_column($questionData['sub_questions'], 'marks')),
            'answer_schema' => $answerSchema
        ];
    }

    public static function getDefaultData(): array
    {
        return [
            'passage' => [
                'title' => '',
                'content' => '',
                'source' => ''
            ],
            'instructions' => 'Read the passage carefully and answer the questions that follow.',
            'sub_questions' => [
                [
                    'type' => 'mcq',
                    'question_text' => '',
                    'options' => [
                        ['id' => 'a', 'text' => '', 'is_correct' => false],
                        ['id' => 'b', 'text' => '', 'is_correct' => false],
                        ['id' => 'c', 'text' => '', 'is_correct' => false],
                        ['id' => 'd', 'text' => '', 'is_correct' => false]
                    ],
                    'marks' => 2,
                    'answer_schema' => [
                        'type' => 'single_choice',
                        'correct_answers' => []
                    ]
                ]
            ]
        ];
    }

    public static function getDefaultAnswerSchema(): array
    {
        return [
            'type' => 'comprehension',
            'grading_type' => 'automatic',
            'sub_question_schemas' => []
        ];
    }

    public static function getResponseFormat(): array
    {
        return [
            'sub_answers' => [
                'type' => 'array',
                'required' => true,
                'description' => 'Array of responses to each sub-question'
            ]
        ];
    }

    public static function validateResponse(array $response): bool
    {
        return isset($response['sub_answers']) && 
               is_array($response['sub_answers']) && 
               !empty($response['sub_answers']);
    }
}
