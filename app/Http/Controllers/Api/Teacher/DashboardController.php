<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\LessonUploadResource;
use App\Http\Resources\LiveLessonSessionResource;
use App\Http\Resources\TeacherStudentResource;
use App\Models\Course;
use App\Models\LessonUpload;
use App\Models\LiveLessonSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $teacher = $request->user();
        if (!$teacher) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $teacherId = $teacher->id;

        $upcomingSessions = LiveLessonSession::forTeacher($teacherId)
            ->scheduled()
            ->with(['lesson', 'course', 'teacher'])
            ->orderBy('scheduled_start_time', 'asc')
            ->limit(5)
            ->get();

        $activeSessions = LiveLessonSession::forTeacher($teacherId)
            ->active()
            ->with(['lesson', 'course', 'teacher', 'participants'])
            ->get();

        $recentSessions = LiveLessonSession::forTeacher($teacherId)
            ->ended()
            ->with(['lesson', 'course', 'teacher'])
            ->orderBy('end_time', 'desc')
            ->limit(5)
            ->get();

        $courses = Course::where('created_by', $teacherId)
            ->with('modules')
            ->get()
            ->map(function ($course) {
                return [
                    'id' => $course->id,
                    'uid' => $course->uid,
                    'title' => $course->title,
                    'description' => $course->description,
                    'thumbnail' => $course->thumbnail,
                    'status' => $course->status,
                    'modules_count' => $course->modules->count(),
                    'created_by' => $course->created_by,
                    'created_by_me' => true,
                ];
            })
            ->values();

        $pendingUploads = LessonUpload::whereHas('lesson.liveSessions', function ($q) use ($teacherId) {
            $q->where('teacher_id', $teacherId);
        })
            ->where('status', 'pending')
            ->with(['child.user', 'lesson', 'slide'])
            ->limit(10)
            ->get();

        $students = $teacher->assignedStudents()
            ->with(['user', 'attendances', 'assessmentSubmissions', 'lessonProgress'])
            ->get();

        $stats = [
            'total_sessions' => LiveLessonSession::forTeacher($teacherId)->count(),
            'active_sessions' => $activeSessions->count(),
            'upcoming_sessions' => $upcomingSessions->count(),
            'total_students' => $teacher->assignedStudents()->count(),
            'pending_uploads' => $pendingUploads->count(),
            'total_courses' => $courses->count(),
        ];

        return $this->success([
            'stats' => $stats,
            'upcoming_sessions' => LiveLessonSessionResource::collection($upcomingSessions)->resolve(),
            'active_sessions' => LiveLessonSessionResource::collection($activeSessions)->resolve(),
            'recent_sessions' => LiveLessonSessionResource::collection($recentSessions)->resolve(),
            'courses' => $courses,
            'pending_uploads' => LessonUploadResource::collection($pendingUploads)->resolve(),
            'students' => TeacherStudentResource::collection($students)->resolve(),
        ]);
    }
}
