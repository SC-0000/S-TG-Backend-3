<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\LiveLessonSession;
use App\Models\Course;
use App\Models\Child;
use App\Models\Assessment;
use App\Models\LessonUpload;
use App\Models\ContentLesson;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    /**
     * Display the teacher dashboard
     */
    public function index()
    {
        $teacher = Auth::user();
        $teacherId = $teacher->id;

        // Get upcoming live sessions
        $upcomingSessions = LiveLessonSession::forTeacher($teacherId)
            ->scheduled()
            ->with(['lesson', 'course'])
            ->orderBy('scheduled_start_time', 'asc')
            ->limit(5)
            ->get();

        // Get currently active sessions
        $activeSessions = LiveLessonSession::forTeacher($teacherId)
            ->active()
            ->with(['lesson', 'course', 'participants'])
            ->get();

        // Get recent sessions
        $recentSessions = LiveLessonSession::forTeacher($teacherId)
            ->ended()
            ->with(['lesson', 'course'])
            ->orderBy('end_time', 'desc')
            ->limit(5)
            ->get();

        // Get teacher's courses (only courses created by this teacher)
        $courses = Course::where('created_by', $teacherId)
            ->with('modules')
            ->get()
            ->map(function ($course) use ($teacherId) {
                return [
                    'id' => $course->id,
                    'uid' => $course->uid,
                    'title' => $course->title,
                    'description' => $course->description,
                    'thumbnail' => $course->thumbnail,
                    'status' => $course->status,
                    'modules_count' => $course->modules->count(),
                    'created_by' => $course->created_by,
                    'created_by_me' => true, // Always true since we're filtering by created_by
                ];
            });

        // Get pending lesson uploads to grade
        $pendingUploads = LessonUpload::whereHas('lesson.liveSessions', function ($q) use ($teacherId) {
            $q->where('teacher_id', $teacherId);
        })
            ->where('status', 'pending')
            ->with(['child.user', 'lesson'])
            ->limit(10)
            ->get();

        // Get students count using direct assignment relationship
        $studentsCount = $teacher->assignedStudents()->count();

        // Get students with performance data for Phase 2 dashboard components
        $students = $teacher->assignedStudents()
            ->with(['user', 'attendances', 'assessmentSubmissions', 'lessonProgress'])
            ->get()
            ->map(function($student) {
                return [
                    'id' => $student->id,
                    'name' => $student->full_name, // Uses accessor from Child model
                    'year_group' => $student->year_group,
                    'performance_matrix' => [
                        'Attendance' => [
                            'score' => $student->calculateAttendanceScore(),
                            'trend' => $student->calculateAttendanceTrend()
                        ],
                        'Assignments' => [
                            'score' => $student->calculateAssignmentScore(),
                            'trend' => $student->calculateAssignmentTrend()
                        ],
                        'Assessments' => [
                            'score' => $student->calculateAssessmentScore(),
                            'trend' => $student->calculateAssessmentTrend()
                        ],
                        'Participation' => [
                            'score' => $student->calculateParticipationScore(),
                            'trend' => $student->calculateParticipationTrend()
                        ]
                    ],
                    'performance_score' => $student->calculateOverallPerformance(),
                    'risk_factors' => $student->identifyRiskFactors(),
                    'achievements' => $student->getRecentAchievements(),
                    'improvement' => $student->calculateImprovement(),
                    'contact_email' => $student->user?->email
                ];
            });

        // Statistics
        $stats = [
            'total_sessions' => LiveLessonSession::forTeacher($teacherId)->count(),
            'active_sessions' => $activeSessions->count(),
            'upcoming_sessions' => $upcomingSessions->count(),
            'total_students' => $studentsCount,
            'pending_uploads' => $pendingUploads->count(),
            'total_courses' => $courses->count(),
        ];

        return Inertia::render('@admin/Teacher/Dashboard', [
            'stats' => $stats,
            'upcomingSessions' => $upcomingSessions,
            'activeSessions' => $activeSessions,
            'recentSessions' => $recentSessions,
            'courses' => $courses,
            'pendingUploads' => $pendingUploads,
            'students' => $students, // NEW: Student performance data for Phase 2 components
        ]);
    }

    /**
     * Get list of students taught by this teacher
     */
    public function myStudents()
    {
        $teacher = Auth::user();

        // Use direct assignment relationship with performance data
        $studentsPaginated = $teacher->assignedStudents()
            ->with([
                'user', // Parent user
                'attendances',
                'assessmentSubmissions',
                'lessonProgress',
                'assignedTeachers' => function ($q) {
                    $q->select('users.id', 'users.name');
                }
            ])
            ->withPivot(['assigned_at', 'assigned_by', 'notes'])
            ->paginate(20);

        // Transform to include performance metrics
        $studentsPaginated->getCollection()->transform(function($student) {
            return [
                'id' => $student->id,
                'child_name' => $student->child_name,
                'name' => $student->full_name,
                'date_of_birth' => $student->date_of_birth,
                'year_group' => $student->year_group,
                'user' => $student->user,
                'assigned_teachers' => $student->assignedTeachers,
                'pivot' => $student->pivot,
                
                // Performance metrics for Phase 2 components
                'performance_matrix' => [
                    'Attendance' => [
                        'score' => $student->calculateAttendanceScore(),
                        'trend' => $student->calculateAttendanceTrend()
                    ],
                    'Assignments' => [
                        'score' => $student->calculateAssignmentScore(),
                        'trend' => $student->calculateAssignmentTrend()
                    ],
                    'Assessments' => [
                        'score' => $student->calculateAssessmentScore(),
                        'trend' => $student->calculateAssessmentTrend()
                    ],
                    'Participation' => [
                        'score' => $student->calculateParticipationScore(),
                        'trend' => $student->calculateParticipationTrend()
                    ]
                ],
                'performance_score' => $student->calculateOverallPerformance(),
                'risk_factors' => $student->identifyRiskFactors(),
                'achievements' => $student->getRecentAchievements(),
                'improvement' => $student->calculateImprovement(),
                'contact_email' => $student->user?->email
            ];
        });

        return Inertia::render('@admin/Teacher/Students/Index', [
            'students' => $studentsPaginated,
        ]);
    }

    /**
     * Get detailed view of a specific student
     */
    public function studentDetail(Child $child)
    {
        $teacher = Auth::user();
        $teacherId = $teacher->id;

        // Verify teacher has this student assigned
        $hasAccess = $teacher->assignedStudents()->where('children.id', $child->id)->exists();

        if (!$hasAccess) {
            abort(403, 'Unauthorized access to student details. This student is not assigned to you.');
        }

        $child->load([
            'user', // Parent user
            'assignedTeachers' => function ($q) {
                $q->select('users.id', 'users.name')
                  ->withPivot(['assigned_at', 'assigned_by', 'notes']);
            },
            'lessonProgress' => function ($q) use ($teacherId) {
                $q->whereHas('lesson.liveSessions', function ($sq) use ($teacherId) {
                    $sq->where('teacher_id', $teacherId);
                });
            },
            'assessmentSubmissions' => function ($q) use ($teacherId) {
                $q->whereHas('assessment.lesson', function ($sq) use ($teacherId) {
                    $sq->where('teacher_id', $teacherId);
                })->latest();
            },
        ]);

        // Format student data consistently
        $studentData = [
            'id' => $child->id,
            'child_name' => $child->child_name,
            'name' => $child->full_name,
            'date_of_birth' => $child->date_of_birth,
            'year_group' => $child->year_group,
            'user' => $child->user,
            'assigned_teachers' => $child->assignedTeachers,
            'lesson_progress' => $child->lessonProgress,
            'assessment_submissions' => $child->assessmentSubmissions,
            'academic_info' => $child->academic_info,
            'medical_info' => $child->medical_info,
            'additional_info' => $child->additional_info,
        ];

        return Inertia::render('@admin/Teacher/Students/Show', [
            'student' => $studentData,
        ]);
    }
}
