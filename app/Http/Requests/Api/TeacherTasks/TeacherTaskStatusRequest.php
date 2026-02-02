<?php

namespace App\Http\Requests\Api\TeacherTasks;

use Illuminate\Foundation\Http\FormRequest;

class TeacherTaskStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => 'required|in:Pending,In Progress,Completed',
        ];
    }
}
