<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\LessonUploadResource;
use App\Models\LessonUpload;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LessonUploadController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $teacher = $request->user();
        if (!$teacher) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $query = LessonUpload::with(['child', 'lesson', 'slide', 'reviewer']);

        $orgId = $request->attributes->get('organization_id') ?: $teacher->current_organization_id;
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

    private function ensureOrgScope(Request $request, LessonUpload $upload): ?JsonResponse
    {
        $teacher = $request->user();
        if (!$teacher) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id') ?: $teacher->current_organization_id;
        if ($orgId) {
            $lessonOrgId = $upload->lesson?->organization_id;
            if ((int) $lessonOrgId !== (int) $orgId) {
                return $this->error('Not found.', [], 404);
            }
        }

        return null;
    }
}
