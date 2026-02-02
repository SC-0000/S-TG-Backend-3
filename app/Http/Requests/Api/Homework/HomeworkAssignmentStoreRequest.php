<?php

namespace App\Http\Requests\Api\Homework;

use App\Http\Requests\Api\ApiRequest;

class HomeworkAssignmentStoreRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'subject' => ['required', 'string', 'max:255'],
            'due_date' => ['required', 'date'],
            'attachments' => ['sometimes', 'array'],
            'attachments.*' => ['file', 'max:10240'],
        ];
    }
}
