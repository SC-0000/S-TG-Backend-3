<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\Course;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

class ModuleController extends Controller
{
    /**
     * Get the route prefix based on the authenticated user's role.
     */
    private function getRoutePrefix(): string
    {
        return Auth::user()->role === 'teacher' ? 'teacher' : 'admin';
    }

    /**
     * Display a listing of modules for a course.
     */
    public function index(Request $request, Course $course)
    {
        $modules = $course->modules()
            ->with('lessons')
            ->orderBy('order_position')
            ->get()
            ->map(function ($module) {
                return [
                    'id' => $module->id,
                    'uid' => $module->uid,
                    'title' => $module->title,
                    'description' => $module->description,
                    'order_position' => $module->order_position,
                    'status' => $module->status,
                    'lessons_count' => $module->lessons->count(),
                    'estimated_duration_minutes' => $module->estimated_duration_minutes,
                ];
            });
        
        return response()->json(['modules' => $modules]);
    }

    /**
     * Store a newly created module.
     */
    public function store(Request $request, Course $course)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'order_position' => 'nullable|integer|min:0',
            'metadata' => 'nullable|array',
        ]);
        
        $module = Module::create([
            'course_id' => $course->id,
            'organization_id' => $request->organization_id ?: Auth::user()->current_organization_id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'order_position' => $validated['order_position'] ?? $course->modules()->max('order_position') + 1,
            'status' => 'draft',
            'metadata' => $validated['metadata'] ?? [],
        ]);
        
        $routePrefix = $this->getRoutePrefix();
        
        return redirect()
            ->route("{$routePrefix}.courses.edit", $course)
            ->with('success', 'Module created successfully.');
    }

    /**
     * Display the specified module.
     */
    public function show(Module $module)
    {
        $module->load([
            'course',
            'lessons' => function ($query) {
                $query->orderBy('order_position');
            },
            'lessons.slides',
        ]);
        
        return Inertia::render('Admin/Modules/Show', [
            'module' => [
                'id' => $module->id,
                'uid' => $module->uid,
                'title' => $module->title,
                'description' => $module->description,
                'order_position' => $module->order_position,
                'status' => $module->status,
                'metadata' => $module->metadata,
                'estimated_duration_minutes' => $module->estimated_duration_minutes,
                'course' => [
                    'id' => $module->course->id,
                    'title' => $module->course->title,
                ],
                'lessons' => $module->lessons->map(function ($lesson) {
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
                    ];
                }),
            ],
        ]);
    }

    /**
     * Update the specified module.
     */
    public function update(Request $request, Module $module)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'order_position' => 'nullable|integer|min:0',
            'status' => 'nullable|in:draft,review,live,archived',
            'metadata' => 'nullable|array',
            'estimated_duration_minutes' => 'nullable|integer|min:0',
        ]);
        
        $module->update($validated);
        
        $routePrefix = $this->getRoutePrefix();
        
        return redirect()
            ->route("{$routePrefix}.courses.edit", $module->course_id)
            ->with('success', 'Module updated successfully.');
    }

    /**
     * Remove the specified module.
     */
    public function destroy(Module $module)
    {
        $courseId = $module->course_id;
        $module->delete();
        
        $routePrefix = $this->getRoutePrefix();
        
        return redirect()
            ->route("{$routePrefix}.courses.edit", $courseId)
            ->with('success', 'Module deleted successfully.');
    }

    /**
     * Reorder modules within a course.
     */
    public function reorder(Request $request, Course $course)
    {
        $validated = $request->validate([
            'module_ids' => 'required|array',
            'module_ids.*' => 'exists:modules,id',
        ]);
        
        foreach ($validated['module_ids'] as $index => $moduleId) {
            Module::where('id', $moduleId)
                ->where('course_id', $course->id)
                ->update(['order_position' => $index]);
        }
        
        return response()->json([
            'message' => 'Modules reordered successfully.',
        ]);
    }

    /**
     * Publish the module.
     */
    public function publish(Module $module)
    {
        $module->update(['status' => 'live']);
        $module->lessons()->update(['status' => 'live']);
        
        return response()->json([
            'message' => 'Module published successfully.',
        ]);
    }

    /**
     * Duplicate the module.
     */
    public function duplicate(Module $module)
    {
        $newModule = $module->replicate();
        $newModule->title = $module->title . ' (Copy)';
        $newModule->status = 'draft';
        $newModule->order_position = $module->course->modules()->max('order_position') + 1;
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
        
        return response()->json([
            'module' => $newModule,
            'message' => 'Module duplicated successfully.',
        ], 201);
    }

    /**
     * Create and attach a new lesson to the module.
     */
    public function storeLesson(Request $request, Module $module)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'grade' => 'nullable|string|max:50',
            'subject' => 'nullable|string|max:100',
            'tags' => 'nullable|string',
        ]);

        // Load course to get journey_category_id
        $module->load('course');

        // Create the lesson with metadata
        $lesson = \App\Models\ContentLesson::create([
            'organization_id' => Auth::user()->current_organization_id,
            'journey_category_id' => $module->course?->journey_category_id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'status' => 'draft',
            'metadata' => [
                'grade' => $validated['grade'] ?? null,
                'subject' => $validated['subject'] ?? null,
                'tags' => $validated['tags'] ?? null,
            ],
        ]);

        // Attach the lesson to the module
        $module->lessons()->attach($lesson->id, [
            'order_position' => $module->lessons()->count(),
        ]);

        return redirect()
            ->back()
            ->with('success', 'Lesson created and attached successfully.');
    }

    /**
     * Attach a lesson to the module.
     */
    public function attachLesson(Request $request, Module $module)
    {
        $validated = $request->validate([
            'lesson_id' => 'required|exists:new_lessons,id',
            'order_position' => 'nullable|integer|min:0',
        ]);

        // Check if already attached
        if ($module->lessons()->wherePivot('content_lesson_id', $validated['lesson_id'])->exists()) {
            return response()->json([
                'message' => 'Lesson already attached to this module.',
            ], 422);
        }

        $module->lessons()->attach($validated['lesson_id'], [
            'order_position' => $validated['order_position'] ?? $module->lessons()->count(),
        ]);

        return redirect()
            ->back()
            ->with('success', 'Lesson attached successfully.');
    }

    /**
     * Detach a lesson from the module.
     */
    public function detachLesson(Module $module, $lessonId)
    {
        $module->lessons()->detach($lessonId);

        $routePrefix = $this->getRoutePrefix();

        return redirect()
            ->route("{$routePrefix}.courses.edit", $module->course_id)
            ->with('success', 'Lesson detached successfully.');
    }

    /**
     * Attach an assessment to the module.
     */
    public function attachAssessment(Request $request, Module $module)
    {
        $validated = $request->validate([
            'assessment_id' => 'required|exists:assessments,id',
        ]);

        // Check if already attached
        if ($module->assessments()->where('assessment_id', $validated['assessment_id'])->exists()) {
            return response()->json([
                'message' => 'Assessment already attached to this module.',
            ], 422);
        }

        $module->assessments()->attach($validated['assessment_id']);

        return redirect()
            ->back()
            ->with('success', 'Assessment attached successfully.');
    }

    /**
     * Detach an assessment from the module.
     */
    public function detachAssessment(Module $module, $assessmentId)
    {
        $module->assessments()->detach($assessmentId);

        return redirect()
            ->back()
            ->with('success', 'Assessment detached successfully.');
    }
}
