<?php

namespace App\Http\Requests\Api\Homework;

use App\Http\Requests\Api\ApiRequest;

class HomeworkAssignmentUpdateRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'required', 'string'],
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'due_date' => ['sometimes', 'required', 'date'],
            'journey_category_id' => ['sometimes', 'nullable', 'integer', 'exists:journey_categories,id'],
            'assigned_by' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'assigned_by_role' => ['sometimes', 'nullable', 'string', 'max:50'],
            'status' => ['sometimes', 'nullable', 'string', 'max:30'],
            'visibility' => ['sometimes', 'nullable', 'string', 'max:30'],
            'available_from' => ['sometimes', 'nullable', 'date'],
            'grading_mode' => ['sometimes', 'nullable', 'string', 'max:30'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'items' => ['sometimes', 'array'],
            'items.*.type' => ['required_with:items', 'string', 'max:50'],
            'items.*.ref_id' => ['nullable', 'integer'],
            'items.*.payload' => ['nullable', 'array'],
            'items.*.sort_order' => ['nullable', 'integer'],
            'attachments' => ['sometimes', 'array'],
            'attachments.*' => ['file', 'max:10240'],
            'target_child_ids' => ['sometimes', 'nullable', 'array'],
            'target_child_ids.*' => ['integer', 'exists:children,id'],
        ];
    }
}
