<?php

namespace App\Http\Requests\Api\LessonPlayer;

use Illuminate\Foundation\Http\FormRequest;

class WhiteboardLoadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'block_id' => 'required|string',
        ];
    }
}
