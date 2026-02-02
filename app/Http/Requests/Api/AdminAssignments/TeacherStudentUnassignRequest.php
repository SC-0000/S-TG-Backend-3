<?php

namespace App\Http\Requests\Api\AdminAssignments;

use Illuminate\Foundation\Http\FormRequest;

class TeacherStudentUnassignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'teacher_id' => 'required|exists:users,id',
            'student_id' => 'required|exists:children,id',
        ];
    }
}
