<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AIGradingReviewRequest extends FormRequest
{
    public function authorize()
    {
        return true; // Authorization handled in controller
    }

    public function rules()
    {
        return [
            'child_id' => 'required|integer|exists:children,id',
            'message' => 'nullable|string|max:2000',
            
            // Context object validation
            'context' => 'nullable|array',
            'context.submission_id' => 'nullable|integer|exists:assessment_submissions,id',
            'context.assessment_title' => 'nullable|string|max:500',
            'context.child_id' => 'nullable|integer|exists:children,id',
            'context.wrong_answers' => 'nullable|array|max:100',
            'context.wrong_answers.*.question_id' => 'nullable|integer',
            'context.wrong_answers.*.question_text' => 'nullable|string|max:2000',
            'context.wrong_answers.*.question_type' => 'nullable|string|max:100',
            'context.wrong_answers.*.student_answer' => 'nullable|string|max:2000',
            'context.wrong_answers.*.marks_awarded' => 'nullable|numeric|min:0',
            'context.wrong_answers.*.total_marks' => 'nullable|numeric|min:0',
            'context.wrong_answers.*.is_correct' => 'nullable|boolean',
            'context.wrong_answers.*.detailed_feedback' => 'nullable|string|max:5000',
            'context.wrong_answers.*.question_data' => 'nullable|array', // ADDED: Accept question_data object
            'context.current_question' => 'nullable|array',
            'context.current_question.question_id' => 'nullable|integer',
            'context.current_question.question_text' => 'nullable|string|max:2000',
            'context.current_question.student_answer' => 'nullable|string|max:2000',
            'context.message' => 'nullable|string|max:2000',
        ];
    }

    public function messages()
    {
        return [
            'context.wrong_answers.max' => 'Cannot process more than 100 questions at once',
            'child_id.exists' => 'Invalid child ID',
            'context.submission_id.exists' => 'Submission not found',
        ];
    }
    
    /**
     * Sanitize context data to prevent injection
     */
    public function sanitizedContext(): array
    {
        $context = $this->validated()['context'] ?? [];
        
        // Remove any potentially dangerous keys
        $dangerousKeys = ['__proto__', 'constructor', 'prototype'];
        foreach ($dangerousKeys as $key) {
            unset($context[$key]);
        }
        
        // Recursively sanitize nested arrays
        array_walk_recursive($context, function(&$value) {
            if (is_string($value)) {
                $value = strip_tags($value); // Remove HTML tags
            }
        });
        
        return $context;
    }
}
