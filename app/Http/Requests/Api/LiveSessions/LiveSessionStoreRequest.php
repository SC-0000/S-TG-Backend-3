<?php

namespace App\Http\Requests\Api\LiveSessions;

use App\Http\Requests\ApiRequest;

class LiveSessionStoreRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'lesson_id' => 'required|exists:new_lessons,id',
            'course_id' => 'nullable|exists:courses,id',
            'year_group' => 'nullable|string|max:50',
            'teacher_id' => 'nullable|exists:users,id',
            'organization_id' => 'nullable|exists:organizations,id',
            'scheduled_start_time' => 'required|date|after:now',
            'audio_enabled' => 'boolean',
            'video_enabled' => 'boolean',
            'allow_student_questions' => 'boolean',
            'whiteboard_enabled' => 'boolean',
            'record_session' => 'boolean',
            'start_now' => 'boolean',
        ];
    }
}
