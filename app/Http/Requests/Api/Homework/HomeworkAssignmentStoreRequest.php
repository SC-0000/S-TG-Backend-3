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
            'journey_category_id' => ['nullable', 'integer', 'exists:journey_categories,id'],
            'assigned_by' => ['nullable', 'integer', 'exists:users,id'],
            'assigned_by_role' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:30'],
            'visibility' => ['nullable', 'string', 'max:30'],
            'available_from' => ['nullable', 'date'],
            'grading_mode' => ['nullable', 'string', 'max:30'],
            'settings' => ['nullable', 'array'],
            'items' => ['nullable', 'array'],
            'items.*.type' => ['required_with:items', 'string', 'max:50'],
            'items.*.ref_id' => ['nullable', 'integer'],
            'items.*.payload' => ['nullable', 'array'],
            'items.*.sort_order' => ['nullable', 'integer'],
            'attachments' => ['sometimes', 'array'],
            'attachments.*' => ['file', 'max:10240'],
            'target_child_ids' => ['nullable', 'array'],
            'target_child_ids.*' => ['integer', 'exists:children,id'],
        ];
    }
}
