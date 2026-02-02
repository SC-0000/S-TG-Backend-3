<?php

namespace App\Services\QuestionTypes;

interface QuestionTypeInterface
{
    /**
     * Validate the question data structure
     */
    public static function validate(array $questionData): bool;

    /**
     * Grade a student's response
     */
    public static function grade(array $questionData, array $answerSchema, array $response): array;

    /**
     * Render question for student view
     */
    public static function renderForStudent(array $questionData): array;

    /**
     * Render question for admin/teacher view
     */
    public static function renderForAdmin(array $questionData, array $answerSchema): array;

    /**
     * Get default question data structure
     */
    public static function getDefaultData(): array;

    /**
     * Get default answer schema structure
     */
    public static function getDefaultAnswerSchema(): array;

    /**
     * Get the expected response format for this question type
     */
    public static function getResponseFormat(): array;

    /**
     * Validate a student's response format
     */
    public static function validateResponse(array $response): bool;
}
