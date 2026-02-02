<?php
// app/Http/Controllers/ProgressController.php
namespace App\Http\Controllers;

use App\Models\Access;
use App\Models\Assessment;
use App\Models\Child;
use App\Models\ContentLesson;
use App\Models\Course;
use App\Models\Journey;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\LiveLessonSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class TrackerController extends Controller
{
    /**
     * Get course progress with module breakdown
     */
    private function getCourseProgress($childIds)
    {
        $accessRecords = Access::whereIn('child_id', $childIds)->get();
        
        Log::debug('getCourseProgress - Access Records', [
            'childIds' => $childIds,
            'accessCount' => $accessRecords->count(),
            'accessRecords' => $accessRecords->map(function($a) {
                return [
                    'id' => $a->id,
                    'child_id' => $a->child_id,
                    'course_ids' => $a->course_ids,
                ];
            })->toArray()
        ]);
        
        $courseIds = collect();
        foreach ($accessRecords as $access) {
            if (!empty($access->course_ids) && is_array($access->course_ids)) {
                foreach ($access->course_ids as $cid) {
                    if ($cid) {
                        $courseIds->push($cid);
                    }
                }
            }
        }
        $courseIds = $courseIds->unique();
        
        Log::debug('getCourseProgress - Collected Course IDs', [
            'courseIds' => $courseIds->toArray()
        ]);
        
        if ($courseIds->isEmpty()) {
            Log::debug('getCourseProgress - No courses found');
            return collect([]);
        }
        
        $courses = Course::with([
            'modules' => function($q) {
                $q->orderBy('order_position')
                  ->with([
                      'lessons' => function($lq) {
                          $lq->select('new_lessons.id', 'new_lessons.title', 'new_lessons.estimated_minutes', 'new_lessons.status');
                      }
                  ]);
            }
        ])
        ->whereIn('id', $courseIds)
        ->get();
        
        return $courses->map(function($course) use ($childIds) {
            $allLessonIds = $course->modules->flatMap(function($module) {
                return $module->lessons->pluck('id');
            });
            
            $progressRecords = LessonProgress::whereIn('child_id', $childIds)
                ->whereIn('lesson_id', $allLessonIds)
                ->get();
            
            $totalLessons = $allLessonIds->count();
            $completedLessons = $progressRecords->where('status', 'completed')->count();
            $totalTimeSpent = $progressRecords->sum('time_spent_seconds');
            $avgCompletion = $totalLessons > 0 
                ? $progressRecords->avg('completion_percentage') 
                : 0;
            
            $moduleProgress = $course->modules->map(function($module) use ($progressRecords, $childIds) {
                $moduleLessonIds = $module->lessons->pluck('id');
                $moduleProgressData = $progressRecords->whereIn('lesson_id', $moduleLessonIds);
                
                $total = $moduleLessonIds->count();
                $completed = $moduleProgressData->where('status', 'completed')->count();
                
                // Get content lessons for this module
                $contentLessons = ContentLesson::whereHas('modules', function($q) use ($module) {
                    $q->where('modules.id', $module->id);
                })
                ->with(['progress' => function($q) use ($childIds) {
                    $q->whereIn('child_id', $childIds);
                }])
                ->get()
                ->map(function($lesson) {
                    $progress = $lesson->progress->first();
                    return [
                        'id' => $lesson->id,
                        'title' => $lesson->title,
                        'status' => $progress?->status ?? 'not_started',
                        'completion_percentage' => $progress?->completion_percentage ?? 0,
                    ];
                });
                
                // Get assessments for this module
                $assessments = Assessment::whereHas('modules', function($q) use ($module) {
                    $q->where('modules.id', $module->id);
                })
                ->with(['submissions' => function($q) use ($childIds) {
                    $q->whereIn('child_id', $childIds);
                }])
                ->get()
                ->map(function($assessment) {
                    $submission = $assessment->submissions->first();
                    return [
                        'id' => $assessment->id,
                        'title' => $assessment->title,
                        'type' => $assessment->type,
                        'score' => $submission ? $submission->marks_obtained : null,
                        'total_marks' => $submission ? $submission->total_marks : null,
                        'percentage' => $submission && $submission->total_marks > 0 
                            ? round(($submission->marks_obtained / $submission->total_marks) * 100) 
                            : null,
                    ];
                });
                
                // Get live sessions for this course (filtered by module would require additional relationship)
                // For now, we'll get course-level live sessions
                $liveSessions = Lesson::whereHas('liveLessonSession', function($q) use ($module) {
                    $q->where('course_id', $module->course_id);
                })
                ->with(['attendances' => function($q) use ($childIds) {
                    $q->whereIn('child_id', $childIds);
                }])
                ->limit(5) // Limit to recent sessions per module
                ->orderBy('start_time', 'desc')
                ->get()
                ->map(function($session) {
                    $attendance = $session->attendances->first();
                    return [
                        'id' => $session->id,
                        'title' => $session->title,
                        'date' => $session->start_time?->toDateString(),
                        'attendance' => $attendance?->status ?? 'pending',
                        'is_live' => $session->liveLessonSession?->status === 'live',
                    ];
                });
                
                return [
                    'id' => $module->id,
                    'title' => $module->title,
                    'order' => $module->order_position,
                    'lessons_total' => $total,
                    'lessons_completed' => $completed,
                    'completion_percentage' => $total > 0 ? round(($completed / $total) * 100) : 0,
                    'status' => $completed === $total ? 'completed' : ($completed > 0 ? 'in_progress' : 'not_started'),
                    'content_lessons' => $contentLessons,
                    'assessments' => $assessments,
                    'live_sessions' => $liveSessions,
                ];
            });
            
            return [
                'id' => $course->id,
                'title' => $course->title,
                'description' => $course->description,
                'thumbnail' => $course->thumbnail,
                'total_modules' => $course->modules->count(),
                'completed_modules' => $moduleProgress->where('status', 'completed')->count(),
                'total_lessons' => $totalLessons,
                'completed_lessons' => $completedLessons,
                'overall_completion' => round($avgCompletion),
                'time_spent_minutes' => round($totalTimeSpent / 60),
                'last_accessed' => $progressRecords->max('last_accessed_at')?->toDateTimeString(),
                'modules' => $moduleProgress,
            ];
        });
    }

    /**
     * Get live session participation details
     */
    private function getLiveSessionDetails($childIds, $lessonIds)
    {
        if ($lessonIds->isEmpty()) {
            return collect([]);
        }
        
        $lessons = Lesson::with([
            'service:id,service_name',
            'liveLessonSession' => function($q) {
                $q->with([
                    'course:id,title',
                    'teacher:id,name',
                ]);
            },
            'participants' => function($q) use ($childIds) {
                $q->whereIn('child_id', $childIds)
                  ->with('child:id,child_name');
            }
        ])
        ->whereIn('id', $lessonIds)
        ->orderBy('start_time', 'desc')
        ->get();
        
        return $lessons->map(function($lesson) {
            $participants = $lesson->participants->map(function($participant) {
                $duration = 0;
                if ($participant->joined_at && $participant->left_at) {
                    $duration = $participant->joined_at->diffInMinutes($participant->left_at);
                } elseif ($participant->joined_at) {
                    $duration = $participant->joined_at->diffInMinutes(now());
                }
                
                return [
                    'child_id' => $participant->child_id,
                    'child_name' => $participant->child->child_name ?? 'Unknown',
                    'status' => $participant->status,
                    'joined_at' => $participant->joined_at?->toDateTimeString(),
                    'left_at' => $participant->left_at?->toDateTimeString(),
                    'duration_minutes' => $duration,
                    'connection_status' => $participant->connection_status,
                    'interaction_data' => $participant->interaction_data,
                ];
            });
            
            return [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'service' => $lesson->service?->service_name,
                'date' => $lesson->start_time?->toDateString(),
                'start_time' => $lesson->start_time?->toDateTimeString(),
                'end_time' => $lesson->end_time?->toDateTimeString(),
                'participants' => $participants,
                'total_participants' => $participants->count(),
                'live_session' => $lesson->liveLessonSession ? [
                    'status' => $lesson->liveLessonSession->status,
                    'scheduled_start' => $lesson->liveLessonSession->scheduled_start_time?->toDateTimeString(),
                    'actual_start' => $lesson->liveLessonSession->actual_start_time?->toDateTimeString(),
                    'end_time' => $lesson->liveLessonSession->end_time?->toDateTimeString(),
                    'duration_minutes' => $lesson->liveLessonSession->duration ?? 0,
                    'active_participants' => $lesson->liveLessonSession->getActiveParticipantsCount(),
                    'teacher_name' => $lesson->liveLessonSession->teacher?->name,
                    'course' => $lesson->liveLessonSession->course?->title,
                ] : null,
            ];
        });
    }

    /**
     * Get assessment course context (via Module → Course)
     */
    private function getAssessmentCourseContext($assessment)
    {
        // Check if assessment is attached to any module
        $module = $assessment->modules->first();
        
        if ($module && $module->course) {
            return [
                'type' => 'module',
                'course_name' => $module->course->title,
                'course_id' => $module->course_id,
                'module_name' => $module->title,
                'module_id' => $module->id,
            ];
        }
        
        // No course context available
        return null;
    }

    /**
     * Get content lesson progress
     */
    private function getContentLessonProgress($childIds)
    {
        Log::debug('getContentLessonProgress - START', ['childIds' => $childIds->toArray()]);
        
        $accessRecords = Access::whereIn('child_id', $childIds)->get();
        
        Log::debug('getContentLessonProgress - Access Records', [
            'count' => $accessRecords->count(),
            'records' => $accessRecords->map(function($a) {
                return [
                    'id' => $a->id,
                    'child_id' => $a->child_id,
                    'content_lesson_id' => $a->content_lesson_id,
                    'lesson_ids' => $a->lesson_ids,
                    'course_ids' => $a->course_ids,
                ];
            })->toArray()
        ]);
        
        $contentLessonIds = collect();
        
        // 1. Standalone content lessons
        foreach ($accessRecords as $access) {
            if ($access->content_lesson_id) {
                $contentLessonIds->push($access->content_lesson_id);
                Log::debug('Added standalone content lesson', ['lesson_id' => $access->content_lesson_id]);
            }
        }
        
        // 2. Content lessons from courses
        foreach ($accessRecords as $access) {
            if ($access->course_ids && is_array($access->course_ids)) {
                $courseIds = $access->course_ids;
                Log::debug('Processing course IDs', ['course_ids' => $courseIds]);
                
                $courseLessons = Course::with('modules.lessons')
                    ->whereIn('id', $courseIds)
                    ->get()
                    ->flatMap(function($course) {
                        return $course->modules->flatMap(function($m) {
                            return $m->lessons->pluck('id');
                        });
                    });
                
                Log::debug('Course lessons found', ['lesson_ids' => $courseLessons->toArray()]);
                
                foreach ($courseLessons as $lessonId) {
                    $contentLessonIds->push($lessonId);
                }
            }
        }
        
        $contentLessonIds = $contentLessonIds->unique();
        
        Log::debug('getContentLessonProgress - Final Lesson IDs', [
            'count' => $contentLessonIds->count(),
            'ids' => $contentLessonIds->toArray()
        ]);
        
        if ($contentLessonIds->isEmpty()) {
            Log::debug('getContentLessonProgress - No content lessons found, returning empty collection');
            return collect([]);
        }
        
        $lessons = ContentLesson::with([
            'modules.course',
            'slides',
            'progress' => function($q) use ($childIds) {
                $q->whereIn('child_id', $childIds);
            }
        ])
        ->whereIn('id', $contentLessonIds)
        ->get();
        
        return $lessons->map(function($lesson) {
            $progress = $lesson->progress->first();
            $course = $lesson->modules->first()?->course;
            $module = $lesson->modules->first();
            
            return [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'description' => $lesson->description,
                'course_name' => $course?->title,
                'course_id' => $course?->id,
                'module_name' => $module?->title,
                'module_id' => $module?->id,
                'journey_category_id' => $lesson->journey_category_id ?? $course?->journey_category_id,
                'total_slides' => $lesson->slides->count(),
                'status' => $progress?->status ?? 'not_started',
                'completion_percentage' => $progress?->completion_percentage ?? 0,
                'slides_viewed' => count($progress?->slides_viewed ?? []),
                'questions_attempted' => $progress?->questions_attempted ?? 0,
                'questions_correct' => $progress?->questions_correct ?? 0,
                'questions_total' => $progress?->questions_total ?? 0,
                'time_spent_minutes' => round(($progress?->time_spent_seconds ?? 0) / 60),
                'last_accessed' => $progress?->last_accessed_at?->toDateTimeString(),
            ];
        });
    }

    public function show(Request $request)
    {
        $user     = $request->user();          // either parent or admin
        $childKey = $request->get('child', 'all');
        $orgId    = $user?->current_organization_id;

        /* ── 1. Which children does this user see? ─────── */
        $children = $user->role === 'admin'
            ? Child::select('id', 'child_name AS name')
                ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
                ->orderBy('name')
                ->get()
            : $user->children()
                   ->select('id', 'child_name AS name')
                   ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
                   ->orderBy('name')
                   ->get();

        $childIds = ($childKey === 'all')
            ? $children->pluck('id')
            : collect([(int)$childKey]);

        // 2. Use access table to determine lessons/assessments for these children
        $accessRecords = \App\Models\Access::whereIn('child_id', $childIds)
            // ->where('access', true)
            // ->where('payment_status', 'paid')
            ->get();

        $lessonIds = collect();
        $assessmentIds = collect();
        foreach ($accessRecords as $access) {
            if ($access->lesson_id) {
                $lessonIds->push($access->lesson_id);
            }
            if ($access->lesson_ids) {
                foreach ((array) $access->lesson_ids as $lid) {
                    $lessonIds->push($lid);
                }
            }
            if ($access->assessment_id) {
                $assessmentIds->push($access->assessment_id);
            }
            if ($access->assessment_ids) {
                foreach ((array) $access->assessment_ids as $aid) {
                    $assessmentIds->push($aid);
                }
            }
        }
        $lessonIds = $lessonIds->unique()->values();
        $assessmentIds = $assessmentIds->unique()->values();

        // Fetch lessons and attach attendances
        $lessons = Lesson::with([
                'service:id,service_name',
                'attendances' => fn($q) => $q->whereIn('child_id', $childIds),
            ])
            ->whereIn('id', $lessonIds)
            ->orderBy('start_time')
            ->get();

        $lessonRows = $lessons->map(function($l) use ($childKey) {
            if ($childKey === 'all') {
                $distinct = $l->attendances->pluck('status')->unique();
                $status   = ($distinct->count() === 1)
                    ? $distinct->first()
                    : 'mixed';
            } else {
                $status = optional($l->attendances->first())->status ?? 'pending';
            }

            return [
                'id'         => $l->id,
                'title'      => $l->title,
                'service'    => $l->service?->service_name,
                'date'       => optional($l->start_time)->toDateString(),
                'attendance' => $status,   // present/absent/late/excused/pending/mixed
            ];
        });

        // Fetch assessments and attach submissions
        $assessments = Assessment::with([
                'submissions' => function($q) use ($childIds) {
                    $q->whereIn('child_id', $childIds)
                      ->with('child:id,child_name'); // eager‐load child's name
                },
                'modules.course:id,title', // NEW: Get course through module
            ])
            ->whereIn('id', $assessmentIds)
            ->get();

        /* ── 4. Shape "assessmentRows" plus per-category breakdown ─────────── */
        $assessmentRows = $assessments->map(function($a) {
            $subs = $a->submissions->map(function($s) {
                // $s->answers_json is an array of [question_text, category, marks, marks_awarded, …]
                $answers = collect($s->answers_json ?? []);

                // 4a) Per‐category totals:
                $byCategory = $answers
                    ->groupBy('category')
                    ->map(fn($group, $cat) => [
                        'category'      => $cat,
                        'obtained'      => $group->sum('marks_awarded'),
                        'total_marks'   => $group->sum('marks'),
                    ])
                    ->values();

                return [
                    'submission_id'    => $s->id,
                    'child_id'         => $s->child_id,
                    'child_name'       => $s->child->child_name,     // eager‐loaded above
                    'score'            => $s->marks_obtained,
                    'total_marks'      => $s->total_marks,
                    'submitted_at'     => optional($s->finished_at)->toDateTimeString(),
                    'status'           => $s->status,
                    'category_breakdown'=> $byCategory,               // array of {category, obtained, total_marks}
                ];
            });

            return [
                'id'          => $a->id,
                'title'       => $a->title,
                'description' => $a->description,
                'type'        => $a->type,
                'date'        => optional($a->availability)->toDateString(),
                'submissions' => $subs,
                'course_context' => $this->getAssessmentCourseContext($a),
            ];
        });

        /* ── 5. Build a per‐child "overall" summary (across all assessments) ─ */
        $childStats = collect();
        foreach ($childIds as $cid) {
            // Grab every submission for this child (across all assessments)
            $allSubs = $assessments
                ->flatMap(fn($a) => $a->submissions)
                ->where('child_id', $cid);

            // Correct fields:
            $totalTaken = $allSubs->count();
            $totalObtained = $allSubs->sum('marks_obtained');
            $totalPossible = $allSubs->sum('total_marks');

            // Calculate average_score as a percentage (if total possible > 0)
            $avgScore = $totalPossible > 0
                ? round(($totalObtained / $totalPossible) * 100, 2)
                : 0;

            $childStats[$cid] = [
                'child_id'          => $cid,
                'assessments_taken' => $totalTaken,
                'average_score'     => $avgScore,
                'total_obtained'    => $totalObtained,
                'total_possible'    => $totalPossible,
            ];
        }

        /* ── 6. Get Journey Overview Data (from JourneyController logic) ─────── */
        $journeys = Journey::when($orgId, fn($q) => $q->forOrganization($orgId))
            ->with([
                'categories' => fn ($q) => $q
                    ->when($orgId, fn($catQ) => $catQ->forOrganization($orgId))
                    ->with([
                        // 'lessons:id,session_code,status',
                        'assessments:id,title,journey_category_id',
                    ])
                    ->orderBy('topic')
                    ->orderBy('name'),
            ])
            ->orderBy('title')
            ->get();

        // Enhance each category with course data
        foreach ($journeys as $journey) {
            foreach ($journey->categories as $category) {
                // 1. Get courses linked to this category
                $category->courses = Course::where('journey_category_id', $category->id)
                    ->with([
                        'modules.lessons', // Content lessons (via Module relationship)
                    ])
                    ->get()
                    ->map(function($course) use ($childIds) {
                        // Calculate course progress
                        $allLessons = $course->modules->flatMap(fn($m) => $m->lessons);
                        $progressRecords = LessonProgress::whereIn('child_id', $childIds)
                            ->whereIn('lesson_id', $allLessons->pluck('id'))
                            ->get();
                        
                        $totalLessons = $allLessons->count();
                        $completedLessons = $progressRecords->where('status', 'completed')->count();
                        $avgProgress = $totalLessons > 0 
                            ? ($completedLessons / $totalLessons) * 100 
                            : 0;
                        
                        return [
                            'id' => $course->id,
                            'title' => $course->title,
                            'thumbnail' => $course->thumbnail,
                            'description' => $course->description,
                            'progress' => round($avgProgress),
                            'total_lessons' => $totalLessons,
                            'completed_lessons' => $completedLessons,
                            'modules_count' => $course->modules->count(),
                            
                            // Content lessons from all modules
                            'content_lessons' => $allLessons->map(fn($l) => [
                                'id' => $l->id,
                                'title' => $l->title,
                                'module_id' => $l->pivot->module_id ?? null,
                            ]),
                            
                            // Live sessions (prepared but not used initially)
                            // 'live_sessions' => $course->modules->flatMap(fn($m) => $m->lessons)->map(fn($l) => [
                            //     'id' => $l->id,
                            //     'title' => $l->title,
                            //     'date' => $l->start_time?->toDateString(),
                            // ]),
                        ];
                    });
                
                // 2. Identify standalone content lessons (not in any course)
                $courseLessonIds = $category->courses->flatMap(function($course) {
                    return collect($course['content_lessons'])->pluck('id');
                });
                
                // Get all content lessons directly linked to this category via journey_category_id
                $categoryContentLessons = ContentLesson::where('journey_category_id', $category->id)
                    ->with(['progress' => function($q) use ($childIds) {
                        $q->whereIn('child_id', $childIds);
                    }])
                    ->get();
                
                $category->standalone_content_lessons = $categoryContentLessons
                    ->whereNotIn('id', $courseLessonIds)
                    ->map(function($l) {
                        $progress = $l->progress->first();
                        return [
                            'id' => $l->id,
                            'title' => $l->title,
                            'type' => 'content_lesson',
                            'status' => $progress?->status ?? 'not_started',
                            'completion_percentage' => $progress?->completion_percentage ?? 0,
                        ];
                    });
                
                // 3. Identify standalone assessments (not linked to courses)
                $category->standalone_assessments = $category->assessments
                    ->reject(function($assessment) use ($category) {
                        // Check if assessment is linked to any course in this category
                        return DB::table('assessment_course')
                            ->where('assessment_id', $assessment->id)
                            ->whereIn('course_id', function($query) use ($category) {
                                $query->select('id')
                                    ->from('courses')
                                    ->where('journey_category_id', $category->id);
                            })
                            ->exists();
                    })
                    ->map(fn($a) => [
                        'id' => $a->id,
                        'title' => $a->title,
                        'type' => 'assessment',
                    ]);
                
                // 4. Build comprehensive access data for this category
                $accessInfo = [
                    'courses' => [],
                    'content_lessons' => [],
                    'assessments' => [],
                    'live_sessions' => [],
                ];
                
                // Get all access records for these children
                $childAccessRecords = Access::whereIn('child_id', $childIds)->get();
                
                // Track course access
                foreach ($category->courses as $course) {
                    $hasAccess = $childAccessRecords->filter(function($access) use ($course) {
                        return $access->course_ids && in_array($course['id'], (array)$access->course_ids);
                    })->isNotEmpty();
                    
                    $accessInfo['courses'][$course['id']] = [
                        'has_access' => $hasAccess,
                        'child_ids' => $childAccessRecords->filter(function($access) use ($course) {
                            return $access->course_ids && in_array($course['id'], (array)$access->course_ids);
                        })->pluck('child_id')->unique()->values()->toArray(),
                    ];
                }
                
                // Track standalone content lesson access
                foreach ($category->standalone_content_lessons as $lesson) {
                    $hasAccess = $childAccessRecords->filter(function($access) use ($lesson) {
                        return ($access->content_lesson_id == $lesson['id']) ||
                               ($access->lesson_ids && in_array($lesson['id'], (array)$access->lesson_ids));
                    })->isNotEmpty();
                    
                    $accessInfo['content_lessons'][$lesson['id']] = [
                        'has_access' => $hasAccess,
                        'child_ids' => $childAccessRecords->filter(function($access) use ($lesson) {
                            return ($access->content_lesson_id == $lesson['id']) ||
                                   ($access->lesson_ids && in_array($lesson['id'], (array)$access->lesson_ids));
                        })->pluck('child_id')->unique()->values()->toArray(),
                    ];
                }
                
                // Track assessment access
                foreach ($category->standalone_assessments as $assessment) {
                    $hasAccess = $childAccessRecords->filter(function($access) use ($assessment) {
                        return ($access->assessment_id == $assessment['id']) ||
                               ($access->assessment_ids && in_array($assessment['id'], (array)$access->assessment_ids));
                    })->isNotEmpty();
                    
                    $accessInfo['assessments'][$assessment['id']] = [
                        'has_access' => $hasAccess,
                        'child_ids' => $childAccessRecords->filter(function($access) use ($assessment) {
                            return ($access->assessment_id == $assessment['id']) ||
                                   ($access->assessment_ids && in_array($assessment['id'], (array)$access->assessment_ids));
                        })->pluck('child_id')->unique()->values()->toArray(),
                    ];
                }
                
                // Track live session access (from legacy lessons)
                $categoryLessons = $category->lessons;
                foreach ($categoryLessons as $lesson) {
                    $hasAccess = $childAccessRecords->filter(function($access) use ($lesson) {
                        return ($access->lesson_id == $lesson->id) ||
                               ($access->lesson_ids && in_array($lesson->id, (array)$access->lesson_ids));
                    })->isNotEmpty();
                    
                    $accessInfo['live_sessions'][$lesson->id] = [
                        'has_access' => $hasAccess,
                        'child_ids' => $childAccessRecords->filter(function($access) use ($lesson) {
                            return ($access->lesson_id == $lesson->id) ||
                                   ($access->lesson_ids && in_array($lesson->id, (array)$access->lesson_ids));
                        })->pluck('child_id')->unique()->values()->toArray(),
                    ];
                }
                
                $category->access_info = $accessInfo;
                
                // Summary counts for quick reference
                $category->access_summary = [
                    'total_courses' => $category->courses->count(),
                    'owned_courses' => collect($accessInfo['courses'])->where('has_access', true)->count(),
                    'total_content_lessons' => $category->standalone_content_lessons->count(),
                    'owned_content_lessons' => collect($accessInfo['content_lessons'])->where('has_access', true)->count(),
                    'total_assessments' => $category->standalone_assessments->count(),
                    'owned_assessments' => collect($accessInfo['assessments'])->where('has_access', true)->count(),
                    'total_live_sessions' => $categoryLessons->count(),
                    'owned_live_sessions' => collect($accessInfo['live_sessions'])->where('has_access', true)->count(),
                ];
            }
        }

        // Reshape: group each journey's categories by TOPIC
        $journeys = $journeys->map(function (Journey $j) {
            $byTopic = $j->categories
                ->groupBy('topic')
                ->map(function ($cats) {
                    return $cats->map(function ($cat) {
                        return [
                            'id'          => $cat->id,
                            'name'        => $cat->name,
                            'topic'       => $cat->topic,
                            'lessons'     => $cat->lessons->map->only(['id', 'title']),
                            'assessments' => $cat->assessments->map->only(['id', 'title']),
                            // NEW: Course data
                            'courses'     => $cat->courses ?? collect([]),
                            'standalone_content_lessons' => $cat->standalone_content_lessons ?? collect([]),
                            'standalone_assessments' => $cat->standalone_assessments ?? collect([]),
                            // NEW: Access information
                            'access_info' => $cat->access_info ?? [
                                'courses' => [],
                                'content_lessons' => [],
                                'assessments' => [],
                                'live_sessions' => [],
                            ],
                            'access_summary' => $cat->access_summary ?? [
                                'total_courses' => 0,
                                'owned_courses' => 0,
                                'total_content_lessons' => 0,
                                'owned_content_lessons' => 0,
                                'total_assessments' => 0,
                                'owned_assessments' => 0,
                                'total_live_sessions' => 0,
                                'owned_live_sessions' => 0,
                            ],
                        ];
                    })->values();
                });
                                
            return [
                'id'      => $j->id,
                'title'   => $j->title,
                'topics'  => $byTopic,   // key = topic string, value = [ {category,…}, … ]
            ];
        });

        // Get children data for journey overview (using access table)
        $childrenData = [];
        $childrenForJourney = ($user->role == 'admin'
            ? Child::query()
            : $user->children())
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->with('services.lessons', 'services.assessments')
            ->get();

        foreach ($childrenForJourney as $child) {
            $lessonIds = collect();
            $assessmentIds = collect();

            $accessRecords = \App\Models\Access::where('child_id', $child->id)
                // ->where('access', true)
                // ->where('payment_status', 'paid')
                ->get();

            foreach ($accessRecords as $access) {
                if ($access->lesson_id) {
                    $lessonIds->push($access->lesson_id);
                }
                if ($access->lesson_ids) {
                    foreach ((array) $access->lesson_ids as $lid) {
                        $lessonIds->push($lid);
                    }
                }
                if ($access->assessment_id) {
                    $assessmentIds->push($access->assessment_id);
                }
                if ($access->assessment_ids) {
                    foreach ((array) $access->assessment_ids as $aid) {
                        $assessmentIds->push($aid);
                    }
                }
            }

            $childrenData[] = [
                'child_id' => $child->id,
                'lesson_ids' => $lessonIds->unique()->values(),
                'assessment_ids' => $assessmentIds->unique()->values(),
            ];
        }

        // NEW: Get course and content lesson progress
        $courses = $this->getCourseProgress($childIds);
        $contentLessons = $this->getContentLessonProgress($childIds);
        $liveSessionDetails = $this->getLiveSessionDetails($childIds, $lessonIds);

        Log::debug('assessmentRows', [
            'assessmentRows' => $assessmentRows->toArray(),
            'childStats'     => $childStats->toArray(),
        ]);

        /* ── 7. Return Inertia data ────────────────────────────────────── */
        return Inertia::render('@parent/Main/ProgressTracker', [
            'progressData' => [
                'lessons'       => $lessonRows,
                'assessments'   => $assessmentRows,
                'childrenStats' => $childStats->values()->all(),
                // NEW: Add courses and content lessons
                'courses'       => $courses,
                'contentLessons'=> $contentLessons,
                'liveSessionDetails' => $liveSessionDetails,
            ],
            'childrenList'  => $children,       // [{ id, name }, …]
            'selectedChild' => $childKey,
            'journeys'      => $journeys,       // Journey overview data
            'childrenData'  => $childrenData,   // Children data for journey overview
        ]);
    }
}
