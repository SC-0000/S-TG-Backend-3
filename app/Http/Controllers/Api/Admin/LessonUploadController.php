<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\LessonUploadResource;
use App\Models\LessonUpload;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LessonUploadController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $query = LessonUpload::with(['child', 'lesson', 'slide', 'reviewer']);

        $orgId = $request->attributes->get('organization_id');
        if ($user->isSuperAdmin()) {
            if ($request->filled('organization_id')) {
                $orgId = $request->integer('organization_id');
            }
        }

        if ($orgId) {
            $query->whereHas('lesson', function ($q) use ($orgId) {
                $q->where('organization_id', $orgId);
            });
        }

        ApiQuery::applyFilters($query, $request, [
            'status' => true,
            'lesson_id' => true,
            'child_id' => true,
            'slide_id' => true,
        ]);

        ApiQuery::applySort($query, $request, ['created_at', 'status', 'score'], '-created_at');

        $uploads = $query->paginate(ApiPagination::perPage($request, 20));
        $data = LessonUploadResource::collection($uploads->items())->resolve();

        return $this->paginated($uploads, $data);
    }

    public function show(Request $request, LessonUpload $upload): JsonResponse
    {
        if ($response = $this->ensureOrgScope($request, $upload)) {
            return $response;
        }

        $upload->load(['child', 'lesson', 'slide', 'reviewer']);
        $data = (new LessonUploadResource($upload))->resolve();

        return $this->success($data);
    }

    public function grade(Request $request, LessonUpload $upload): JsonResponse
    {
        if ($response = $this->ensureOrgScope($request, $upload)) {
            return $response;
        }

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
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
        ]);

        $upload->load(['child', 'lesson', 'slide', 'reviewer']);
        $data = (new LessonUploadResource($upload))->resolve();

        return $this->success(['upload' => $data]);
    }

    public function feedback(Request $request, LessonUpload $upload): JsonResponse
    {
        if ($response = $this->ensureOrgScope($request, $upload)) {
            return $response;
        }

        $validated = $request->validate([
            'feedback' => 'required|string',
            'feedback_audio' => 'nullable|file|mimes:mp3,wav,m4a|max:10240',
        ]);

        $data = [
            'feedback' => $validated['feedback'],
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
        ];

        if ($request->hasFile('feedback_audio')) {
            $audioFile = $request->file('feedback_audio');
            $audioPath = $audioFile->store('lesson-feedback-audio', 'public');
            $data['feedback_audio'] = $audioPath;
        }

        $upload->update($data);

        $upload->load(['child', 'lesson', 'slide', 'reviewer']);
        $resource = (new LessonUploadResource($upload))->resolve();

        return $this->success(['upload' => $resource]);
    }

    public function returnToStudent(Request $request, LessonUpload $upload): JsonResponse
    {
        if ($response = $this->ensureOrgScope($request, $upload)) {
            return $response;
        }

        $upload->update([
            'status' => 'returned',
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
        ]);

        $upload->load(['child', 'lesson', 'slide', 'reviewer']);
        $data = (new LessonUploadResource($upload))->resolve();

        return $this->success(['upload' => $data]);
    }

    public function requestAIAnalysis(Request $request, LessonUpload $upload): JsonResponse
    {
        if ($response = $this->ensureOrgScope($request, $upload)) {
            return $response;
        }

        $upload->update(['status' => 'reviewing']);

        return $this->success(['message' => 'AI analysis requested.']);
    }

    public function destroy(Request $request, LessonUpload $upload): JsonResponse
    {
        if ($response = $this->ensureOrgScope($request, $upload)) {
            return $response;
        }

        Storage::disk('public')->delete($upload->file_path);
        if ($upload->feedback_audio) {
            Storage::disk('public')->delete($upload->feedback_audio);
        }

        $upload->delete();

        return $this->success(['message' => 'Upload deleted successfully.']);
    }

    private function ensureOrgScope(Request $request, LessonUpload $upload): ?JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id');
        if ($user->isSuperAdmin() && $request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
        }

        if ($orgId) {
            $lessonOrgId = $upload->lesson?->organization_id;
            if ((int) $lessonOrgId !== (int) $orgId) {
                return $this->error('Not found.', [], 404);
            }
        }

        return null;
    }
}
