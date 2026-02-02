<?php

namespace App\Http\Requests\Api\LessonPlayer;

use Illuminate\Foundation\Http\FormRequest;

class LessonUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'block_id' => 'required|string',
            'file' => 'required|file|max:51200',
            'file_type' => 'required|in:image,pdf,audio,video,document',
        ];
    }
}
