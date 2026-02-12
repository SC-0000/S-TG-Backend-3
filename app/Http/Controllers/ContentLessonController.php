<?php

namespace App\Http\Controllers;

use App\Models\ContentLesson;
use App\Models\Module;
use App\Models\Course;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

class ContentLessonController extends Controller
{
    /**
     * Admin index: list all content lessons (standalone)
     */
    public function adminIndex(Request $request)
    {
        $user = Auth::user();
        
        $query = ContentLesson::with(['modules.course','slides','organization']);
        
        // Organization filtering
        if ($user->role === 'super_admin') {
            // Super Admin: Can see all lessons, with optional organization filter
            if ($request->has('organization_id') && $request->organization_id) {
                $query->where('organization_id', $request->organization_id);
            }
            // Otherwise show ALL lessons
        } else {
            // Admin: Only their organization's lessons
            $query->where('organization_id', $user->current_organization_id);
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
        
        $paginator = $query->orderBy('created_at','desc')->paginate(15);

        // Transform each lesson into a simple payload including the primary module (if any)
        $paginator->getCollection()->transform(function ($lesson) use ($user) {
            $primary = $lesson->modules->first();

            return [
                'id' => $lesson->id,
                'uid' => $lesson->uid,
                'title' => $lesson->title,
                'description' => $lesson->description,
                'order_position' => $lesson->order_position,
                'lesson_type' => $lesson->lesson_type,
                'delivery_mode' => $lesson->delivery_mode,
                'status' => $lesson->status,
                'slides_count' => $lesson->slides->count(),
                'estimated_minutes' => $lesson->estimated_minutes,
                'enable_ai_help' => $lesson->enable_ai_help,
                'enable_tts' => $lesson->enable_tts,
                'organization' => $lesson->organization ? [
                    'id' => $lesson->organization->id,
                    'name' => $lesson->organization->name,
                ] : null,
                'module' => $primary ? [
                    'id' => $primary->id,
                    'title' => $primary->title,
                    'course' => $primary->course ? [
                        'id' => $primary->course->id,
                        'title' => $primary->course->title,
                    ] : null,
                ] : null,
                'created_by_me' => $lesson->created_by === $user->id,
            ];
        });
        
        // Get organizations list ONLY for super admin
        $organizations = $user->role === 'super_admin' 
            ? \App\Models\Organization::select('id', 'name')->orderBy('name')->get()
            : null;

        return Inertia::render('@admin/ContentManagement/Lessons/Index', [
            'lessons' => $paginator,
            'organizations' => $organizations,
            'filters' => $request->only(['status', 'search', 'organization_id']),
        ]);
    }

    /**
     * Teacher index: list content lessons for teacher's organization
     */
    public function teacherIndex(Request $request)
    {
        $user = Auth::user();
        
        $paginator = ContentLesson::with(['modules.course','slides'])
            ->when($user->current_organization_id, function($query) use ($user) {
                $query->where('organization_id', $user->current_organization_id);
            })
            ->orderBy('created_at','desc')
            ->paginate(15);

        // Transform each lesson into a simple payload including the primary module (if any)
        $paginator->getCollection()->transform(function ($lesson) {
            $primary = $lesson->modules->first();

            return [
                'id' => $lesson->id,
                'uid' => $lesson->uid,
                'title' => $lesson->title,
                'description' => $lesson->description,
                'order_position' => $lesson->order_position,
                'lesson_type' => $lesson->lesson_type,
                'delivery_mode' => $lesson->delivery_mode,
                'status' => $lesson->status,
                'slides_count' => $lesson->slides->count(),
                'estimated_minutes' => $lesson->estimated_minutes,
                'enable_ai_help' => $lesson->enable_ai_help,
                'enable_tts' => $lesson->enable_tts,
                'module' => $primary ? [
                    'id' => $primary->id,
                    'title' => $primary->title,
                    'course' => $primary->course ? [
                        'id' => $primary->course->id,
                        'title' => $primary->course->title,
                    ] : null,
                ] : null,
            ];
        });

        return Inertia::render('@admin/ContentManagement/Lessons/Index', [
            'lessons' => $paginator,
        ]);
    }

    /**
     * Show form to create a standalone content lesson (optional attach to course/module)
     */
    public function create()
    {
        $courses = Course::select('id','title')->orderBy('title')->get();
        $user = auth()->user();
        $orgId = $user?->role === 'super_admin' ? null : $user?->current_organization_id;
        $journeyCategories = \App\Models\JourneyCategory::with('journey')
            ->when($orgId, fn($q) => $q->forOrganization($orgId))
            ->orderBy('name')
            ->get();

        return Inertia::render('@admin/ContentManagement/Lessons/Create', [
            'courses' => $courses,
            'modules' => [],
            'journeyCategories' => $journeyCategories,
            'routePrefix' => auth()->user()->role === 'teacher' ? 'teacher' : 'admin',
        ]);
    }

    /**
     * Show edit form for a standalone content lesson (admin).
     * Reuses the same data shape as the create page and provides modules
     * for the lesson's primary course so the module dropdown can be prefilled.
     */
    public function editForm(ContentLesson $lesson)
    {
        $lesson->load(['modules.course', 'slides']);

        $primaryModule = $lesson->modules->first();
        $courses = Course::select('id','title')->orderBy('title')->get();
        $user = auth()->user();
        $orgId = $user?->role === 'super_admin' ? null : $user?->current_organization_id;
        $journeyCategories = \App\Models\JourneyCategory::with('journey')
            ->when($orgId, fn($q) => $q->forOrganization($orgId))
            ->orderBy('name')
            ->get();
        $modules = [];

        if ($primaryModule) {
            // load modules for that course
            $modules = Module::where('course_id', $primaryModule->course_id)
                ->select('id','title')->orderBy('order_position')->get();
        }

        return Inertia::render('@admin/ContentManagement/Lessons/Edit', [
            'lesson' => $lesson,
            'courses' => $courses,
            'modules' => $modules,
            'journeyCategories' => $journeyCategories,
        ]);
    }

    /**
     * Store a newly created standalone content lesson (admin)
     */
    public function storeAdmin(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'year_group' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'order_position' => 'nullable|integer|min:0',
            'lesson_type' => 'nullable|in:interactive,video,reading,practice,assessment',
            'delivery_mode' => 'nullable|in:self_paced,live_interactive,hybrid',
            'estimated_minutes' => 'nullable|integer|min:0',
            'metadata' => 'nullable|array',
            'completion_rules' => 'nullable|array',
            'enable_ai_help' => 'nullable|boolean',
            'enable_tts' => 'nullable|boolean',
            'course_id' => 'nullable|exists:courses,id',
            'module_id' => 'nullable|exists:modules,id',
            'journey_category_id' => 'nullable|exists:journey_categories,id',
        ]);

