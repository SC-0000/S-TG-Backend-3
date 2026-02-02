<?php

namespace App\Http\Requests\Api\Attendance;

use App\Http\Requests\Api\ApiRequest;

class AttendanceStoreRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'child_id' => ['required', 'exists:children,id'],
            'status' => ['required', 'in:present,absent,late,excused'],
            'notes' => ['nullable', 'string', 'max:500'],
            'date' => ['nullable', 'date'],
        ];
    }
}
