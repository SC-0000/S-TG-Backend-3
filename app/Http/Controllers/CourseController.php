<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CourseController extends Controller
{
    /**
     * Get route prefix based on user role
     */
    private function getRoutePrefix(): string
    {
        $user = Auth::user();
        return $user->hasRole('teacher') ? 'teacher' : 'admin';
    }
    
    /**
     * Browse available courses (courses that have services)
     */
    public function browse()
    {
        $courses = Course::with(['modules', 'assessments'])
            ->whereHas('service') // Only show courses that can be purchased
            ->get()
            ->map(function ($course) {
                $service = Service::where('course_id', $course->id)->first();
                
                // Count content
                $totalLessons = $course->modules->sum(function ($module) {
                    return $module->lessons()->count();
                });
                
                $totalLiveSessions = $course->modules->sum(function ($module) {
                    return $module->lessons->sum(function ($lesson) {
                        return $lesson->liveSessions()->count();
                    });
                });
                
                $totalAssessments = $course->modules->sum(function ($module) {
                    return $module->assessments()->count();
                }) + $course->assessments()->count();

                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'description' => $course->description,
                    'thumbnail' => $course->thumbnail,
                    'category' => $course->category,
                    'level' => $course->level,
                    'estimated_duration_minutes' => $course->estimated_duration_minutes,
                    'is_featured' => $course->is_featured,
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
            ->filter(fn($course) => $course['service'] !== null); // Only include courses with services

        return Inertia::render('@parent/Courses/Browse', [
            'courses' => $courses->values(),
        ]);
    }

    /**
     * Show enrolled courses for the logged-in parent's children
     */
    public function myCourses()
    {
        $user = Auth::user();
        $children = $user->children()->with(['accesses' => function($query) {
            $query->where('access', true)
                  ->whereNotNull('content_lesson_id');
        }])->get();

        $enrolledCourses = [];

        foreach ($children as $child) {
            $courses = $child->enrolledCourses()->get()->map(function ($course) use ($child) {
                // Calculate progress
                $totalLessons = $course->modules->sum(function ($module) {
                    return $module->lessons->count();
                });

                $completedLessons = 0;
                foreach ($course->modules as $module) {
                    foreach ($module->lessons as $lesson) {
                        $progress = \App\Models\LessonProgress::where('child_id', $child->id)
                            ->where('lesson_id', $lesson->id)
                            ->where('status', 'completed')
                            ->exists();
                        
                        if ($progress) {
                            $completedLessons++;
                        }
                    }
                }

                $progressPercentage = $totalLessons > 0 
                    ? round(($completedLessons / $totalLessons) * 100) 
                    : 0;

                return [
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
            });

            $enrolledCourses = array_merge($enrolledCourses, $courses->toArray());
        }

        return Inertia::render('@parent/Courses/MyCourses', [
            'enrolledCourses' => $enrolledCourses,
            'children' => $children->map(fn($child) => [
                'id' => $child->id,
                'name' => $child->child_name,
            ]),
        ]);
    }

    /**
     * Show detailed view of a course
     */
    public function show(Course $course)
    {
        $course->load([
            'modules.lessons',
            'modules.lessons.liveSessions',
            'modules.assessments',
            'assessments'
        ]);

        $service = Service::where('course_id', $course->id)->first();

        // Check if any child has access
        $hasAccess = false;
        if (Auth::check()) {
            $children = Auth::user()->children;
            foreach ($children as $child) {
                if ($child->hasAccessToCourse($course->id)) {
                    $hasAccess = true;
                    break;
                }
            }
        }

        // Get lesson IDs that belong to this course's modules
        $courseLessonIds = $course->modules()
            ->with('lessons')
            ->get()
            ->pluck('lessons')
            ->flatten()
            ->pluck('id')
            ->unique()
            ->values();
            
        // Get only live sessions for lessons in this course with course_id matching
        $allLiveSessions = \App\Models\LiveLessonSession::with('lesson:id,title,description')
            ->select('id', 'uid', 'lesson_id', 'course_id', 'scheduled_start_time', 'status', 'session_code')
            ->whereIn('lesson_id', $courseLessonIds)
            ->where('course_id', $course->id)
            ->orderBy('scheduled_start_time', 'desc')
            ->get()
            ->map(function($session) {
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

        return Inertia::render('@parent/Courses/Show', [
            'course' => [
                'id' => $course->id,
                'uid' => $course->uid,
                'title' => $course->title,
                'description' => $course->description,
                'thumbnail' => $course->thumbnail,
                'category' => $course->category,
                'level' => $course->level,
                'estimated_duration_minutes' => $course->estimated_duration_minutes,
                'is_featured' => $course->is_featured,
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
                                'progress' => null, // Can be populated per child
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
                ] : null,
                'has_access' => $hasAccess,
            ],
            'allLiveSessions' => $allLiveSessions,
        ]);
    }
     public function view(Course $course)
    {
        $course->load(['modules.lessons.slides']);
        Log::info('Viewing course', ['course_id' => $course->id, 'modules_count' => $course->modules->count()]);
        return Inertia::render('@parent/ContentLessons/CourseView', [
            'course' => [
                'id' => $course->id,
                'uid' => $course->uid,
                'title' => $course->title,
                'description' => $course->description,
                'thumbnail' => $course->thumbnail,
                'metadata' => $course->metadata,
                'modules' => $course->modules->map(function ($module) {
                    return [
                        'id' => $module->id,
                        'title' => $module->title,
                        'description' => $module->description,
                        'lessons_count' => $module->lessons->count(),
                        'lessons' => $module->lessons->map(function ($lesson) {
                            return [
                                'id' => $lesson->id,
                                'uid' => $lesson->uid,
                                'title' => $lesson->title,
                                'description' => $lesson->description,
                                'slides_count' => $lesson->slides->count(),
                                'estimated_minutes' => $lesson->estimated_minutes,
                            ];
                        }),
                    ];
                }),
            ],
        ]);
    }

    /**
     * Display a listing of courses (for admin).
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        
        $query = Course::with(['organization', 'modules']);
        
        // Organization filtering
        if ($user->role === 'super_admin') {
            // Super Admin: Can see all courses, with optional organization filter
            if ($request->has('organization_id') && $request->organization_id) {
                $query->where('organization_id', $request->organization_id);
            }
            // Otherwise show ALL courses
        } else {
            // Admin/Teacher: Show global + their organization's courses
            $query->visibleToOrg($user->current_organization_id);
        }
        
        // Apply filters (use filled() to only filter when value is not empty)
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('title', 'like', '%' . $request->search . '%')
                  ->orWhere('description', 'like', '%' . $request->search . '%');
            });
        }
        
        $courses = $query->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($course) use ($user) {
                return [
                    'id' => $course->id,
                    'uid' => $course->uid,
                    'title' => $course->title,
                    'description' => $course->description,
                    'thumbnail' => $course->thumbnail,
                    'status' => $course->status,
                    'modules_count' => $course->modules->count(),
                    'estimated_duration_minutes' => $course->estimated_duration_minutes,
                    'is_featured' => $course->is_featured,
                    'organization' => $course->organization ? [
                        'id' => $course->organization->id,
                        'name' => $course->organization->name,
                    ] : null,
                    'created_at' => $course->created_at,
                    'created_by' => $course->created_by,
                    'created_by_me' => $course->created_by === $user->id,
                ];
            })
            ->toArray();
        
        // Get organizations list ONLY for super admin
        $organizations = $user->role === 'super_admin' 
            ? \App\Models\Organization::select('id', 'name')->orderBy('name')->get()
            : null;
        Log::info('CourseController@index hit', [
            'user_id' => auth()->id(),
            'user_role' => auth()->user()->role ?? 'no-role',
            'user_email' => auth()->user()->email ?? 'no-email',
            'courses_count' => count($courses),
        ]);
        return Inertia::render('@admin/ContentManagement/Courses/Index', [
            'courses' => $courses,
            'organizations' => $organizations,
            'filters' => $request->only(['status', 'search', 'organization_id']),
        ]);
    }

    /**
     * Teacher's course index - scoped to their organization
     */
    public function teacherIndex(Request $request)
    {
        $user = Auth::user();
        
        $query = Course::with(['organization', 'modules'])
            ->visibleToOrg($user->current_organization_id);
        
        // Apply filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        
        if ($request->has('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('title', 'like', '%' . $request->search . '%')
                  ->orWhere('description', 'like', '%' . $request->search . '%');
            });
        }
        
        $courses = $query->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($course) use ($user) {
                return [
                    'id' => $course->id,
                    'uid' => $course->uid,
                    'title' => $course->title,
                    'description' => $course->description,
                    'thumbnail' => $course->thumbnail,
                    'status' => $course->status,
                    'modules_count' => $course->modules->count(),
                    'estimated_duration_minutes' => $course->estimated_duration_minutes,
                    'is_featured' => $course->is_featured,
                    'created_at' => $course->created_at,
                    'created_by' => $course->created_by,
                    'created_by_me' => $course->created_by === $user->id,
                ];
            })
            ->toArray();
        
        return Inertia::render('@admin/ContentManagement/Courses/Index', [
            'courses' => $courses,
            'filters' => $request->only(['status', 'search']),
        ]);
    }

    /**
     * Show the form for creating a new course.
     */
    public function create()
    {
        Log::info('CourseController@create hit', [
            'user_id' => auth()->id(),
            'user_role' => auth()->user()->role ?? 'no-role',
            'user_email' => auth()->user()->email ?? 'no-email',
        ]);
        
        $user = auth()->user();
        $orgId = $user?->role === 'super_admin' ? null : $user?->current_organization_id;
        $journeyCategories = \App\Models\JourneyCategory::with('journey')
            ->when($orgId, fn($q) => $q->forOrganization($orgId))
            ->orderBy('name')
            ->get();
        
        $organizations = $user?->role === 'super_admin'
            ? \App\Models\Organization::select('id', 'name')->orderBy('name')->get()
            : null;

        return Inertia::render('@admin/ContentManagement/Courses/Create', [
            'journeyCategories' => $journeyCategories,
            'organizations' => $organizations,
        ]);
    }

    /**
     * Store a newly created course.
     */
    public function store(Request $request)
    {
        $routePrefix = $this->getRoutePrefix();
        
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'year_group' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'thumbnail' => 'nullable|string',
            'cover_image' => 'nullable|string',
            'metadata' => 'nullable|array',
            'journey_category_id' => 'nullable|exists:journey_categories,id',
            'is_global' => 'nullable|boolean',
            'organization_id' => 'nullable|integer|exists:organizations,id',
        ]);

        $user = $request->user();
        $isSuperAdmin = $user->role === 'super_admin';
        $isGlobal = $isSuperAdmin && $request->boolean('is_global');
        $organizationId = $request->input('organization_id');

        if (!$isSuperAdmin) {
            $organizationId = $user->current_organization_id;
            $isGlobal = false;
        }

        if ($isGlobal) {
            $organizationId = null;
        }

        if ($isSuperAdmin && !$isGlobal && !$organizationId) {
            throw ValidationException::withMessages([
                'organization_id' => 'Organization is required unless the course is global.',
            ]);
        }
        
        // Merge extra metadata like category, level, etc. into the metadata JSON field
        $metadata = $validated['metadata'] ?? [];
        
        $course = Course::create([
            'organization_id' => $organizationId,
            'is_global' => $isGlobal,
            'journey_category_id' => $validated['journey_category_id'] ?? null,
            'title' => $validated['title'],
            'year_group' => $validated['year_group'] ?? null,
            'description' => $validated['description'] ?? null,
            'thumbnail' => $validated['thumbnail'] ?? null,
            'cover_image' => $validated['cover_image'] ?? null,
            'status' => 'draft',
            'metadata' => $metadata,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);
        
        return redirect()
            ->route("{$routePrefix}.courses.index")
            ->with('success', 'Course created successfully.');
    }

  

    /**
     * Show the form for editing the specified course.
     */
    public function edit(Course $course)
    {
        $course->load([
            'modules' => function ($query) {
                $query->orderBy('order_position');
            },
            'modules.lessons' => function ($query) {
                $query->orderBy('order_position');
            },
            'modules.lessons.slides',
            'modules.lessons.liveSessions',
            'modules.assessments',
        ]);
        
        // Get all available lessons for selection
        $allLessons = \App\Models\ContentLesson::select('id', 'uid', 'title', 'description', 'estimated_minutes')
            ->orderBy('title')
            ->get();
            
        // Get all available assessments
        $allAssessments = \App\Models\Assessment::select('id', 'uid', 'title', 'description')
            ->orderBy('title')
            ->get();
            
        // Get lesson IDs that belong to this course's modules
        $courseLessonIds = $course->modules()
            ->with('lessons')
            ->get()
            ->pluck('lessons')
            ->flatten()
            ->pluck('id')
            ->unique()
            ->values();
            
        // Get only live sessions for lessons in this course
        // Show ONLY sessions where course_id matches this course
        $allLiveSessions = \App\Models\LiveLessonSession::with('lesson:id,title,description')
            ->select('id', 'uid', 'lesson_id', 'course_id', 'scheduled_start_time', 'status', 'session_code')
            ->whereIn('lesson_id', $courseLessonIds)
            ->where('course_id', $course->id)
            ->orderBy('scheduled_start_time', 'desc')
            ->get()
            ->map(function($session) {
                return [
                    'id' => $session->id,
                    'uid' => $session->uid,
                    'lesson_id' => $session->lesson_id,
                    'course_id' => $session->course_id,
                    'title' => $session->lesson ? $session->lesson->title : 'Untitled Session',
                    'description' => $session->lesson ? $session->lesson->description : '',
                    'scheduled_start_time' => $session->scheduled_start_time?->format('Y-m-d H:i:s'),
                    'status' => $session->status,
                    'session_code' => $session->session_code,
                ];
            });
        
        // Get all teachers for live session creation
        $teachers = \App\Models\User::where('role', 'teacher')
            ->orWhere('role', 'admin')
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();
        
        return Inertia::render('@admin/ContentManagement/Courses/Edit', [
            'course' => [
                'id' => $course->id,
                'uid' => $course->uid,
                'title' => $course->title,
                'description' => $course->description,
                'thumbnail' => $course->thumbnail,
                'cover_image' => $course->cover_image,
                'status' => $course->status,
                'metadata' => $course->metadata,
                'created_at' => $course->created_at,
                'journey_category_id' => $course->journey_category_id,
                'year_group' => $course->year_group,
                'organization_id' => $course->organization_id,
                'is_global' => (bool) $course->is_global,
                'modules' => $course->modules->map(function ($module) use ($course) {
                    return [
                        'id' => $module->id,
                        'uid' => $module->uid,
                        'title' => $module->title,
                        'description' => $module->description,
                        'order_position' => $module->order_position,
                        'status' => $module->status,
                        'lessons_count' => $module->lessons->count(),
                        'estimated_duration_minutes' => $module->estimated_duration_minutes,
                        'lessons' => $module->lessons->map(function ($lesson) use ($course) {
                            return [
                                'id' => $lesson->id,
                                'uid' => $lesson->uid,
                                'title' => $lesson->title,
                                'description' => $lesson->description,
                                'order_position' => $lesson->pivot->order_position ?? 0,
                                'status' => $lesson->status,
                                'lesson_type' => $lesson->lesson_type,
                                'estimated_minutes' => $lesson->estimated_minutes,
                                'slides_count' => $lesson->slides->count(),
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
            ],
            'allLessons' => $allLessons,
            'allAssessments' => $allAssessments,
            'allLiveSessions' => $allLiveSessions,
            'teachers' => $teachers,
            'journeyCategories' => \App\Models\JourneyCategory::with('journey')
                ->when(
                    (auth()->user()?->role === 'super_admin') ? null : auth()->user()?->current_organization_id,
                    fn($q, $orgId) => $q->forOrganization($orgId)
                )
                ->orderBy('name')
                ->get(),
            'organizations' => auth()->user()?->role === 'super_admin'
                ? \App\Models\Organization::select('id', 'name')->orderBy('name')->get()
                : null,
        ]);
    }

    /**
     * Update the specified course.
     */
    public function update(Request $request, Course $course)
    {
        $routePrefix = $this->getRoutePrefix();
        
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'year_group' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'thumbnail' => 'nullable|string',
            'category' => 'nullable|string|max:100',
            'level' => 'nullable|in:beginner,intermediate,advanced',
            'metadata' => 'nullable|array',
            'estimated_duration_minutes' => 'nullable|integer|min:0',
            'is_featured' => 'nullable|boolean',
            'status' => 'nullable|in:draft,review,live,archived',
            'journey_category_id' => 'nullable|exists:journey_categories,id',
            'is_global' => 'nullable|boolean',
            'organization_id' => 'nullable|integer|exists:organizations,id',
        ]);
        
        $user = $request->user();
        $isSuperAdmin = $user->role === 'super_admin';
        $isGlobal = $isSuperAdmin && $request->boolean('is_global');
        $organizationId = $request->input('organization_id');

        if (!$isSuperAdmin) {
            $organizationId = $user->current_organization_id;
            $isGlobal = false;
        }

        if ($isGlobal) {
            $organizationId = null;
        }

        if ($isSuperAdmin && !$isGlobal && !$organizationId) {
            throw ValidationException::withMessages([
                'organization_id' => 'Organization is required unless the course is global.',
            ]);
        }

        $validated['organization_id'] = $organizationId;
        $validated['is_global'] = $isGlobal;
        $validated['updated_by'] = $user->id;
        
        $course->update($validated);
        
        return redirect()
            ->route("{$routePrefix}.courses.show", $course)
            ->with('success', 'Course updated successfully.');
    }

    /**
     * Remove the specified course.
     */
    public function destroy(Course $course)
    {
        $routePrefix = $this->getRoutePrefix();
        
        $course->delete();
        
        return redirect()
            ->route("{$routePrefix}.courses.index")
            ->with('success', 'Course deleted successfully.');
    }

    /**
     * Publish the course.
     */
    public function publish(Course $course)
    {
        $routePrefix = $this->getRoutePrefix();
        
        $course->update(['status' => 'live']);
        $course->modules()->update(['status' => 'live']);
        
        return redirect()
            ->route("{$routePrefix}.courses.show", $course)
            ->with('success', 'Course published successfully.');
    }

    /**
     * Archive the course.
     */
    public function archive(Course $course)
    {
        $routePrefix = $this->getRoutePrefix();
        
        $course->update(['status' => 'archived']);
        
        return redirect()
            ->route("{$routePrefix}.courses.show", $course)
            ->with('success', 'Course archived successfully.');
    }

    /**
     * Duplicate the course.
     */
    public function duplicate(Course $course)
    {
        $routePrefix = $this->getRoutePrefix();
        
        $newCourse = $course->replicate();
        $newCourse->title = $course->title . ' (Copy)';
        $newCourse->status = 'draft';
        $newCourse->uid = 'CRS-' . strtoupper(Str::random(8));
        $newCourse->save();
        
        // Duplicate modules
        foreach ($course->modules as $module) {
            $newModule = $module->replicate();
            $newModule->course_id = $newCourse->id;
            $newModule->save();
            
            // Duplicate lessons
            foreach ($module->lessons as $lesson) {
                $newLesson = $lesson->replicate();
                $newLesson->module_id = $newModule->id;
                $newLesson->save();
                
                // Duplicate slides
                foreach ($lesson->slides as $slide) {
                    $newSlide = $slide->replicate();
                    $newSlide->lesson_id = $newLesson->id;
                    $newSlide->save();
                }
            }
        }
        
        return redirect()
            ->route("{$routePrefix}.courses.show", $newCourse)
            ->with('success', 'Course duplicated successfully.');
    }

    /**
     * Return modules for a course (JSON) - used by admin content lesson forms
     */
    public function modules(Course $course)
    {
        $modules = $course->modules()->select('id','title','order_position')->orderBy('order_position')->get();
        return response()->json(['modules' => $modules]);
    }
}