        // If module_id provided verify it belongs to the provided course_id (if course_id present)
        if (!empty($validated['module_id']) && !empty($validated['course_id'])) {
            $module = Module::find($validated['module_id']);
            if (!$module || $module->course_id != $validated['course_id']) {
                return redirect()->back()->withErrors(['module_id' => 'Selected module does not belong to the chosen course.'])->withInput();
            }
        }

        $lesson = ContentLesson::create([
            'organization_id' => $request->organization_id ?: Auth::user()->current_organization_id ?? null,
            'journey_category_id' => $validated['journey_category_id'] ?? null,
            'title' => $validated['title'],
            'year_group' => $validated['year_group'] ?? null,
            'description' => $validated['description'] ?? null,
            'order_position' => $validated['order_position'] ?? 0,
            'lesson_type' => $validated['lesson_type'] ?? 'interactive',
            'delivery_mode' => $validated['delivery_mode'] ?? 'self_paced',
            'status' => 'draft',
            'estimated_minutes' => $validated['estimated_minutes'] ?? 0,
            'metadata' => $validated['metadata'] ?? [],
            'completion_rules' => $validated['completion_rules'] ?? [],
            'enable_ai_help' => $validated['enable_ai_help'] ?? true,
            'enable_tts' => $validated['enable_tts'] ?? true,
        ]);

