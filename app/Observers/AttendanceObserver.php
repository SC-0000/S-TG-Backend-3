<?php

namespace App\Observers;

use App\Models\Attendance;
use App\Services\Tasks\TaskResolutionService;

class AttendanceObserver
{
    /**
     * When attendance is created, resolve any mark_attendance tasks
     * linked to the lesson.
     */
    public function created(Attendance $attendance): void
    {
        if ($attendance->lesson_id) {
            TaskResolutionService::resolve(
                \App\Models\Lesson::class,
                $attendance->lesson_id,
                'attendance_marked'
            );
        }
    }
}
