<?php

namespace App\Http\Controllers\Api;

use App\Models\Course;
use App\Models\LessonProgress;
use App\Models\LiveLessonSession;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalCourseController extends ApiController
{
    public function browse(Request $request): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');

        $courses = Course::with(['modules.lessons.liveSessions', 'assessments'])
            ->visibleToOrg($orgId)
            ->whereHas('service')
            ->get()
            ->map(function ($course) use ($orgId) {
                $service = Service::where('course_id', $course->id)
                    ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
                    ->first();

                $totalLessons = $course->modules->sum(fn ($module) => $module->lessons->count());
                $totalLiveSessions = $course->modules->sum(function ($module) {
                    return $module->lessons->sum(fn ($lesson) => $lesson->liveSessions->count());
                });
                $totalAssessments = $course->modules->sum(fn ($module) => $module->assessments()->count())
                    + $course->assessments()->count();

                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'description' => $course->description,
                    'thumbnail' => $course->thumbnail,
                    'category' => $course->category,
                    'level' => $course->level,
                    'estimated_duration_minutes' => $course->estimated_duration_minutes,
                    'is_featured' => (bool) $course->is_featured,
                    'content_stats' => [
                        'lessons' => $totalLessons,
                        'live_sessions' => $totalLiveSessions,
                        'assessments' => $totalAssessments,
                    ],
                    'service' => $service ? [
                        'id' => $service->id,
                        'price' => $service->price,
                        'availability' => $service->availability,
                    ] : null,
                ];
            })
            ->filter(fn ($course) => $course['service'] !== null)
            ->values();

        return $this->success([
            'courses' => $courses,
        ]);
    }

    public function myCourses(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $children = $user->children()->get();
        $enrolledCourses = [];

        foreach ($children as $child) {
            $courses = $child->enrolledCourses()
                ->with(['modules.lessons'])
                ->get();

            $lessonIds = $courses->flatMap(fn ($course) => $course->modules->flatMap(fn ($module) => $module->lessons->pluck('id')))
                ->unique()
                ->values();

            $completedLessonIds = LessonProgress::where('child_id', $child->id)
                ->whereIn('lesson_id', $lessonIds)
                ->where('status', 'completed')
                ->pluck('lesson_id')
                ->all();

            $completedLookup = array_flip($completedLessonIds);

            foreach ($courses as $course) {
                $courseLessonIds = $course->modules->flatMap(fn ($module) => $module->lessons->pluck('id'))->values();
                $totalLessons = $courseLessonIds->count();
                $completedLessons = $courseLessonIds->filter(fn ($id) => isset($completedLookup[$id]))->count();

                $progressPercentage = $totalLessons > 0
                    ? round(($completedLessons / $totalLessons) * 100)
                    : 0;

                $enrolledCourses[] = [
                    'id' => $course->id,
                    'title' => $course->title,
                    'description' => $course->description,
                    'thumbnail' => $course->thumbnail,
                    'progress' => $progressPercentage,
                    'completed_lessons' => $completedLessons,
                    'total_lessons' => $totalLessons,
                    'child' => [
                        'id' => $child->id,
                        'name' => $child->child_name,
                    ],
                ];
            }
        }

        return $this->success([
            'enrolled_courses' => $enrolledCourses,
            'children' => $children->map(fn ($child) => [
                'id' => $child->id,
                'name' => $child->child_name,
            ]),
        ]);
    }

    public function show(Request $request, Course $course): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');
        if (! $course->is_global && $orgId && (int) $course->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $course->load([
            'modules.lessons',
            'modules.lessons.liveSessions',
            'modules.assessments',
            'assessments',
        ]);

        $service = Service::where('course_id', $course->id)
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->first();

        $hasAccess = false;
        $user = $request->user();
        if ($user) {
            $children = $user->children;
            foreach ($children as $child) {
                if ($child->hasAccessToCourse($course->id)) {
                    $hasAccess = true;
                    break;
                }
            }
        }

        $courseLessonIds = $course->modules()
            ->with('lessons')
            ->get()
            ->pluck('lessons')
            ->flatten()
            ->pluck('id')
            ->unique()
            ->values();

        $liveSessions = LiveLessonSession::with('lesson:id,title,description')
            ->select('id', 'uid', 'lesson_id', 'course_id', 'scheduled_start_time', 'status', 'session_code')
            ->whereIn('lesson_id', $courseLessonIds)
            ->where('course_id', $course->id)
            ->orderBy('scheduled_start_time', 'desc')
            ->get()
            ->map(function ($session) {
                return [
                    'id' => $session->id,
                    'uid' => $session->uid,
                    'lesson_id' => $session->lesson_id,
                    'course_id' => $session->course_id,
                    'scheduled_start_time' => $session->scheduled_start_time?->format('Y-m-d H:i:s'),
                    'status' => $session->status,
                    'session_code' => $session->session_code,
                ];
            });

        $payload = [
            'id' => $course->id,
            'uid' => $course->uid,
            'title' => $course->title,
            'description' => $course->description,
            'thumbnail' => $course->thumbnail,
            'category' => $course->category,
            'level' => $course->level,
            'estimated_duration_minutes' => $course->estimated_duration_minutes,
            'is_featured' => (bool) $course->is_featured,
            'modules' => $course->modules->map(function ($module) use ($course) {
                return [
                    'id' => $module->id,
                    'title' => $module->title,
                    'description' => $module->description,
                    'lessons' => $module->lessons->map(function ($lesson) use ($course) {
                        return [
                            'id' => $lesson->id,
                            'uid' => $lesson->uid,
                            'title' => $lesson->title,
                            'description' => $lesson->description,
                            'slides_count' => $lesson->slides()->count(),
                            'progress' => null,
                            'live_sessions' => $lesson->liveSessions
                                ->where('course_id', $course->id)
                                ->map(function ($session) {
                                    return [
                                        'id' => $session->id,
                                        'uid' => $session->uid,
                                        'session_code' => $session->session_code,
                                        'status' => $session->status,
                                        'scheduled_start_time' => $session->scheduled_start_time?->format('Y-m-d H:i:s'),
                                    ];
                                }),
                        ];
                    }),
                    'assessments' => $module->assessments->map(function ($assessment) {
                        return [
                            'id' => $assessment->id,
                            'uid' => $assessment->uid,
                            'title' => $assessment->title,
                            'description' => $assessment->description,
                        ];
                    }),
                ];
            }),
            'assessments' => $course->assessments->map(function ($assessment) {
                return [
                    'id' => $assessment->id,
                    'uid' => $assessment->uid,
                    'title' => $assessment->title,
                    'description' => $assessment->description,
                ];
            }),
            'service' => $service ? [
                'id' => $service->id,
                'price' => $service->price,
                'availability' => $service->availability,
            ] : null,
            'has_access' => $hasAccess,
        ];

        return $this->success([
            'course' => $payload,
            'live_sessions' => $liveSessions,
        ]);
    }
}
