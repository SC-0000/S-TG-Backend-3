<?php

namespace App\Http\Controllers;

use App\Models\ContentLesson;
use App\Models\LessonSlide;
use App\Models\LessonProgress;
use App\Models\SlideInteraction;
use App\Models\Child;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log;

class LessonPlayerController extends Controller
{
    /**
     * Start a lesson (initialize progress).
     */
    public function start(Request $request, ContentLesson $lesson)
    {
        $child = $this->getChild($request);
        
        $progress = LessonProgress::firstOrCreate(
            [
                'child_id' => $child->id,
                'lesson_id' => $lesson->id,
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
        
        return redirect()->route('parent.lessons.player', $lesson);
    }

    /**
     * Display the lesson player.
     */
    public function view(Request $request, ContentLesson $lesson)
    {
        $child = $this->getChild($request);
        Log::info('LessonPlayerController view called', ['child_id' => $child->id, 'lesson_id' => $lesson->id]);
        
        // Auto-create progress if it doesn't exist (allows direct access to player)
        $progress = LessonProgress::firstOrCreate(
            [
                'child_id' => $child->id,
                'lesson_id' => $lesson->id,
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
        
        Log::info('Fetched lesson progress', ['progress_id' => $progress->id, 'status' => $progress->status]);
        $lesson->load(['slides' => function ($query) {
            $query->orderBy('order_position');
        }]);
        Log::info('Loaded lesson slides', ['slides_count' => $lesson->slides->count()]);
        
        // Process slides with blocks
        $slidesWithBlocks = $lesson->slides->map(function ($slide) {
            $blocks = $slide->blocks;
            
            // Collect all question IDs
            $questionIds = [];
            foreach ($blocks as $block) {
                if ($block['type'] === 'question' && isset($block['content']['question_id'])) {
                    $questionIds[] = $block['content']['question_id'];
                }
            }
            
            // Fetch all questions in one query
            $questions = [];
            if (!empty($questionIds)) {
                $questions = \App\Models\Question::whereIn('id', $questionIds)
                    ->get()
                    ->keyBy('id');
            }
            
            // Expand question data in blocks
            foreach ($blocks as &$block) {
                if ($block['type'] === 'question' && isset($block['content']['question_id'])) {
                    $questionId = $block['content']['question_id'];
                    if (isset($questions[$questionId])) {
                        $block['content']['selected_question'] = $questions[$questionId];
                    }
                }
            }
            
            return [
                'id' => $slide->id,
                'uid' => $slide->uid,
                'title' => $slide->title,
                'order_position' => $slide->order_position,
                'blocks' => $blocks, // Include full blocks array
            ];
        });
        
        Log::info('Slides processed with blocks', [
            'total_slides' => $slidesWithBlocks->count(),
            'first_slide_has_blocks' => $slidesWithBlocks->first() ? count($slidesWithBlocks->first()['blocks']) : 0,
        ]);
        
        return Inertia::render('@parent/ContentLessons/Player', [
            'lesson' => [
                'id' => $lesson->id,
                'uid' => $lesson->uid,
                'title' => $lesson->title,
                'description' => $lesson->description,
                'lesson_type' => $lesson->lesson_type,
                'estimated_minutes' => $lesson->estimated_minutes,
                'enable_ai_help' => $lesson->enable_ai_help,
                'enable_tts' => $lesson->enable_tts,
                'slides' => $slidesWithBlocks,
            ],
            'progress' => [
                'id' => $progress->id,
                'status' => $progress->status,
                'slides_viewed' => $progress->slides_viewed ?? [],
                'last_slide_id' => $progress->last_slide_id,
                'completion_percentage' => $progress->completion_percentage,
                'time_spent_seconds' => $progress->time_spent_seconds,
                'questions_attempted' => $progress->questions_attempted,
                'questions_correct' => $progress->questions_correct,
                'questions_score' => $progress->questions_score,
            ],
        ]);
    }

    /**
     * Update lesson progress (called periodically).
     */
    public function updateProgress(Request $request, ContentLesson $lesson)
    {
        $child = $this->getChild($request);
        
        $validated = $request->validate([
            'time_spent_seconds' => 'required|integer|min:0',
            'slide_id' => 'nullable|exists:lesson_slides,id',
            'last_slide_id' => 'nullable|exists:lesson_slides,id',
            'slides_viewed' => 'nullable|array',
            'slides_viewed.*' => 'integer|exists:lesson_slides,id',
        ]);
        
        $progress = LessonProgress::where('child_id', $child->id)
            ->where('lesson_id', $lesson->id)
            ->firstOrFail();
        
        // Update time spent
        $progress->updateTimeSpent($validated['time_spent_seconds']);
        
        // Update last slide ID (current position)
        if (isset($validated['last_slide_id'])) {
            $progress->update(['last_slide_id' => $validated['last_slide_id']]);
        } elseif (isset($validated['slide_id'])) {
            // Fallback to slide_id if last_slide_id not provided
            $progress->update(['last_slide_id' => $validated['slide_id']]);
        }
        
        // Update slides viewed array
        if (isset($validated['slides_viewed'])) {
            $progress->update(['slides_viewed' => $validated['slides_viewed']]);
            // Recalculate completion percentage
            $progress->checkCompletion();
        }
        
        // Update last accessed timestamp
        $progress->update(['last_accessed_at' => now()]);
        
        // Update slide interaction if provided
        if (isset($validated['slide_id'])) {
            $interaction = SlideInteraction::where('child_id', $child->id)
                ->where('slide_id', $validated['slide_id'])
                ->where('lesson_progress_id', $progress->id)
                ->first();
            
            if ($interaction) {
                $interaction->increment('time_spent_seconds', $validated['time_spent_seconds']);
                $interaction->update(['last_viewed_at' => now()]);
            }
        }
        
        // Refresh to get updated values
        $progress->refresh();
        
        return response()->json([
            'success' => true,
            'progress' => [
                'time_spent_seconds' => $progress->time_spent_seconds,
                'last_slide_id' => $progress->last_slide_id,
                'slides_viewed' => $progress->slides_viewed ?? [],
                'completion_percentage' => $progress->completion_percentage,
                'last_accessed_at' => $progress->last_accessed_at,
            ],
        ]);
    }

    /**
     * Record a slide view.
     */
    public function recordSlideView(Request $request, ContentLesson $lesson, LessonSlide $slide)
    {
        $child = $this->getChild($request);
        
        $progress = LessonProgress::where('child_id', $child->id)
            ->where('lesson_id', $lesson->id)
            ->firstOrFail();
        
        // Mark slide as viewed
        $progress->markSlideViewed($slide->id);
        
        // Create or update slide interaction
        $interaction = SlideInteraction::firstOrCreate(
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
        
        $interaction->increment('interactions_count');
        $interaction->update(['last_viewed_at' => now()]);
        
        return response()->json([
            'success' => true,
            'progress' => [
                'completion_percentage' => $progress->completion_percentage,
                'slides_viewed' => $progress->slides_viewed,
            ],
        ]);
    }

    /**
     * Mark lesson as complete.
     */
    public function complete(Request $request, ContentLesson $lesson)
    {
        $child = $this->getChild($request);
        
        $progress = LessonProgress::where('child_id', $child->id)
            ->where('lesson_id', $lesson->id)
            ->firstOrFail();
        
        // Check completion criteria
        $progress->checkCompletion();
        
        return response()->json([
            'success' => true,
            'completed' => $progress->status === 'completed',
            'progress' => [
                'status' => $progress->status,
                'completion_percentage' => $progress->completion_percentage,
                'score' => $progress->score,
                'completed_at' => $progress->completed_at,
            ],
        ]);
    }

    /**
     * Record slide interaction (help request, flag, etc).
     */
    public function recordInteraction(Request $request, ContentLesson $lesson, LessonSlide $slide)
    {
        $child = $this->getChild($request);
        
        $validated = $request->validate([
            'interaction_type' => 'required|string|in:help_request,flag_difficult,hint_used,tts_used',
            'data' => 'nullable|array',
        ]);
        
        $progress = LessonProgress::where('child_id', $child->id)
            ->where('lesson_id', $lesson->id)
            ->firstOrFail();
        
        $interaction = SlideInteraction::where('child_id', $child->id)
            ->where('slide_id', $slide->id)
            ->where('lesson_progress_id', $progress->id)
            ->first();
        
        if (!$interaction) {
            $interaction = SlideInteraction::create([
                'child_id' => $child->id,
                'slide_id' => $slide->id,
                'lesson_progress_id' => $progress->id,
                'first_viewed_at' => now(),
                'interactions_count' => 0,
            ]);
        }
        
        // Handle different interaction types
        switch ($validated['interaction_type']) {
            case 'help_request':
                $interaction->addHelpRequest('general', $validated['data'] ?? []);
                break;
                
            case 'flag_difficult':
                $interaction->update(['flagged_difficult' => true]);
                break;
                
            case 'hint_used':
            case 'tts_used':
                $interaction->addHelpRequest($validated['interaction_type'], $validated['data'] ?? []);
                break;
        }
        
        return response()->json(['success' => true]);
    }

    /**
     * Submit confidence rating for a slide.
     */
    public function submitConfidence(Request $request, ContentLesson $lesson, LessonSlide $slide)
    {
        $child = $this->getChild($request);
        
        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
        ]);
        
        $progress = LessonProgress::where('child_id', $child->id)
            ->where('lesson_id', $lesson->id)
            ->firstOrFail();
        
        $interaction = SlideInteraction::where('child_id', $child->id)
            ->where('slide_id', $slide->id)
            ->where('lesson_progress_id', $progress->id)
            ->first();
        
        if (!$interaction) {
            $interaction = SlideInteraction::create([
                'child_id' => $child->id,
                'slide_id' => $slide->id,
                'lesson_progress_id' => $progress->id,
                'first_viewed_at' => now(),
            ]);
        }
        
        $interaction->setConfidenceRating($validated['rating']);
        
        return response()->json(['success' => true]);
    }

    /**
     * Get lesson summary (after completion).
     */
    public function summary(Request $request, ContentLesson $lesson)
    {
        $child = $this->getChild($request);
        
        $progress = LessonProgress::where('child_id', $child->id)
            ->where('lesson_id', $lesson->id)
            ->firstOrFail();
        
        $lesson->load(['slides', 'assessments']);
        
        return Inertia::render('@parent/ContentLessons/Summary', [
            'lesson' => [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'description' => $lesson->description,
            ],
            'progress' => [
                'status' => $progress->status,
                'completion_percentage' => $progress->completion_percentage,
                'time_spent_seconds' => $progress->time_spent_seconds,
                'score' => $progress->score,
                'questions_attempted' => $progress->questions_attempted,
                'questions_correct' => $progress->questions_correct,
                'questions_score' => $progress->questions_score,
                'accuracy' => $progress->accuracy,
                'uploads_submitted' => $progress->uploads_submitted,
                'uploads_required' => $progress->uploads_required,
                'slides_viewed' => $progress->slides_viewed ?? [],
                'started_at' => $progress->started_at,
                'completed_at' => $progress->completed_at,
            ],
            'stats' => [
                'total_slides' => $lesson->slides->count(),
                'slides_viewed' => count($progress->slides_viewed ?? []),
                'difficult_slides' => SlideInteraction::where('child_id', $child->id)
                    ->where('lesson_progress_id', $progress->id)
                    ->where('flagged_difficult', true)
                    ->count(),
            ],
        ]);
    }

    /**
     * Helper to get current child.
     */
    protected function getChild(Request $request)
    {
        // Assuming user has children relationship
        return $request->user()->children()->firstOrFail();
    }
}
