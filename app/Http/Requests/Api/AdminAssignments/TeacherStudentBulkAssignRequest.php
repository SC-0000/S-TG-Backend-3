<?php

namespace App\Http\Requests\Api\AdminAssignments;

use Illuminate\Foundation\Http\FormRequest;

class TeacherStudentBulkAssignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'teacher_ids' => 'required|array|min:1',
            'teacher_ids.*' => 'exists:users,id',
            'student_ids' => 'required|array|min:1',
            'student_ids.*' => 'exists:children,id',
            'notes' => 'nullable|string',
        ];
    }
}
