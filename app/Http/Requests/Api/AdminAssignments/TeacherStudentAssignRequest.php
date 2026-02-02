<?php

namespace App\Http\Requests\Api\AdminAssignments;

use Illuminate\Foundation\Http\FormRequest;

class TeacherStudentAssignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'teacher_id' => 'required|exists:users,id',
            'student_ids' => 'required|array|min:1',
            'student_ids.*' => 'exists:children,id',
            'notes' => 'nullable|string',
        ];
    }
}
