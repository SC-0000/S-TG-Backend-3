<?php

namespace App\Http\Requests\Api\AdminTasks;

use Illuminate\Foundation\Http\FormRequest;

class AdminTaskUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'task_type' => 'sometimes|required|string|max:255',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'status' => 'sometimes|required|in:Pending,In Progress,Completed',
            'related_entity' => 'nullable|string|max:255',
            'priority' => 'sometimes|required|in:Low,Medium,High,Critical',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'metadata' => 'nullable|array',
            'due_at' => 'sometimes|nullable|date',
            'category' => 'sometimes|nullable|string|max:50',
            'action_url' => 'sometimes|nullable|string|max:500',
        ];
    }
}
