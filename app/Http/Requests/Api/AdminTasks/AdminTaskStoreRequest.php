<?php

namespace App\Http\Requests\Api\AdminTasks;

use Illuminate\Foundation\Http\FormRequest;

class AdminTaskStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'task_type' => 'required|string|max:255',
            'assigned_to' => 'required|integer|exists:users,id',
            'status' => 'required|in:Pending,In Progress,Completed',
            'related_entity' => 'nullable|string|max:255',
            'priority' => 'required|in:Low,Medium,High,Critical',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'metadata' => 'nullable|array',
        ];
    }
}
