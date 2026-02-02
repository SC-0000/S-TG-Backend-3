<?php

namespace App\Http\Controllers;

use App\Actions\SyncLessonService;
use App\Http\Requests\StoreServiceRequest;
use App\Models\Assessment;
use App\Models\Child;
use App\Models\LiveLessonSession;
use App\Models\Service;
use App\Models\Course;
use App\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ServiceController extends Controller
{
    /* ───────────────────────────── READ ───────────────────────────── */

    public function adminIndex(Request $request)
    {
        $user = Auth::user();
        $isSuperAdmin = $user?->role === 'super_admin';
        $orgId = $user?->current_organization_id;

        $query = Service::with('organization:id,name')->latest();

        if ($isSuperAdmin && $request->filled('organization_id')) {
            $query->visibleToOrg((int) $request->organization_id);
        } elseif (!$isSuperAdmin && $orgId) {
            $query->visibleToOrg($orgId);
        }

        $services = $query->get();

        $organizations = $isSuperAdmin
            ? \App\Models\Organization::select('id', 'name')->orderBy('name')->get()
            : null;

        return Inertia::render('@admin/Services/Index', [
            'services' => $services,
            'organizations' => $organizations,
            'filters' => $request->only(['organization_id']),
        ]);
    }

    public function portalIndex()
    {
        $user = Auth::user();
        $orgId = $user?->current_organization_id;

        return Inertia::render('@parent/Main/Services', [
            'services' => Service::withCount(['lessons', 'assessments'])
                                 ->visibleToOrg($orgId)
                                 ->latest()
                                 ->get(),
        ]);
    }

    public function index()
    {
        // Fetch all subscriptions
        $subscriptions = \App\Models\Subscription::all()->map(function ($subscription) {
            $filters = $subscription->content_filters ?? [];
            $yearGroups = $filters['year_groups'] ?? [];
            $features = $subscription->features ?? [];
            
            // Calculate course statistics based on year groups
            $totalCourses = 0;
            $avgCoursesPerGrade = 0;
            
            if (!empty($yearGroups)) {
                // Count published courses for each year group
                foreach ($yearGroups as $yearGroup) {
                    $count = \App\Models\Course::where('year_group', $yearGroup)
                        ->where('status', 'published')
                        ->count();
                    $totalCourses += $count;
                }
                
                // Calculate average courses per grade
                $avgCoursesPerGrade = round($totalCourses / count($yearGroups), 1);
            }
            
            $assessmentsCount = 0;
            $totalLessons = 0;
            
            // Check for individual AI features
            $hasAI = (isset($features['ai_tutoring']) && $features['ai_tutoring']) 
                  || (isset($features['ai']) && $features['ai'])
                  || (isset($features['ai_analysis']) && $features['ai_analysis']);
            
            $hasAIAnalysis = isset($features['ai_analysis']) && $features['ai_analysis'];
            $hasEnhancedReports = isset($features['enhanced_reports']) && $features['enhanced_reports'];
            
            return [
                'id' => $subscription->id,
                'name' => $subscription->name,
                'slug' => $subscription->slug,
                'description' => $subscription->description ?? 'Access assessments and resources',
                'year_groups' => $yearGroups,
                'has_ai' => $hasAI,
                'has_ai_analysis' => $hasAIAnalysis,
                'has_enhanced_reports' => $hasEnhancedReports,
                'stats' => [
                    'total_courses' => $totalCourses,
                    'avg_courses_per_grade' => $avgCoursesPerGrade,
                    'assessments_count' => $assessmentsCount,
                    'total_lessons' => $totalLessons,
                ],
            ];
        })->values();
        Log::info('Fetched Subscriptions for Public Index:', $subscriptions->toArray());
        return Inertia::render('@public/Services/Index', [
            'services' => Service::where('service_level', 'basic')
                                 ->global()
                                 ->withCount(['lessons', 'assessments'])
                                 ->latest()
                                 ->get(),
            'subscriptions' => $subscriptions,
        ]);
    }

    /**
     * Public service detail page (for guests/visitors)
     */
    public function publicShow(Service $service)
    {
        // Load service with relationships
        $service->load([
            'lessons:id,title,lesson_mode,start_time,end_time',
            'assessments:id,title,description,deadline',
        ]);

        // For flexible services, get available content with enrollment data
        $availableContent = null;
        if ($service->isFlexibleService()) {
            $availableContent = [
                'lessons' => $service->getAvailableLiveSessions()->map(function($session) {
                    // Get attached LiveLessonSession and its ContentLesson if they exist
                    $liveLessonSession = $session->liveLessonSession;
                    $contentLesson = $liveLessonSession?->contentLesson;
                    
                    return [
                        'id' => $session->id,
                        'title' => $contentLesson?->title ?? $session->title,
                        'description' => $contentLesson?->description ?? ($session->description ?? ''),
                        'lesson_mode' => $session->lesson_mode,
                        'start_time' => $session->start_time,
                        'end_time' => $session->end_time,
                        'current_enrollments' => $session->pivot->current_enrollments ?? 0,
                        'enrollment_limit' => $session->pivot->enrollment_limit,
                        'is_available' => $session->pivot->enrollment_limit === null || 
                                         ($session->pivot->current_enrollments ?? 0) < $session->pivot->enrollment_limit,
                        'categories' => [],
                        'live_session_info' => $liveLessonSession ? [
                            'status' => $liveLessonSession->status,
                            'session_code' => $liveLessonSession->session_code,
                            'scheduled_start_time' => $liveLessonSession->scheduled_start_time,
                            'uid' => $liveLessonSession->uid,
                        ] : null,
                    ];
                }),
                'assessments' => $service->getAvailableAssessments()->map(function($assessment) {
                    return [
                        'id' => $assessment->id,
                        'title' => $assessment->title,
                        'description' => $assessment->description,
                        'deadline' => $assessment->deadline,
                        'current_enrollments' => $assessment->pivot->current_enrollments ?? 0,
                        'enrollment_limit' => $assessment->pivot->enrollment_limit,
                        'is_available' => $assessment->pivot->enrollment_limit === null || 
                                         ($assessment->pivot->current_enrollments ?? 0) < $assessment->pivot->enrollment_limit,
                        'categories' => [], // Add categories if you have them
                    ];
                }),
            ];
        }

        return Inertia::render('@public/Services/ShowPublic', [
            'service' => $service,
            'availableContent' => $availableContent,
        ]);
    }

    /**
     * Parent portal service detail page
     */
    public function parentShow(Service $service)
    {
        $user = auth()->user();
        $orgId = $user?->role === 'super_admin'
            ? $user?->current_organization_id
            : $user?->current_organization_id;

        // Guard: ensure parents see only their org's services (unless org-less)
        if (
            !$service->is_global
            && $orgId
            && $service->organization_id
            && (int) $service->organization_id !== (int) $orgId
        ) {
            abort(404);
        }

        // Load service with relationships
        $service->load([
            'lessons:id,title,lesson_mode,start_time,end_time',
            'assessments:id,title,description,deadline',
            'course.modules.lessons:id,title,module_id',
            'course.modules.assessments:id,title,module_id',
        ]);

        // For flexible services, get available content with enrollment data
        $availableContent = null;
        if ($service->isFlexibleService()) {
            $availableContent = [
                'lessons' => $service->getAvailableLiveSessions()->map(function($session) {
                    // Get attached LiveLessonSession and its ContentLesson if they exist
                    $liveLessonSession = $session->liveLessonSession;
                    $contentLesson = $liveLessonSession?->contentLesson;
                    
                    return [
                        'id' => $session->id,
                        'title' => $contentLesson?->title ?? $session->title,
                        'description' => $contentLesson?->description ?? ($session->description ?? ''),
                        'lesson_mode' => $session->lesson_mode,
                        'start_time' => $session->start_time,
                        'end_time' => $session->end_time,
                        'current_enrollments' => $session->pivot->current_enrollments ?? 0,
                        'enrollment_limit' => $session->pivot->enrollment_limit,
                        'is_available' => $session->pivot->enrollment_limit === null || 
                                         ($session->pivot->current_enrollments ?? 0) < $session->pivot->enrollment_limit,
                        'categories' => [],
                        'live_session_status' => $liveLessonSession?->status ?? null,
                    ];
                }),
                'assessments' => $service->getAvailableAssessments()->map(function($assessment) {
                    return [
                        'id' => $assessment->id,
                        'title' => $assessment->title,
                        'description' => $assessment->description,
                        'deadline' => $assessment->deadline,
                        'current_enrollments' => $assessment->pivot->current_enrollments ?? 0,
                        'enrollment_limit' => $assessment->pivot->enrollment_limit,
                        'is_available' => $assessment->pivot->enrollment_limit === null || 
                                         ($assessment->pivot->current_enrollments ?? 0) < $assessment->pivot->enrollment_limit,
                        'categories' => [],
                    ];
                }),
            ];
        }

        // Build included content for non-flexible services (lessons/assessments/course)
        $includedContent = [
            'lessons' => $service->lessons->map(fn($lesson) => [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'lesson_mode' => $lesson->lesson_mode,
                'start_time' => $lesson->start_time,
                'end_time' => $lesson->end_time,
            ]),
            'assessments' => $service->assessments->map(fn($assessment) => [
                'id' => $assessment->id,
                'title' => $assessment->title,
                'description' => $assessment->description,
                'deadline' => $assessment->deadline,
            ]),
        ];

        // Course-specific info if this service is tied to a course
        $courseInfo = null;
        if ($service->course) {
            $course = $service->course;
            $courseInfo = [
                'id' => $course->id,
                'title' => $course->title,
                'description' => $course->description,
                'thumbnail' => $course->thumbnail,
                'modules' => $course->modules->map(function ($module) {
                    return [
                        'id' => $module->id,
                        'title' => $module->title,
                        'lessons' => $module->lessons->map(fn($l) => [
                            'id' => $l->id,
                            'title' => $l->title,
                        ]),
                        'assessments' => $module->assessments->map(fn($a) => [
                            'id' => $a->id,
                            'title' => $a->title,
                        ]),
                    ];
                }),
            ];
        }

        return Inertia::render('@parent/Services/Show', [
            'service' => $service,
            'availableContent' => $availableContent,
            'includedContent' => $includedContent,
            'courseInfo' => $courseInfo,
        ]);
    }

    /* ───────────────────────────── CREATE ─────────────────────────── */

    public function create(Request $request)
    {
        $user = Auth::user();
        $isSuperAdmin = $user?->role === 'super_admin';
        $orgId = $user?->current_organization_id;

        $preselected = [];
        if ($request->query('lesson_id')) {
            $preselected = [(int) $request->query('lesson_id')];
        }

        return Inertia::render('@admin/Services/CreateService', [
            'lessons'        => Lesson::select('id', 'title', 'lesson_mode', 'start_time', 'end_time', 'organization_id', 'is_global')
                                    ->when(!$isSuperAdmin && $orgId, fn($q) => $q->visibleToOrg($orgId))
                                    ->orderBy('title')
                                    ->get(),
            'assessments'    => Assessment::select('id', 'title', 'organization_id', 'is_global')
                                    ->when(!$isSuperAdmin && $orgId, fn($q) => $q->visibleToOrg($orgId))
                                    ->orderBy('title')
                                    ->get(),
            'courses'        => Course::select('id', 'title', 'description', 'organization_id', 'is_global')
                                     ->when(!$isSuperAdmin && $orgId, fn($q) => $q->visibleToOrg($orgId))
                                     ->orderBy('title')
                                     ->get(),
            'childrenByYear' => Child::select('id', 'child_name', 'year_group', 'organization_id')
                                     ->when(!$isSuperAdmin && $orgId, fn($q) => $q->where('organization_id', $orgId))
                                     ->orderBy('year_group')
                                     ->get()
                                     ->groupBy('year_group'),
            'preselected_lesson_ids' => $preselected,
            'organizations' => $isSuperAdmin
                ? \App\Models\Organization::select('id', 'name')->orderBy('name')->get()
                : null,
        ]);
    }

    public function store(StoreServiceRequest $request)
    {
        try {
            Log::info('=== Service Creation Started ===');
            Log::info('Request Data:', $request->all());
            Log::info('Request Type:', ['_type' => $request->_type]);
            $user = $request->user();
            $isSuperAdmin = $user?->role === 'super_admin';
            $isGlobal = $isSuperAdmin ? $request->boolean('is_global') : false;
            $organizationId = $isSuperAdmin
                ? ($isGlobal ? null : $request->input('organization_id', $user?->current_organization_id))
                : $user?->current_organization_id;
            
            DB::transaction(function () use ($request, $organizationId, $isGlobal) {

                /* ── 1. Persist the service ─────────────────────────────── */
                Log::info('Step 1: Creating service record');
                $payload = $this->payload($request);
                Log::info('Service Payload:', $payload);
                
                $service = Service::create(array_merge(
                    $payload,
                    [
                        'organization_id' => $organizationId,
                        'is_global' => $isGlobal,
                        'quantity_remaining' => $request->quantity_remaining
                            ?? $request->quantity,
                    ]
                ));
                
                Log::info('Service created successfully', [
                    'service_id' => $service->id,
                    'service_name' => $service->service_name,
                    'type' => $service->_type
                ]);

                /* ── 2. Pivot tables ────────────────────────────────────── */
                Log::info('Step 2: Syncing relations');
                $this->syncRelations($service, $request);
                Log::info('Relations synced successfully');

                /* ── 3. Upload media  ───────────────────────────────────── */
                if ($request->hasFile('media')) {
                    Log::info('Step 3: Handling media uploads');
                    $this->handleMediaUploads($service, $request);
                    Log::info('Media uploads completed');
                } else {
                    Log::info('Step 3: No media files to upload');
                }
                
                if ($isGlobal) {
                    $this->propagateGlobalContent($service, $request);
                }

                Log::info('=== Service Creation Completed Successfully ===', [
                    'service_id' => $service->id
                ]);
            });

            return redirect()
                ->route('services.admin.index')
                ->with('success', 'Service created successfully.');
                
        } catch (\Exception $e) {
            Log::error('=== Service Creation Failed ===', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->except(['media'])
            ]);
            
            throw $e;
        }
    }

    /* ───────────────────────────── SHOW ───────────────────────────── */

    /**
     * Get available content for flexible service with enrollment status
     */
    public function getAvailableContent(Service $service)
    {
        if (!$service->isFlexibleService()) {
            return response()->json([
                'error' => 'This service is not a flexible service'
            ], 400);
        }

        $liveSessions = $service->getAvailableLiveSessions()->map(function($session) {
            return [
                'id' => $session->id,
                'title' => $session->title,
                'lesson_mode' => $session->lesson_mode,
                'start_time' => $session->start_time,
                'end_time' => $session->end_time,
                'enrollment_status' => $session->enrollment_status,
                'current_enrollments' => $session->current_enrollments,
                'max_enrollments' => $session->max_enrollments,
                'is_available' => $session->is_available,
            ];
        });

        $assessments = $service->getAvailableAssessments()->map(function($assessment) {
            return [
                'id' => $assessment->id,
                'title' => $assessment->title,
                'description' => $assessment->description,
                'deadline' => $assessment->deadline,
                'enrollment_status' => $assessment->enrollment_status,
                'current_enrollments' => $assessment->current_enrollments,
                'max_enrollments' => $assessment->max_enrollments,
                'is_available' => $assessment->is_available,
            ];
        });

        return response()->json([
            'service' => [
                'id' => $service->id,
                'service_name' => $service->service_name,
                'selection_config' => $service->selection_config,
                'required_selections' => $service->getRequiredSelections(),
            ],
            'available_content' => [
                'live_sessions' => $liveSessions,
                'assessments' => $assessments,
            ],
        ]);
    }

    public function show(Service $service)
    {
        /*
        |------------------------------------------------------------------
        | 1. Eager-load relations – only columns that really exist!
        |------------------------------------------------------------------
        */
        $service->load([
            // Lessons from live_sessions table
            'lessons:id,title,lesson_mode,start_time,end_time',

            // ASSESSMENTS come from a pivot – no service_id column here
            'assessments:id,title,description,deadline',

            'children:id,child_name,year_group',
            
            // Course details if service is linked to a course
            'course:id,title,description,cover_image,thumbnail,status',
        ]);
        
        // If flexible service, include enrollment data
        $flexibleData = null;
        if ($service->isFlexibleService()) {
            $flexibleData = [
                'selection_config' => $service->selection_config,
                'required_selections' => $service->getRequiredSelections(),
                'available_live_sessions' => $service->getAvailableLiveSessions(),
                'available_assessments' => $service->getAvailableAssessments(),
            ];
        }
        
        Log::debug('Service loaded', ['service' => $service->toArray()]);

        /*
        |------------------------------------------------------------------
        | 2. Build a single chronological timeline (lessons + assessments)
        |------------------------------------------------------------------
        */
        $timeline = collect();

        foreach ($service->lessons as $lesson) {
            $timeline->push([
                'type'  => 'lesson',
                'id'    => $lesson->id,
                'title' => $lesson->title,
                'at'    => $lesson->start_time,
                'extra' => [
                    'end'  => $lesson->end_time,
                    'mode' => $lesson->lesson_mode,
                ],
            ]);
        }

        foreach ($service->assessments as $assessment) {
            $timeline->push([
                'type'  => 'assessment',
                'id'    => $assessment->id,
                'title' => $assessment->title,
                'at'    => $assessment->deadline,
                'extra' => [
                    'desc' => $assessment->description,
                ],
            ]);
        }

        $timeline = $timeline->sortBy('at')->values();

        /*
        |------------------------------------------------------------------
        | 3. Ship everything to Inertia
        |------------------------------------------------------------------
        */
        return Inertia::render('@admin/Services/Show', [
            'service'  => $service,
            'timeline' => $timeline,
            'flexibleData' => $flexibleData,
        ]);
    }
    

    /* ───────────────────────────── EDIT ───────────────────────────── */

    public function edit(Service $service)
    {
        $user = Auth::user();
        $isSuperAdmin = $user?->role === 'super_admin';
        $orgId = $user?->current_organization_id;

        $mismatchWarnings = $this->getOrgMismatchWarnings($service);

        return Inertia::render('@admin/Services/Edit', [
            'service'        => $service->load([
                'lessons:id,organization_id,is_global',
                'assessments:id,organization_id,is_global',
                'children:id',
                'course:id,title,description,organization_id,is_global',
            ]),
            'lessons'        => Lesson::select('id', 'title', 'lesson_mode', 'start_time', 'end_time', 'organization_id', 'is_global')
                                    ->when(!$isSuperAdmin && $orgId, fn($q) => $q->visibleToOrg($orgId))
                                    ->orderBy('title')
                                    ->get(),
            'assessments'    => Assessment::select('id', 'title', 'organization_id', 'is_global')
                                    ->when(!$isSuperAdmin && $orgId, fn($q) => $q->visibleToOrg($orgId))
                                    ->orderBy('title')
                                    ->get(),
            'courses'        => Course::select('id', 'title', 'description', 'organization_id', 'is_global')
                                     ->when(!$isSuperAdmin && $orgId, fn($q) => $q->visibleToOrg($orgId))
                                     ->orderBy('title')
                                     ->get(),
            'childrenByYear' => Child::select('id', 'child_name', 'year_group', 'organization_id')
                                     ->when(!$isSuperAdmin && $orgId, fn($q) => $q->where('organization_id', $orgId))
                                     ->orderBy('year_group')
                                     ->get()
                                     ->groupBy('year_group'),
            'organizations' => $isSuperAdmin
                ? \App\Models\Organization::select('id', 'name')->orderBy('name')->get()
                : null,
            'mismatch_warnings' => $mismatchWarnings,
        ]);
    }

    /* ───────────────────────────── UPDATE ─────────────────────────── */

    public function update(StoreServiceRequest $request, Service $service)
    {
        $user = $request->user();
        $isSuperAdmin = $user?->role === 'super_admin';
        $isGlobal = $isSuperAdmin ? $request->boolean('is_global') : false;
        $organizationId = $isSuperAdmin
            ? ($isGlobal ? null : $request->input('organization_id', $user?->current_organization_id))
            : $user?->current_organization_id;

        DB::transaction(function () use ($request, $service, $organizationId, $isGlobal) {

            /* ── 1. Update scalar columns ──────────────────────────── */
            $service->update($this->payload($request) + [
                'organization_id' => $organizationId,
                'is_global' => $isGlobal,
            ]);

            /* ── 2. Update pivots ──────────────────────────────────── */
            $this->syncRelations($service, $request);

            /* ── 3. Replace / append media  ────────────────────────── */
            $this->handleMediaUploads($service, $request, /* replace = */ true);

            if ($isGlobal) {
                $this->propagateGlobalContent($service, $request);
            }
        });

        return redirect()
            ->route('services.show', $service)
            ->with('success', 'Service updated successfully.');
    }

    /* ───────────────────────────── DELETE ─────────────────────────── */

    public function destroy(Service $service)
    {
        // Optionally, delete media files here if you want
        $service->delete();
        return back()->with('success', 'Service deleted.');
    }

    /* ───────────────────────────── Helpers ────────────────────────── */

    /**
     * Extract only the DB columns from the validated request.
     */
    private function payload(StoreServiceRequest $request): array
    {
        return $request->except([
            'lesson_ids',
            'assessment_ids',
            'child_ids',
            'media',
        ]);
    }

    /**
     * Sync lessons, assessments, and children (condition-based).
     */
    private function syncRelations(Service $service, StoreServiceRequest $request): void
    {
        Log::info('syncRelations: Starting', [
            'service_id' => $service->id,
            'service_type' => $service->_type
        ]);
        
        $lessonIds = $request->input('lesson_ids', []);
        $assessmentIds = $request->input('assessment_ids', []);
        $childIds = $request->input('child_ids', []);
        
        Log::info('syncRelations: Input data', [
            'lesson_ids' => $lessonIds,
            'assessment_ids' => $assessmentIds,
            'child_ids' => $childIds,
            'flexible_content' => $request->input('flexible_content', [])
        ]);

        // For flexible services, sync with enrollment limits
        if ($service->_type === 'flexible' && $request->has('flexible_content')) {
            $flexibleContent = $request->input('flexible_content', []);
            Log::info('syncRelations: Processing flexible service content', [
                'flexible_content_count' => count($flexibleContent)
            ]);
            
            // Prepare pivot data for lessons with enrollment limits
            $lessonPivotData = [];
            foreach ($flexibleContent as $content) {
                if ($content['type'] === 'lesson') {
                    $lessonPivotData[$content['id']] = [
                        'enrollment_limit' => $content['max_enrollments'] ?? null,
                        'current_enrollments' => 0
                    ];
                }
            }
            
            // Prepare pivot data for assessments with enrollment limits
            $assessmentPivotData = [];
            foreach ($flexibleContent as $content) {
                if ($content['type'] === 'assessment') {
                    $assessmentPivotData[$content['id']] = [
                        'enrollment_limit' => $content['max_enrollments'] ?? null,
                        'current_enrollments' => 0
                    ];
                }
            }
            
            Log::info('syncRelations: Syncing flexible content', [
                'lessons_to_sync' => count($lessonPivotData),
                'assessments_to_sync' => count($assessmentPivotData)
            ]);
            
            $service->lessons()->sync($lessonPivotData);
            $service->assessments()->sync($assessmentPivotData);
        } else {
            Log::info('syncRelations: Syncing standard service content');
            $service->lessons()->sync($lessonIds);
            $service->assessments()->sync($assessmentIds);
        }

        Log::info('syncRelations: Lessons and assessments synced');

        if ($service->restriction_type === 'Specific') {
            Log::info('syncRelations: Syncing specific children', [
                'child_count' => count($childIds)
            ]);
            $service->children()->sync($childIds);
        } else {
            Log::info('syncRelations: Detaching all children (not Specific restriction)');
            $service->children()->detach();
        }

        // keep lessons ↔ service relationship table in sync
        Log::info('syncRelations: Calling SyncLessonService action');
        app(SyncLessonService::class)($service->id, 
            $service->_type === 'flexible' 
                ? array_keys($lessonPivotData ?? [])
                : $lessonIds
        );
        
        Log::info('syncRelations: Completed successfully');
    }

    private function propagateGlobalContent(Service $service, StoreServiceRequest $request): void
    {
        $lessonIds = $request->input('lesson_ids', []);
        $assessmentIds = $request->input('assessment_ids', []);
        $courseId = $request->input('course_id');

        $flexibleContent = $request->input('flexible_content', []);
        if (!empty($flexibleContent)) {
            foreach ($flexibleContent as $content) {
                if (($content['type'] ?? null) === 'lesson') {
                    $lessonIds[] = $content['id'];
                }
                if (($content['type'] ?? null) === 'assessment') {
                    $assessmentIds[] = $content['id'];
                }
            }
        }

        $lessonIds = array_values(array_unique(array_filter($lessonIds)));
        $assessmentIds = array_values(array_unique(array_filter($assessmentIds)));

        if (!empty($lessonIds)) {
            Lesson::whereIn('id', $lessonIds)->update([
                'is_global' => true,
                'organization_id' => null,
            ]);
        }

        if (!empty($assessmentIds)) {
            Assessment::whereIn('id', $assessmentIds)->update([
                'is_global' => true,
                'organization_id' => null,
            ]);
        }

        if ($courseId) {
            $course = Course::find($courseId);
            if ($course) {
                $course->update([
                    'is_global' => true,
                    'organization_id' => null,
                ]);

                $courseAssessmentIds = $course->getAllAssessmentIds();
                if (!empty($courseAssessmentIds)) {
                    Assessment::whereIn('id', $courseAssessmentIds)->update([
                        'is_global' => true,
                        'organization_id' => null,
                    ]);
                }
            }
        }
    }

    private function getOrgMismatchWarnings(Service $service): array
    {
        if ($service->is_global) {
            return [];
        }

        $orgId = $service->organization_id;
        if (!$orgId) {
            return [];
        }

        $warnings = [];

        $lessonMismatches = $service->lessons()
            ->select(
                'live_sessions.id',
                'live_sessions.title',
                'live_sessions.organization_id',
                'live_sessions.is_global'
            )
            ->where(function ($q) use ($orgId) {
                $q->where('organization_id', '!=', $orgId)
                  ->orWhereNull('organization_id');
            })
            ->get();

        if ($lessonMismatches->isNotEmpty()) {
            $warnings[] = [
                'type' => 'lesson',
                'count' => $lessonMismatches->count(),
                'items' => $lessonMismatches->map(fn ($item) => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'organization_id' => $item->organization_id,
                    'is_global' => $item->is_global,
                ])->toArray(),
            ];
        }

        $assessmentMismatches = $service->assessments()
            ->select(
                'assessments.id',
                'assessments.title',
                'assessments.organization_id',
                'assessments.is_global'
            )
            ->where(function ($q) use ($orgId) {
                $q->where('organization_id', '!=', $orgId)
                  ->orWhereNull('organization_id');
            })
            ->get();

        if ($assessmentMismatches->isNotEmpty()) {
            $warnings[] = [
                'type' => 'assessment',
                'count' => $assessmentMismatches->count(),
                'items' => $assessmentMismatches->map(fn ($item) => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'organization_id' => $item->organization_id,
                    'is_global' => $item->is_global,
                ])->toArray(),
            ];
        }

        if ($service->course) {
            $course = $service->course;
            if ($course->organization_id !== $orgId || $course->is_global) {
                $warnings[] = [
                    'type' => 'course',
                    'count' => 1,
                    'items' => [[
                        'id' => $course->id,
                        'title' => $course->title,
                        'organization_id' => $course->organization_id,
                        'is_global' => $course->is_global,
                    ]],
                ];
            }
        }

        return $warnings;
    }

    /**
     * Handle file uploads; replace or merge with existing media JSON.
     */
    private function handleMediaUploads(
        Service $service,
        StoreServiceRequest $request,
        bool $replace = false
    ): void {
        if (! $request->hasFile('media')) return;

        // Store all uploaded files
        $newPaths = collect($request->file('media'))->map(
            fn ($file) => $file->store("service-media/{$service->id}", 'public')
        )->all();

        if ($replace) {
            // Optionally delete old files here:
            // Storage::disk('public')->delete($service->media);
            $service->media = $newPaths;
        } else {
            $service->media = array_merge($service->media ?? [], $newPaths);
        }

        $service->save();
    }
}
