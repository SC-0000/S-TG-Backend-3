<?php

namespace App\Http\Controllers;

use App\Models\ContentLesson;
use App\Models\LessonSlide;
use App\Models\LessonUpload;
use App\Models\LessonProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LessonUploadController extends Controller
{
    /**
     * Upload a file for an upload block.
     */
    public function upload(Request $request, ContentLesson $lesson, LessonSlide $slide)
    {
        $child = $this->getChild($request);
        
        $validated = $request->validate([
            'block_id' => 'required|string',
            'file' => 'required|file|max:51200', // 50MB max
            'file_type' => 'required|in:image,pdf,audio,video,document',
        ]);
        
        // Verify slide belongs to lesson
        if ($slide->lesson_id !== $lesson->id) {
            return response()->json(['message' => 'Slide not found in this lesson.'], 404);
        }
        
        // Get block details
        $blocks = $slide->blocks;
        $uploadBlock = collect($blocks)->firstWhere('id', $validated['block_id']);
        
        if (!$uploadBlock || $uploadBlock['type'] !== 'UploadBlock') {
            return response()->json(['message' => 'Upload block not found.'], 404);
        }
        
        // Check file type is allowed
        $acceptedTypes = $uploadBlock['content']['accepted_types'] ?? [];
        // For simplicity, accepting all for now - could add validation here
        
        // Store file
        $file = $request->file('file');
        $filename = time() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs(
            "lesson-uploads/{$lesson->id}/{$child->id}",
            $filename,
            'public'
        );
        
        // Create upload record
        $upload = LessonUpload::create([
            'child_id' => $child->id,
            'lesson_id' => $lesson->id,
            'slide_id' => $slide->id,
            'block_id' => $validated['block_id'],
            'file_path' => $path,
            'file_type' => $validated['file_type'],
            'file_size_kb' => round($file->getSize() / 1024),
            'original_filename' => $file->getClientOriginalName(),
            'status' => 'pending',
        ]);
        
        // Update progress
        $progress = LessonProgress::where('child_id', $child->id)
            ->where('lesson_id', $lesson->id)
            ->first();
        
        if ($progress) {
            $progress->increment('uploads_submitted');
            $progress->checkCompletion();
        }
        
        // Trigger AI analysis if enabled
        if ($uploadBlock['settings']['ocr_enabled'] ?? false) {
            // Queue AI analysis job here
            // dispatch(new AnalyzeLessonUploadJob($upload));
        }
        
        return response()->json([
            'success' => true,
            'upload' => [
                'id' => $upload->id,
                'file_path' => Storage::url($upload->file_path),
                'file_type' => $upload->file_type,
                'file_size_kb' => $upload->file_size_kb,
                'status' => $upload->status,
                'created_at' => $upload->created_at,
            ],
        ], 201);
    }

    /**
     * List uploads for a lesson (student view).
     */
    public function index(Request $request, ContentLesson $lesson)
    {
        $child = $this->getChild($request);
        
        $uploads = LessonUpload::where('child_id', $child->id)
            ->where('lesson_id', $lesson->id)
            ->with(['slide'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($upload) {
                return [
                    'id' => $upload->id,
                    'slide_id' => $upload->slide_id,
                    'slide_title' => $upload->slide->title ?? null,
                    'block_id' => $upload->block_id,
                    'file_path' => Storage::url($upload->file_path),
                    'file_type' => $upload->file_type,
                    'file_size_kb' => $upload->file_size_kb,
                    'original_filename' => $upload->original_filename,
                    'status' => $upload->status,
                    'score' => $upload->score,
                    'feedback' => $upload->feedback,
                    'reviewed_at' => $upload->reviewed_at,
                    'created_at' => $upload->created_at,
                ];
            });
        
        return response()->json(['uploads' => $uploads]);
    }

    /**
     * View a single upload.
     */
    public function show(LessonUpload $upload)
    {
        $user = request()->user();
        
        // Check authorization
        if ($user->role === 'parent') {
            $child = $user->children()->firstOrFail();
            if ($upload->child_id !== $child->id) {
                return response()->json(['message' => 'Unauthorized.'], 403);
            }
        }
        
        $upload->load(['slide', 'child', 'reviewer']);
        
        return response()->json([
            'upload' => [
                'id' => $upload->id,
                'lesson_id' => $upload->lesson_id,
                'slide_id' => $upload->slide_id,
                'slide_title' => $upload->slide->title ?? null,
                'block_id' => $upload->block_id,
                'file_path' => Storage::url($upload->file_path),
                'file_type' => $upload->file_type,
                'file_size_kb' => $upload->file_size_kb,
                'original_filename' => $upload->original_filename,
                'status' => $upload->status,
                'score' => $upload->score,
                'rubric_data' => $upload->rubric_data,
                'feedback' => $upload->feedback,
                'feedback_audio' => $upload->feedback_audio ? Storage::url($upload->feedback_audio) : null,
                'annotations' => $upload->annotations,
                'ai_analysis' => $upload->ai_analysis,
                'reviewed_by' => $upload->reviewer ? [
                    'id' => $upload->reviewer->id,
                    'name' => $upload->reviewer->name,
                ] : null,
                'reviewed_at' => $upload->reviewed_at,
                'created_at' => $upload->created_at,
                'child' => [
                    'id' => $upload->child->id,
                    'name' => $upload->child->name,
                ],
            ],
        ]);
    }

    /**
     * Grade an upload (teacher/admin).
     */
    public function grade(Request $request, LessonUpload $upload)
    {
        $validated = $request->validate([
            'score' => 'required|numeric|min:0',
            'rubric_data' => 'nullable|array',
            'feedback' => 'nullable|string',
            'annotations' => 'nullable|array',
        ]);
        
        $upload->update([
            'score' => $validated['score'],
            'rubric_data' => $validated['rubric_data'] ?? null,
            'feedback' => $validated['feedback'] ?? null,
            'annotations' => $validated['annotations'] ?? null,
            'status' => 'graded',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);
        
        return response()->json([
            'success' => true,
            'upload' => [
                'id' => $upload->id,
                'status' => $upload->status,
                'score' => $upload->score,
                'reviewed_at' => $upload->reviewed_at,
            ],
        ]);
    }

    /**
     * Submit feedback for an upload.
     */
    public function submitFeedback(Request $request, LessonUpload $upload)
    {
        $validated = $request->validate([
            'feedback' => 'required|string',
            'feedback_audio' => 'nullable|file|mimes:mp3,wav,m4a|max:10240', // 10MB
        ]);
        
        $data = [
            'feedback' => $validated['feedback'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ];
        
        // Handle audio feedback
        if ($request->hasFile('feedback_audio')) {
            $audioFile = $request->file('feedback_audio');
            $audioPath = $audioFile->store('lesson-feedback-audio', 'public');
            $data['feedback_audio'] = $audioPath;
        }
        
        $upload->update($data);
        
        return response()->json([
            'success' => true,
            'message' => 'Feedback submitted successfully.',
        ]);
    }

    /**
     * Return upload to student.
     */
    public function returnToStudent(LessonUpload $upload)
    {
        $upload->update([
            'status' => 'returned',
            'reviewed_by' => request()->user()->id,
            'reviewed_at' => now(),
        ]);
        
        // Optionally send notification to student/parent
        
        return response()->json([
            'success' => true,
            'message' => 'Upload returned to student.',
        ]);
    }

    /**
     * List pending uploads for review (teacher/admin).
     */
    public function pendingUploads(Request $request)
    {
        $query = LessonUpload::where('status', 'pending')
            ->with(['child', 'lesson', 'slide'])
            ->orderBy('created_at', 'asc');
        
        // Filter by organization if needed
        if ($request->has('organization_id')) {
            $query->whereHas('lesson', function ($q) use ($request) {
                $q->where('organization_id', $request->organization_id);
            });
        }
        
        $uploads = $query->paginate(20)
            ->through(function ($upload) {
                return [
                    'id' => $upload->id,
                    'child_name' => $upload->child->name ?? null,
                    'lesson_title' => $upload->lesson->title ?? null,
                    'slide_title' => $upload->slide->title ?? null,
                    'file_type' => $upload->file_type,
                    'file_path' => Storage::url($upload->file_path),
                    'original_filename' => $upload->original_filename,
                    'created_at' => $upload->created_at,
                ];
            });
        
        return response()->json(['uploads' => $uploads]);
    }

    /**
     * Teacher's pending uploads - scoped to their organization
     */
    public function teacherPending(Request $request)
    {
        $user = $request->user();
        
        $query = LessonUpload::where('status', 'pending')
            ->with(['child', 'lesson', 'slide'])
            ->when($user->current_organization_id, function($q) use ($user) {
                $q->whereHas('lesson', function ($query) use ($user) {
                    $query->where('organization_id', $user->current_organization_id);
                });
            })
            ->orderBy('created_at', 'asc');
        
        $uploads = $query->paginate(20)
            ->through(function ($upload) {
                return [
                    'id' => $upload->id,
                    'child_name' => $upload->child->name ?? null,
                    'lesson_title' => $upload->lesson->title ?? null,
                    'slide_title' => $upload->slide->title ?? null,
                    'file_type' => $upload->file_type,
                    'file_path' => Storage::url($upload->file_path),
                    'original_filename' => $upload->original_filename,
                    'created_at' => $upload->created_at,
                ];
            });
        
        return \Inertia\Inertia::render('@admin/Teacher/Uploads/Pending', [
            'uploads' => $uploads,
        ]);
    }

    /**
     * Request AI analysis for an upload.
     */
    public function requestAIAnalysis(LessonUpload $upload)
    {
        // Queue AI analysis job
        // dispatch(new AnalyzeLessonUploadJob($upload));
        
        $upload->update(['status' => 'reviewing']);
        
        return response()->json([
            'success' => true,
            'message' => 'AI analysis requested.',
        ]);
    }

    /**
     * Delete an upload.
     */
    public function destroy(LessonUpload $upload)
    {
        $user = request()->user();
        $child = $this->getChild(request());
        
        // Only allow student to delete their own uploads, or teacher/admin
        if ($user->role === 'parent' && $upload->child_id !== $child->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }
        
        // Delete file from storage
        Storage::disk('public')->delete($upload->file_path);
        if ($upload->feedback_audio) {
            Storage::disk('public')->delete($upload->feedback_audio);
        }
        
        // Delete record
        $upload->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Upload deleted successfully.',
        ]);
    }

    /**
     * Helper to get current child.
     */
    protected function getChild(Request $request)
    {
        return $request->user()->children()->firstOrFail();
    }
}
