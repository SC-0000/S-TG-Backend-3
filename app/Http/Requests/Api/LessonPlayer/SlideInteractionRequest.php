<?php

namespace App\Http\Requests\Api\LessonPlayer;

use Illuminate\Foundation\Http\FormRequest;

class SlideInteractionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'interaction_type' => 'required|string|in:help_request,flag_difficult,hint_used,tts_used',
            'data' => 'nullable|array',
        ];
    }
}
