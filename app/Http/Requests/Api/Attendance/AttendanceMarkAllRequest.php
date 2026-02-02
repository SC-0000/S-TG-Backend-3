<?php

namespace App\Http\Requests\Api\Attendance;

use App\Http\Requests\Api\ApiRequest;

class AttendanceMarkAllRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:present,absent,late,excused'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
