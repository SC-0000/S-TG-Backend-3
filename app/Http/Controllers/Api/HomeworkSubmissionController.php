<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Homework\HomeworkSubmissionStoreRequest;
use App\Http\Requests\Api\Homework\HomeworkSubmissionUpdateRequest;
use App\Http\Resources\HomeworkSubmissionResource;
use App\Models\HomeworkAssignment;
use App\Models\HomeworkSubmission;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class HomeworkSubmissionController extends ApiController
{
    public function index(Request $request, HomeworkAssignment $homework): JsonResponse
    {
        if ($response = $this->ensureAssignmentScope($request, $homework)) {
            return $response;
        }

        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $query = HomeworkSubmission::where('assignment_id', $homework->id);

        if (in_array($user->role, ['parent', 'guest_parent'], true)) {
            $childIds = $user->children->pluck('id')->all();
            $query->whereIn('student_id', $childIds);
        } else {
            $orgId = $request->attributes->get('organization_id');
            if ($orgId) {
                $query->where('organization_id', $orgId);
            }
        }

        ApiQuery::applyFilters($query, $request, [
            'submission_status' => true,
            'student_id' => true,
        ]);

        ApiQuery::applySort($query, $request, ['created_at', 'submitted_at', 'reviewed_at'], '-created_at');

        $submissions = $query->paginate(ApiPagination::perPage($request, 20));
        $data = HomeworkSubmissionResource::collection($submissions->items())->resolve();

        return $this->paginated($submissions, $data);
    }

    public function store(HomeworkSubmissionStoreRequest $request, HomeworkAssignment $homework): JsonResponse
    {
        if ($response = $this->ensureAssignmentScope($request, $homework)) {
            return $response;
        }

        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        if (!in_array($user->role, ['parent', 'guest_parent'], true)) {
            return $this->error('Unauthorized access.', [], 403);
        }

        $payload = $request->validated();
        $child = $user->children->firstWhere('id', (int) $payload['child_id']);
        if (!$child) {
            return $this->error('Invalid child selection.', [], 422);
        }

        $payload['assignment_id'] = $homework->id;
        $payload['student_id'] = $child->id;
        $payload['organization_id'] = $homework->organization_id;

        $status = $payload['submission_status'] ?? 'submitted';
        $payload['submission_status'] = $status;
        if ($status === 'submitted') {
            $payload['submitted_at'] = now();
        }

        $attachments = $this->storeAttachments($request, 'homework_submissions');
        if (!empty($attachments)) {
            $payload['attachments'] = $attachments;
        }

        $submission = HomeworkSubmission::create($payload);
        $data = (new HomeworkSubmissionResource($submission))->resolve();

        return $this->success(['submission' => $data], [], 201);
    }

    public function show(Request $request, HomeworkSubmission $submission): JsonResponse
    {
        if ($response = $this->ensureSubmissionAccess($request, $submission)) {
            return $response;
        }

        $data = (new HomeworkSubmissionResource($submission))->resolve();

        return $this->success($data);
    }

    public function update(HomeworkSubmissionUpdateRequest $request, HomeworkSubmission $submission): JsonResponse
    {
        if ($response = $this->ensureSubmissionAccess($request, $submission)) {
            return $response;
        }

        $user = $request->user();
        $payload = $request->validated();

        if (in_array($user->role, ['parent', 'guest_parent'], true)) {
            $payload = array_intersect_key($payload, array_flip([
                'submission_status',
                'content',
                'attachments',
            ]));

            if (isset($payload['submission_status']) && $payload['submission_status'] === 'submitted') {
                $payload['submitted_at'] = now();
            }
        } else {
            $payload = array_intersect_key($payload, array_flip([
                'submission_status',
                'content',
                'attachments',
                'grade',
                'feedback',
            ]));

            if (isset($payload['submission_status']) && $payload['submission_status'] === 'graded') {
                $payload['reviewed_at'] = now();
            }
        }

        $attachments = $this->storeAttachments($request, 'homework_submissions');
        if (!empty($attachments)) {
            $payload['attachments'] = array_values(array_merge($submission->attachments ?? [], $attachments));
        }

        $submission->update($payload);
        $data = (new HomeworkSubmissionResource($submission))->resolve();

        return $this->success(['submission' => $data]);
    }

    public function destroy(Request $request, HomeworkSubmission $submission): JsonResponse
    {
        if ($response = $this->ensureSubmissionAccess($request, $submission)) {
            return $response;
        }

        $submission->delete();

        return $this->success(['message' => 'Homework submission deleted successfully.']);
    }

    private function ensureAssignmentScope(Request $request, HomeworkAssignment $homework): ?JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');
        if ($orgId && (int) $homework->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        return null;
    }

    private function ensureSubmissionAccess(Request $request, HomeworkSubmission $submission): ?JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id');
        if ($orgId && (int) $submission->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        if (in_array($user->role, ['parent', 'guest_parent'], true)) {
            $childIds = $user->children->pluck('id')->all();
            if (!in_array((int) $submission->student_id, $childIds, true)) {
                return $this->error('Not found.', [], 404);
            }
        }

        return null;
    }

    private function storeAttachments(Request $request, string $directory): array
    {
        $stored = [];

        if (!$request->hasFile('attachments')) {
            return $stored;
        }

        $files = $request->file('attachments');
        if ($files instanceof UploadedFile) {
            $files = [$files];
        }

        foreach ($files as $file) {
            $stored[] = $file->store($directory, 'public');
        }

        return $stored;
    }
}
