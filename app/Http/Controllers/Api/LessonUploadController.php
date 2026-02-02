<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\LessonPlayer\LessonUploadRequest;
use App\Http\Resources\LessonUploadResource;
use App\Models\Access;
use App\Models\Child;
use App\Models\ContentLesson;
use App\Models\LessonProgress;
use App\Models\LessonSlide;
use App\Models\LessonUpload;
use App\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LessonUploadController extends ApiController
{
    public function upload(LessonUploadRequest $request, ContentLesson $lesson, LessonSlide $slide): JsonResponse
    {
        $child = $this->resolveChild($request);
        if ($child instanceof JsonResponse) {
            return $child;
        }

        if ($response = $this->ensureLessonAccess($request, $child, $lesson)) {
            return $response;
        }

        if ($slide->lesson_id !== $lesson->id) {
            return $this->error('Slide not found in this lesson.', [], 404);
        }

        $validated = $request->validated();

        $blocks = $slide->blocks ?? [];
        $uploadBlock = collect($blocks)->firstWhere('id', $validated['block_id']);
        if (!$uploadBlock || !in_array($uploadBlock['type'], ['UploadBlock', 'upload', 'task'], true)) {
            return $this->error('Upload block not found.', [], 404);
        }

        $file = $request->file('file');
        $filename = time() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs(
            "lesson-uploads/{$lesson->id}/{$child->id}",
            $filename,
            'public'
        );

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

        $progress = LessonProgress::where('child_id', $child->id)
            ->where('lesson_id', $lesson->id)
            ->first();

        if ($progress) {
            $progress->increment('uploads_submitted');
            $progress->checkCompletion();
        }

        return $this->success([
            'upload' => (new LessonUploadResource($upload))->resolve(),
        ], status: 201);
    }

    public function index(Request $request, ContentLesson $lesson): JsonResponse
    {
        $child = $this->resolveChild($request);
        if ($child instanceof JsonResponse) {
            return $child;
        }

        if ($response = $this->ensureLessonAccess($request, $child, $lesson)) {
            return $response;
        }

        $uploads = LessonUpload::where('child_id', $child->id)
            ->where('lesson_id', $lesson->id)
            ->with('slide')
            ->orderBy('created_at', 'desc')
            ->paginate(ApiPagination::perPage($request));

        $data = LessonUploadResource::collection($uploads->items())->resolve();

        return $this->paginated($uploads, $data);
    }

    public function show(Request $request, LessonUpload $upload): JsonResponse
    {
        $user = $request->user();

        if ($user && $user->role === 'parent') {
            $ownsChild = $user->children()
                ->where('children.id', $upload->child_id)
                ->exists();
            if (!$ownsChild) {
                return $this->error('Unauthorized.', [], 403);
            }
        }

        $upload->load(['slide', 'child', 'reviewer', 'lesson']);

        return $this->success([
            'upload' => (new LessonUploadResource($upload))->resolve(),
        ]);
    }

    public function destroy(Request $request, LessonUpload $upload): JsonResponse
    {
        $user = $request->user();

        if ($user && $user->role === 'parent') {
            $ownsChild = $user->children()
                ->where('children.id', $upload->child_id)
                ->exists();
            if (!$ownsChild) {
                return $this->error('Unauthorized.', [], 403);
            }
        }

        Storage::disk('public')->delete($upload->file_path);
        if ($upload->feedback_audio) {
            Storage::disk('public')->delete($upload->feedback_audio);
        }

        $upload->delete();

        return $this->success(['message' => 'Upload deleted successfully.']);
    }

    private function resolveChild(Request $request): Child|JsonResponse
    {
        $user = $request->user();
        $children = $user?->children ?? collect();

        if ($children->isEmpty()) {
            return $this->error('No child profile found.', [], 400);
        }

        if ($request->filled('child_id')) {
            $child = $children->firstWhere('id', $request->integer('child_id'));
            if (!$child) {
                return $this->error('Invalid child selection.', [], 422);
            }
        } elseif ($children->count() > 1) {
            return $this->error('child_id is required when multiple children exist.', [], 422);
        } else {
            $child = $children->first();
        }

        $orgId = $request->attributes->get('organization_id');
        if ($orgId && $child->organization_id && (int) $child->organization_id !== (int) $orgId) {
            return $this->error('Invalid organization context.', [], 403);
        }

        return $child;
    }

    private function ensureLessonAccess(Request $request, Child $child, ContentLesson $lesson): ?JsonResponse
    {
        $user = $request->user();
        if ($user && ($user->isAdmin() || $user->isTeacher() || $user->isSuperAdmin())) {
            return null;
        }

        if ($lesson->status !== 'live') {
            return $this->error('Lesson not found.', [], 404);
        }

        $orgId = $request->attributes->get('organization_id');
        if ($orgId && $lesson->organization_id && (int) $lesson->organization_id !== (int) $orgId) {
            return $this->error('Lesson not found.', [], 404);
        }

        $hasAccess = Access::forChild($child->id)
            ->where('access', true)
            ->where('payment_status', 'paid')
            ->withLessonAccess($lesson->id)
            ->exists();

        if (!$hasAccess) {
            return $this->error('You do not have access to this lesson.', [], 403);
        }

        return null;
    }
}
