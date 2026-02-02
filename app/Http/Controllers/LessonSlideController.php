<?php

namespace App\Http\Controllers;

use App\Models\LessonSlide;
use App\Models\ContentLesson;
use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class LessonSlideController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of slides for a lesson.
     * - If request expects JSON (AJAX/API) return JSON payload
     * - Otherwise render the SlideEditor Inertia page so the admin can manage slides
     */
    public function index(ContentLesson $lesson)
    {
        // $this->authorize('view', $lesson);
        
        // eager load slides ordered
        $lesson->load(['slides' => function ($q) {
            $q->orderBy('order_position');
        }, 'modules.course']);

        // If the caller expects JSON (API / fetch), return JSON
        if (request()->wantsJson() || request()->ajax()) {
            $slides = $lesson->slides->map(function ($slide) {
                return [
                    'id' => $slide->id,
                    'uid' => $slide->uid,
                    'title' => $slide->title,
                    'order_position' => $slide->order_position,
                    'blocks_count' => count($slide->blocks ?? []),
                    'estimated_seconds' => $slide->estimated_seconds,
                    'auto_advance' => $slide->auto_advance,
                    'template_id' => $slide->template_id,
                ];
            });

            return response()->json(['slides' => $slides]);
        }

        // Otherwise render the slide editor Inertia page (admin UI)
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
     * Store a newly created slide.
     */
    public function store(Request $request, ContentLesson $lesson)
    {
        // $this->authorize('update', $lesson);
        
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'order_position' => 'nullable|integer|min:0',
            'blocks' => 'nullable|array',
            'template_id' => 'nullable|string',
            'layout_settings' => 'nullable|array',
            'teacher_notes' => 'nullable|string',
            'estimated_seconds' => 'nullable|integer|min:0',
            'auto_advance' => 'nullable|boolean',
            'min_time_seconds' => 'nullable|integer|min:0',
            'settings' => 'nullable|array',
        ]);
        
        $slide = LessonSlide::create([
            'lesson_id' => $lesson->id,
            'title' => $validated['title'],
            'order_position' => $validated['order_position'] ?? $lesson->slides()->max('order_position') + 1,
            'blocks' => $validated['blocks'] ?? [],
            'template_id' => $validated['template_id'] ?? null,
            'layout_settings' => $validated['layout_settings'] ?? [],
            'teacher_notes' => $validated['teacher_notes'] ?? null,
            'estimated_seconds' => $validated['estimated_seconds'] ?? 60,
            'auto_advance' => $validated['auto_advance'] ?? false,
            'min_time_seconds' => $validated['min_time_seconds'] ?? null,
            'settings' => $validated['settings'] ?? [],
        ]);

        

        $prefix = auth()->user()?->role === 'teacher'
            ? 'teacher.content-lessons.edit'
            : 'admin.content-lessons.edit';
        
        return redirect()
            ->route($prefix, $lesson->id)
            ->with('success', 'Slide created successfully.');
    }

    /**
     * Display the specified slide.
     */
    public function show(LessonSlide $slide)
    {
        // $this->authorize('view', $slide->lesson);
        
        // Load questions for QuestionBlocks
        $blocks = $slide->blocks;
        foreach ($blocks as &$block) {
            if ($block['type'] === 'QuestionBlock') {
                $questionIds = $block['content']['question_ids'] ?? [];
                $block['content']['questions'] = Question::whereIn('id', $questionIds)
                    ->get()
                    ->map(function ($question) {
                        return [
                            'id' => $question->id,
                            'question_text' => $question->question_text,
                            'question_type' => $question->question_type,
                            'options' => $question->options,
                            'marks' => $question->marks,
                        ];
                    });
            }
        }
        
        return response()->json([
            'slide' => array_merge($slide->toArray(), ['blocks' => $blocks]),
        ]);
    }

    /**
     * Update the specified slide.
     */
    public function update(Request $request, LessonSlide $slide)
    {
        // $this->authorize('update', $slide->lesson);
        
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'order_position' => 'nullable|integer|min:0',
            'blocks' => 'nullable|array',
            'template_id' => 'nullable|string',
            'layout_settings' => 'nullable|array',
            'teacher_notes' => 'nullable|string',
            'estimated_seconds' => 'nullable|integer|min:0',
            'auto_advance' => 'nullable|boolean',
            'min_time_seconds' => 'nullable|integer|min:0',
            'settings' => 'nullable|array',
        ]);
        
        $slide->update($validated);
        
        return redirect()
            ->back()
            ->with('success', 'Slide updated successfully.');
    }

    /**
     * Remove the specified slide.
     */
    public function destroy(LessonSlide $slide)
    {
        // $this->authorize('delete', $slide->lesson);
        
        $lessonId = $slide->lesson_id;
        $slide->delete();

       

        $prefix = auth()->user()?->role === 'teacher'
            ? 'teacher.content-lessons.edit'
            : 'admin.content-lessons.edit';
        
        return redirect()
            ->route($prefix, $lessonId)
            ->with('success', 'Slide deleted successfully.');
    }

    /**
     * Reorder slides within a lesson.
     */
    public function reorder(Request $request, ContentLesson $lesson)
    {
        // $this->authorize('update', $lesson);
        
        $validated = $request->validate([
            'slide_ids' => 'required|array',
            'slide_ids.*' => 'exists:lesson_slides,id',
        ]);
        
        foreach ($validated['slide_ids'] as $index => $slideId) {
            LessonSlide::where('id', $slideId)
                ->where('lesson_id', $lesson->id)
                ->update(['order_position' => $index]);
        }
        
        return response()->json([
            'message' => 'Slides reordered successfully.',
        ]);
    }

    /**
     * Add a block to a slide.
     */
    public function addBlock(Request $request, LessonSlide $slide)
    {
        // $this->authorize('update', $slide->lesson);
        
        $validated = $request->validate([
            'type' => 'required|string|in:text,image,video,audio,callout,question,upload,QuestionBlock,UploadBlock,embed,timer,reflection,whiteboard,code,table,divider',
            'content' => 'required|array',
            'settings' => 'nullable|array',
            'order' => 'nullable|integer|min:0',
        ]);
        
        $blocks = $slide->blocks ?? [];
        
        $newBlock = [
            'id' => Str::uuid()->toString(),
            'type' => $validated['type'],
            'order' => $validated['order'] ?? count($blocks),
            'content' => $validated['content'],
            'settings' => $validated['settings'] ?? [
                'visible' => true,
                'locked' => false,
            ],
            'metadata' => [
                'created_at' => now()->toISOString(),
                'ai_generated' => false,
                'version' => 1,
            ],
        ];
        
        $blocks[] = $newBlock;
        
        // Re-sort blocks by order
        usort($blocks, function ($a, $b) {
            return $a['order'] <=> $b['order'];
        });
        
        $slide->update(['blocks' => $blocks]);

        

        $prefix = auth()->user()?->role === 'teacher'
            ? 'teacher.content-lessons.edit'
            : 'admin.content-lessons.edit';
        
        return redirect()
            ->route($prefix, $slide->lesson_id)
            ->with('success', 'Block added successfully.');
    }

    /**
     * Update a specific block within a slide.
     */
    public function updateBlock(Request $request, LessonSlide $slide, string $blockId)
    {
        // $this->authorize('update', $slide->lesson);
        
        Log::info('🔵 [LessonSlideController] updateBlock called', [
            'slide_id' => $slide->id,
            'block_id' => $blockId
        ]);
        Log::info('📥 [LessonSlideController] Request all data', $request->all());
        Log::info('📂 [LessonSlideController] Uploaded files', ['files' => array_keys($request->allFiles())]);
        Log::info('🔍 [LessonSlideController] Has content?', ['has_content' => $request->has('content')]);
        
        if ($request->has('content')) {
            $content = $request->input('content');
            Log::info('📊 [LessonSlideController] Content structure', [
                'keys' => array_keys($content),
                'has_image_file_key' => array_key_exists('image_file', $content),
                'image_file_type' => isset($content['image_file']) ? gettype($content['image_file']) : 'not set'
            ]);
        }
        
        $validated = $request->validate([
            'content' => 'nullable|array',
            'settings' => 'nullable|array',
            'order' => 'nullable|integer|min:0',
        ]);
        
        $blocks = $slide->blocks ?? [];
        $blockFound = false;
        
        foreach ($blocks as &$block) {
            if ($block['id'] === $blockId) {
                $blockFound = true;
                
                if (isset($validated['content'])) {
                    // Parse JSON-stringified fields (from FormData)
                    $content = $this->parseJsonContent($validated['content']);
                    // Process images BEFORE merging (like Questions system)
                    $block['content'] = $this->processBlockImages($content);
                }
                
                if (isset($validated['settings'])) {
                    $block['settings'] = array_merge($block['settings'] ?? [], $validated['settings']);
                }
                
                if (isset($validated['order'])) {
                    $block['order'] = $validated['order'];
                }
                
                $block['metadata']['version'] = ($block['metadata']['version'] ?? 1) + 1;
                
                break;
            }
        }
        
        if (!$blockFound) {
            return redirect()
                ->back()
                ->with('error', 'Block not found.');
        }
        
        // Re-sort blocks if order changed
        if (isset($validated['order'])) {
            usort($blocks, function ($a, $b) {
                return $a['order'] <=> $b['order'];
            });
        }
        
        $slide->update(['blocks' => $blocks]);

        
        return redirect()
            ->back()
            ->with('success', 'Block updated successfully.');
    }

    /**
     * Delete a specific block from a slide.
     */
    public function deleteBlock(LessonSlide $slide, string $blockId)
    {
        // $this->authorize('update', $slide->lesson);
        
        $blocks = $slide->blocks ?? [];
        
        $blocks = array_filter($blocks, function ($block) use ($blockId) {
            return $block['id'] !== $blockId;
        });
        
        // Re-index array
        $blocks = array_values($blocks);
        
        $slide->update(['blocks' => $blocks]);

        
        return redirect()
            ->back()
            ->with('success', 'Block deleted successfully.');
    }

    /**
     * Duplicate a slide.
     */
    public function duplicate(LessonSlide $slide)
    {
        // $this->authorize('update', $slide->lesson);
        
        $newSlide = $slide->replicate();
        $newSlide->title = $slide->title . ' (Copy)';
        $newSlide->order_position = $slide->lesson->slides()->max('order_position') + 1;
        
        // Generate new UUIDs for blocks
        $blocks = $slide->blocks ?? [];
        foreach ($blocks as &$block) {
            $block['id'] = Str::uuid()->toString();
        }
        $newSlide->blocks = $blocks;
        
        $newSlide->save();
        
       

        $prefix = auth()->user()?->role === 'teacher'
            ? 'teacher.content-lessons.edit'
            : 'admin.content-lessons.edit';
        
        return redirect()
            ->route($prefix, $slide->lesson_id)
            ->with('success', 'Slide duplicated successfully.');
    }
    
    /**
     * Save whiteboard interaction (drawing canvas data)
     */
    public function saveWhiteboardInteraction(Request $request, LessonSlide $slide)
    {
        $validated = $request->validate([
            'block_id' => 'required|string',
            'canvas_data' => 'required|string', // Base64 PNG image
        ]);
        
        $child = $request->user()->children()->firstOrFail();
        $progress = \App\Models\LessonProgress::firstOrCreate(
            [
                'child_id' => $child->id,
                'lesson_id' => $slide->lesson_id,
            ],
            [
                'status' => 'in_progress',
                'started_at' => now(),
                'last_accessed_at' => now(),
                'slides_viewed' => [],
                'completion_percentage' => 0,
                'time_spent_seconds' => 0,
            ]
        );

        $interaction = \App\Models\SlideInteraction::firstOrCreate(
            [
                'child_id' => $child->id,
                'slide_id' => $slide->id,
                'lesson_progress_id' => $progress->id,
            ],
            [
                'first_viewed_at' => now(),
                'interactions_count' => 0,
                'time_spent_seconds' => 0,
            ]
        );

        $blockInteractions = $interaction->block_interactions ?? [];
        $blockInteractions[$validated['block_id']] = [
            'type' => 'whiteboard',
            'canvas_data' => $validated['canvas_data'],
            'timestamp' => now()->toISOString(),
        ];

        $interaction->block_interactions = $blockInteractions;
        $interaction->save();
        
        return response()->json([
            'success' => true,
            'message' => 'Whiteboard saved successfully',
            'timestamp' => $blockInteractions[$validated['block_id']]['timestamp'],
        ]);
    }
    
    /**
     * Load whiteboard interaction (retrieve saved drawing)
     */
    public function loadWhiteboardInteraction(Request $request, LessonSlide $slide)
    {
        $request->validate([
            'block_id' => 'required|string',
        ]);
        
        $child = $request->user()->children()->firstOrFail();

        $interaction = \App\Models\SlideInteraction::where('child_id', $child->id)
            ->where('slide_id', $slide->id)
            ->latest('updated_at')
            ->first();
        
        if (!$interaction) {
            return response()->json([
                'canvas_data' => null,
                'timestamp' => null,
            ]);
        }

        $blockInteractions = $interaction->block_interactions ?? [];
        $block = $blockInteractions[$request->block_id] ?? null;

        return response()->json([
            'canvas_data' => $block['canvas_data'] ?? null,
            'timestamp' => $block['timestamp'] ?? null,
        ]);
    }
    
    /**
     * Parse JSON-stringified content fields from FormData
     */
    private function parseJsonContent(array $content): array
    {
        foreach ($content as $key => $value) {
            // Skip File objects
            if ($value instanceof \Illuminate\Http\UploadedFile) {
                continue;
            }
            
            // Try to parse JSON strings (from FormData serialization)
            if (is_string($value) && !empty($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $content[$key] = $decoded;
                    Log::info('✅ [LessonSlideController] Parsed JSON field', [
                        'key' => $key,
                        'type' => gettype($decoded)
                    ]);
                }
            }
        }
        
        return $content;
    }
    
    /**
     * Process uploaded images in block content (using QuestionController pattern)
     */
    private function processBlockImages(array $content): array
    {
        // Handle image_file uploads (from File objects)
        if (isset($content['image_file']) && $content['image_file'] instanceof \Illuminate\Http\UploadedFile) {
            try {
                $stored = $content['image_file']->store('lessons/images', 'public');
                $content['url'] = 'storage/' . $stored;
                unset($content['image_file']); // Remove File object after storing
                
                Log::info('✅ Lesson image uploaded successfully', ['path' => $content['url']]);
            } catch (\Exception $e) {
                Log::error('❌ Lesson image upload failed', ['error' => $e->getMessage()]);
            }
        }
        
        return $content;
    }
}