        // Attach to module pivot if module chosen
        if (!empty($validated['module_id'])) {
            $lesson->modules()->attach($validated['module_id'], [
                'order_position' => $validated['order_position'] ?? 0,
            ]);
        }

        $prefix = auth()->user()->role === 'teacher' ? 'teacher' : 'admin';

        return redirect()
            ->route("{$prefix}.content-lessons.show", $lesson->id)
            ->with('success', 'Content lesson created successfully.');
    }

    /**
     * Display a listing of lessons for a module.
     */
    public function index(Request $request, Module $module)
    {
        
        $lessons = $module->lessons()
            ->with('slides')
            ->orderBy('order_position')
            ->get()
            ->map(function ($lesson) {
                return [
                    'id' => $lesson->id,
                    'uid' => $lesson->uid,
                    'title' => $lesson->title,
                    'description' => $lesson->description,
                    'order_position' => $lesson->order_position,
                    'lesson_type' => $lesson->lesson_type,
                    'delivery_mode' => $lesson->delivery_mode,
                    'status' => $lesson->status,
                    'slides_count' => $lesson->slides->count(),
                    'estimated_minutes' => $lesson->estimated_minutes,
                    'enable_ai_help' => $lesson->enable_ai_help,
                    'enable_tts' => $lesson->enable_tts,
                ];
            });
        
        return response()->json(['lessons' => $lessons]);
    }

    /**
     * Store a newly created lesson.
     */
    public function store(Request $request, Module $module)
    {
        
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'year_group' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'order_position' => 'nullable|integer|min:0',
            'lesson_type' => 'nullable|in:interactive,video,reading,practice,assessment',
            'delivery_mode' => 'nullable|in:self_paced,live_interactive,hybrid',
            'estimated_minutes' => 'nullable|integer|min:0',
            'metadata' => 'nullable|array',
            'completion_rules' => 'nullable|array',
            'enable_ai_help' => 'nullable|boolean',
            'enable_tts' => 'nullable|boolean',
        ]);
        
        // Load course to get journey_category_id
        $module->load('course');
        
        // Debug logging
        \Log::info('ContentLesson Store - Module ID: ' . $module->id);
        \Log::info('ContentLesson Store - Module course_id: ' . $module->course_id);
        \Log::info('ContentLesson Store - Course loaded: ' . ($module->course ? 'YES' : 'NO'));
        if ($module->course) {
            \Log::info('ContentLesson Store - Course ID: ' . $module->course->id);
            \Log::info('ContentLesson Store - Course journey_category_id: ' . ($module->course->journey_category_id ?? 'NULL'));
        }
        
        $journeyCategoryId = $module->course?->journey_category_id;
        \Log::info('ContentLesson Store - Assigning journey_category_id: ' . ($journeyCategoryId ?? 'NULL'));
        
        $lesson = ContentLesson::create([
            'module_id' => $module->id,
            'organization_id' => $request->organization_id ?: Auth::user()->current_organization_id,
            'journey_category_id' => $journeyCategoryId,
            'title' => $validated['title'],
            'year_group' => $validated['year_group'] ?? null,
            'description' => $validated['description'] ?? null,
            'order_position' => $validated['order_position'] ?? (($module->lessons()->max('content_lesson_module.order_position') ?? 0) + 1),
            'lesson_type' => $validated['lesson_type'] ?? 'interactive',
            'delivery_mode' => $validated['delivery_mode'] ?? 'self_paced',
            'status' => 'draft',
            'estimated_minutes' => $validated['estimated_minutes'] ?? 0,
            'metadata' => $validated['metadata'] ?? [],
            'completion_rules' => $validated['completion_rules'] ?? [],
            'enable_ai_help' => $validated['enable_ai_help'] ?? true,
            'enable_tts' => $validated['enable_tts'] ?? true,
        ]);
        
        \Log::info('ContentLesson Store - Created lesson ID: ' . $lesson->id . ' with journey_category_id: ' . ($lesson->journey_category_id ?? 'NULL'));
        
        return redirect()
            ->route('admin.courses.edit', $module->course_id)
            ->with('success', 'Lesson created successfully.');
    }

    /**
     * Show the slide editor for editing a lesson.
     */
    public function edit(ContentLesson $lesson)
    {
        $lesson->load([
            'modules.course',
            'slides' => function ($query) {
                $query->orderBy('order_position');
            },
        ]);
        
        $primaryModule = $lesson->modules->first();
        
        return Inertia::render('@admin/ContentManagement/Lessons/SlideEditor', [
            'lesson' => [
                'id' => $lesson->id,
                'uid' => $lesson->uid,
                'title' => $lesson->title,
                'description' => $lesson->description,
                'lesson_type' => $lesson->lesson_type,
                'delivery_mode' => $lesson->delivery_mode,
                'status' => $lesson->status,
                'estimated_minutes' => $lesson->estimated_minutes,
                'enable_ai_help' => $lesson->enable_ai_help,
                'enable_tts' => $lesson->enable_tts,
                'module' => $primaryModule ? [
                    'id' => $primaryModule->id,
                    'title' => $primaryModule->title,
                    'course_id' => $primaryModule->course_id,
                ] : null,
            ],
            'slides' => $lesson->slides->map(function ($slide) {
                return [
                    'id' => $slide->id,
                    'uid' => $slide->uid,
                    'title' => $slide->title,
                    'order_position' => $slide->order_position,
                    'blocks' => $slide->blocks ?? [],
                    'estimated_seconds' => $slide->estimated_seconds,
                    'auto_advance' => $slide->auto_advance,
                ];
            })->values()->toArray(),
        ]);
    }

    /**
     * Display the specified lesson.
     */
    public function show(ContentLesson $lesson)
    {
        
        $lesson->load([
            'modules.course.journeyCategory.journey',
            'journeyCategory.journey',
            'slides' => function ($query) {
                $query->orderBy('order_position');
            },
            'assessments',
        ]);
        
        $primaryModule = $lesson->modules->first();
        
        return Inertia::render('@admin/ContentManagement/Lessons/Show', [
            'lesson' => [
                'id' => $lesson->id,
                'uid' => $lesson->uid,
                'title' => $lesson->title,
                'description' => $lesson->description,
                'order_position' => $lesson->order_position,
                'lesson_type' => $lesson->lesson_type,
                'delivery_mode' => $lesson->delivery_mode,
                'status' => $lesson->status,
                'estimated_minutes' => $lesson->estimated_minutes,
                'metadata' => $lesson->metadata,
                'completion_rules' => $lesson->completion_rules,
                'enable_ai_help' => $lesson->enable_ai_help,
                'enable_tts' => $lesson->enable_tts,
                'created_at' => $lesson->created_at?->format('M d, Y'),
                'updated_at' => $lesson->updated_at?->format('M d, Y'),
                'is_standalone' => $lesson->modules->isEmpty(),
                'journey_category' => $lesson->journeyCategory ? [
                    'id' => $lesson->journeyCategory->id,
                    'name' => $lesson->journeyCategory->name,
                    'journey' => $lesson->journeyCategory->journey ? [
                        'id' => $lesson->journeyCategory->journey->id,
                        'name' => $lesson->journeyCategory->journey->name,
                    ] : null,
                ] : null,
                'module' => $primaryModule ? [
                    'id' => $primaryModule->id,
                    'title' => $primaryModule->title,
                    'course' => $primaryModule->course ? [
                        'id' => $primaryModule->course->id,
                        'title' => $primaryModule->course->title,
                        'journey_category' => $primaryModule->course->journeyCategory ? [
                            'id' => $primaryModule->course->journeyCategory->id,
                            'name' => $primaryModule->course->journeyCategory->name,
                            'journey' => $primaryModule->course->journeyCategory->journey ? [
                                'id' => $primaryModule->course->journeyCategory->journey->id,
                                'name' => $primaryModule->course->journeyCategory->journey->name,
                            ] : null,
                        ] : null,
                    ] : null,
                ] : null,
                'slides_count' => $lesson->slides->count(),
                'slides' => $lesson->slides->map(function ($slide) {
                    return [
                        'id' => $slide->id,
                        'uid' => $slide->uid,
                        'title' => $slide->title,
                        'order_position' => $slide->order_position,
                        'blocks' => $slide->blocks,
                        'estimated_seconds' => $slide->estimated_seconds,
                        'auto_advance' => $slide->auto_advance,
                    ];
                }),
                'assessments' => $lesson->assessments->map(function ($assessment) {
                    return [
                        'id' => $assessment->id,
                        'title' => $assessment->title,
                        'timing' => $assessment->pivot->timing,
                        'order_position' => $assessment->pivot->order_position,
                    ];
                }),
            ],
        ]);
    }

    /**
     * Update the specified lesson.
     */
    public function update(Request $request, ContentLesson $lesson)
    {
        
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'year_group' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'order_position' => 'nullable|integer|min:0',
            'lesson_type' => 'nullable|in:interactive,video,reading,practice,assessment',
            'delivery_mode' => 'nullable|in:self_paced,live_interactive,hybrid',
            'status' => 'nullable|in:draft,review,live,archived',
            'estimated_minutes' => 'nullable|integer|min:0',
            'metadata' => 'nullable|array',
            'completion_rules' => 'nullable|array',
            'enable_ai_help' => 'nullable|boolean',
            'enable_tts' => 'nullable|boolean',
            'journey_category_id' => 'nullable|exists:journey_categories,id',
            'course_id' => 'nullable|exists:courses,id',
            'module_id' => 'nullable|exists:modules,id',
        ]);
        
        // If module_id provided verify it belongs to the provided course_id (if course_id present)
        if (!empty($validated['module_id']) && !empty($validated['course_id'])) {
            $module = Module::find($validated['module_id']);
            if (!$module || $module->course_id != $validated['course_id']) {
                return redirect()->back()->withErrors(['module_id' => 'Selected module does not belong to the chosen course.'])->withInput();
            }
        }
        
        // Update basic fields
        $lesson->update([
            'title' => $validated['title'],
            'year_group' => $validated['year_group'] ?? $lesson->year_group,
            'description' => $validated['description'] ?? $lesson->description,
            'order_position' => $validated['order_position'] ?? $lesson->order_position,
            'lesson_type' => $validated['lesson_type'] ?? $lesson->lesson_type,
            'delivery_mode' => $validated['delivery_mode'] ?? $lesson->delivery_mode,
            'status' => $validated['status'] ?? $lesson->status,
            'estimated_minutes' => $validated['estimated_minutes'] ?? $lesson->estimated_minutes,
            'metadata' => $validated['metadata'] ?? $lesson->metadata,
            'completion_rules' => $validated['completion_rules'] ?? $lesson->completion_rules,
            'enable_ai_help' => $validated['enable_ai_help'] ?? $lesson->enable_ai_help,
            'enable_tts' => $validated['enable_tts'] ?? $lesson->enable_tts,
            'journey_category_id' => $validated['journey_category_id'] ?? $lesson->journey_category_id,
        ]);
        
        // Handle module attachment changes
        if (isset($validated['module_id'])) {
            // Detach from all modules first
            $lesson->modules()->detach();
            
            // Attach to new module
            $lesson->modules()->attach($validated['module_id'], [
                'order_position' => $validated['order_position'] ?? 0,
            ]);
        }
        
        $primaryModule = $lesson->modules()->first();
        $routePrefix = Auth::user()?->role === 'teacher' ? 'teacher.' : 'admin.';
        
        // If lesson is part of a course, redirect to course edit page
        if ($primaryModule && $primaryModule->course_id) {
            return redirect()
                ->route($routePrefix . 'courses.edit', $primaryModule->course_id)
                ->with('success', 'Lesson updated successfully.');
        }
        
        // Otherwise, redirect to standalone lesson show page
        return redirect()
            ->route($routePrefix . 'content-lessons.show', $lesson->id)
            ->with('success', 'Lesson updated successfully.');
    }

    /**
     * Remove the specified lesson.
     */
    public function destroy(ContentLesson $lesson)
    {
        
        $primaryModule = $lesson->modules()->first();
        $courseId = $primaryModule ? $primaryModule->course_id : null;
        $lesson->delete();
        $routePrefix = Auth::user()?->role === 'teacher' ? 'teacher.' : 'admin.';
        
        // If lesson was part of a course, redirect to course edit page
        if ($courseId) {
            return redirect()
                ->route($routePrefix . 'courses.edit', $courseId)
                ->with('success', 'Lesson deleted successfully.');
        }
        
        // Otherwise, redirect to standalone lessons index
        return redirect()
            ->route($routePrefix . 'content-lessons.index')
            ->with('success', 'Lesson deleted successfully.');
    }

    /**
     * Reorder lessons within a module.
     */
    public function reorder(Request $request, Module $module)
    {
        
        $validated = $request->validate([
            'lesson_ids' => 'required|array',
            'lesson_ids.*' => 'exists:new_lessons,id',
        ]);
        
        foreach ($validated['lesson_ids'] as $index => $lessonId) {
            ContentLesson::where('id', $lessonId)
                ->where('module_id', $module->id)
                ->update(['order_position' => $index]);
        }
        
        return response()->json([
            'message' => 'Lessons reordered successfully.',
        ]);
    }

    /**
     * Publish the lesson.
     */
    public function publish(ContentLesson $lesson)
    {
        
        $lesson->update(['status' => 'live']);
        
        return response()->json([
            'message' => 'Lesson published successfully.',
        ]);
    }

    /**
     * Duplicate the lesson.
     */
    public function duplicate(ContentLesson $lesson)
    {
        
        $newLesson = $lesson->replicate();
        $newLesson->title = $lesson->title . ' (Copy)';
        $newLesson->status = 'draft';
        
        $primaryModule = $lesson->modules()->first();
        $newLesson->order_position = ($primaryModule->lessons()->max('content_lesson_module.order_position') ?? 0) + 1;
        $newLesson->save();
        
        // Attach to the same modules
        foreach ($lesson->modules as $module) {
            $newLesson->modules()->attach($module->id, [
                'order_position' => $newLesson->order_position
            ]);
        }
        
        // Duplicate slides
        foreach ($lesson->slides as $slide) {
            $newSlide = $slide->replicate();
            $newSlide->lesson_id = $newLesson->id;
            $newSlide->save();
        }
        
        return response()->json([
            'lesson' => $newLesson,
            'message' => 'Lesson duplicated successfully.',
        ], 201);
    }

    /**
     * Attach an assessment to the lesson.
     */
    public function attachAssessment(Request $request, ContentLesson $lesson)
    {
        
        $validated = $request->validate([
            'assessment_id' => 'required|exists:assessments,id',
            'order_position' => 'nullable|integer|min:0',
            'timing' => 'nullable|in:inline,end_of_lesson,optional',
        ]);
        
        $lesson->assessments()->attach($validated['assessment_id'], [
            'order_position' => $validated['order_position'] ?? 0,
            'timing' => $validated['timing'] ?? 'end_of_lesson',
        ]);
        
        return response()->json([
            'message' => 'Assessment attached successfully.',
        ]);
    }

    /**
     * Detach an assessment from the lesson.
     */
    public function detachAssessment(ContentLesson $lesson, $assessmentId)
    {
        
        $lesson->assessments()->detach($assessmentId);
        
        return response()->json([
            'message' => 'Assessment detached successfully.',
        ]);
    }
}
