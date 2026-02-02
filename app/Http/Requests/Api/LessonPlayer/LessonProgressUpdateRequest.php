<?php

namespace App\Http\Requests\Api\LessonPlayer;

use Illuminate\Foundation\Http\FormRequest;

class LessonProgressUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'time_spent_seconds' => 'required|integer|min:0',
            'slide_id' => 'nullable|integer|exists:lesson_slides,id',
            'last_slide_id' => 'nullable|integer|exists:lesson_slides,id',
            'slides_viewed' => 'nullable|array',
            'slides_viewed.*' => 'integer|exists:lesson_slides,id',
        ];
    }
}
