<?php

namespace App\Http\Requests\Api\LessonPlayer;

use Illuminate\Foundation\Http\FormRequest;

class LessonQuestionSubmitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'block_id' => 'required|string',
            'question_id' => 'required|exists:questions,id',
            'answer_data' => 'required',
            'time_spent_seconds' => 'required|integer|min:0',
            'hints_used' => 'nullable|array',
        ];
    }
}
