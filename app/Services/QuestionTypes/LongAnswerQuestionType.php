<?php

namespace App\Services\QuestionTypes;

class LongAnswerQuestionType implements QuestionTypeInterface
{
    public static function validate(array $questionData): bool
    {
        return !empty($questionData['question_text']);
    }

    public static function grade(array $questionData, array $answerSchema, array $response): array
    {
        // Long answer questions typically require manual grading
        // Return basic structure for manual review
        return [
            'score' => null, // To be manually graded
            'max_score' => $answerSchema['max_marks'] ?? 1,
            'feedback' => 'This response requires manual grading.',
            'is_correct' => null,
            'needs_manual_grading' => true,
            'response_length' => strlen($response['answer'] ?? ''),
            'response_word_count' => str_word_count($response['answer'] ?? '')
        ];
    }

    public static function renderForStudent(array $questionData): array
    {
        return [
            'question_text' => $questionData['question_text'] ?? '',
            'instructions' => $questionData['instructions'] ?? 'Provide a detailed response in essay format.',
            'rubric_criteria' => $questionData['rubric_criteria'] ?? [],
            'expected_structure' => $questionData['expected_structure'] ?? [],
            'rich_text_enabled' => true
        ];
    }

    public static function renderForAdmin(array $questionData, array $answerSchema): array
    {
        return [
            'question_text' => $questionData['question_text'] ?? '',
            'instructions' => $questionData['instructions'] ?? '',
            'rubric_criteria' => $questionData['rubric_criteria'] ?? [],
            'expected_structure' => $questionData['expected_structure'] ?? [],
            'grading_notes' => $questionData['grading_notes'] ?? '',
            'sample_answer' => $questionData['sample_answer'] ?? '',
            'answer_schema' => $answerSchema
        ];
    }

    public static function getDefaultData(): array
    {
        return [
            'question_text' => '',
            'instructions' => 'Provide a detailed response in essay format. Structure your answer clearly and support your points with examples.',
            'rubric_criteria' => [
                ['criterion' => 'Content Knowledge', 'points' => 40, 'description' => 'Demonstrates understanding of key concepts'],
                ['criterion' => 'Organization', 'points' => 20, 'description' => 'Clear structure and logical flow'],
                ['criterion' => 'Evidence/Examples', 'points' => 20, 'description' => 'Uses relevant examples and evidence'],
                ['criterion' => 'Writing Quality', 'points' => 20, 'description' => 'Grammar, vocabulary, and clarity']
            ],
            'expected_structure' => ['Introduction', 'Body Paragraphs', 'Conclusion'],
            'grading_notes' => '',
            'sample_answer' => ''
        ];
    }

    public static function getDefaultAnswerSchema(): array
    {
        return [
            'type' => 'long_text',
            'grading_type' => 'manual',
            'max_marks' => 10,
            'rubric_based' => true,
            'allows_rich_text' => true
        ];
    }

    public static function getResponseFormat(): array
    {
        return [
            'answer' => [
                'type' => 'string',
                'required' => true,
                'description' => 'The student\'s essay response'
            ]
        ];
    }

    public static function validateResponse(array $response): bool
    {
        return isset($response['answer']) && 
               is_string($response['answer']) && 
               !empty(trim($response['answer']));
    }
}
