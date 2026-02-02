<?php

namespace App\Http\Requests\Api\YearGroups;

use Illuminate\Foundation\Http\FormRequest;

class YearGroupBulkUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'child_ids' => 'required|array|min:1',
            'child_ids.*' => 'required|integer|exists:children,id',
            'year_group' => 'required|string|max:50',
        ];
    }
}
