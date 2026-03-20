<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Homework\HomeworkSubmissionStoreRequest;
use App\Http\Requests\Api\Homework\HomeworkSubmissionUpdateRequest;
use App\Http\Resources\HomeworkSubmissionResource;
use App\Mail\HomeworkNotificationMail;
use App\Models\AppNotification;
use App\Models\HomeworkAssignment;
use App\Models\HomeworkSubmission;
use App\Models\Question;
use App\Models\User;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use App\Support\MailContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class HomeworkSubmissionController extends ApiController
{
    public function indexAll(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        if (in_array($user->role, ['parent', 'guest_parent'], true)) {
            return $this->error('Unauthorized access.', [], 403);
        }

        $query = HomeworkSubmission::query();

        $orgId = $request->attributes->get('organization_id');
        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        ApiQuery::applyFilters($query, $request, [
            'submission_status' => true,
            'student_id' => true,
            'assignment_id' => true,
        ]);

        ApiQuery::applySort($query, $request, ['created_at', 'submitted_at', 'reviewed_at'], '-created_at');

        $submissions = $query->paginate(ApiPagination::perPage($request, 20));
        $data = HomeworkSubmissionResource::collection($submissions->items())->resolve();

        return $this->paginated($submissions, $data);
    }

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

        $assignmentItems = $homework->relationLoaded('items')
            ? $homework->items
            : $homework->items()->get();
        $requiresAttachment = $assignmentItems->contains(fn ($item) => $item->type === 'file_upload');
        $submissionStatus = $payload['submission_status'] ?? 'submitted';
        if ($requiresAttachment && $submissionStatus === 'submitted' && !$request->hasFile('attachments')) {
            return $this->error('Please attach at least one file for this homework.', [
                ['field' => 'attachments', 'message' => 'Please attach at least one file for this homework.'],
            ], 422);
        }

        $settings = $homework->settings ?? [];
        $maxAttempts = isset($settings['max_attempts']) ? (int) $settings['max_attempts'] : null;
        if ($maxAttempts && $maxAttempts > 0) {
            $attemptCount = HomeworkSubmission::query()
                ->where('assignment_id', $homework->id)
                ->where('student_id', $child->id)
                ->count();
            if ($attemptCount >= $maxAttempts) {
                return $this->error('Submission limit reached for this homework.', [], 422);
            }
            $payload['attempt'] = $attemptCount + 1;
        } else {
            $attemptCount = HomeworkSubmission::query()
                ->where('assignment_id', $homework->id)
                ->where('student_id', $child->id)
                ->count();
            $payload['attempt'] = $attemptCount + 1;
        }

        $payload['assignment_id'] = $homework->id;
        $payload['student_id'] = $child->id;
        $payload['organization_id'] = $homework->organization_id;

        $status = $submissionStatus;
        $payload['submission_status'] = $status;
        if ($status === 'submitted') {
            $payload['submitted_at'] = now();
        }

        // Auto-grade if enabled and possible
        $gradingMode = $homework->grading_mode ?? 'manual';
        if ($gradingMode === 'auto') {
            $assignment = $homework->loadMissing('items');
            $contentPayload = $this->parseContentPayload($payload['content'] ?? null);
            $responses = $contentPayload['responses'] ?? [];
            if ($assignment && !empty($responses)) {
                [$grading, $summary] = $this->buildAutoGrading($assignment, $responses);
                if (!empty($grading)) {
                    $contentPayload['grading'] = $grading;
                    $contentPayload['grading_summary'] = $summary;
                    $payload['content'] = json_encode($contentPayload);
                    if (empty($payload['grade']) && ($summary['max_score'] ?? 0) > 0) {
                        $payload['grade'] = $summary['score'] . '/' . $summary['max_score'];
                    }
                }

                $allAutoGradable = $assignment->items->every(
                    fn ($item) => $item->type === 'question_bank'
                );
                if ($allAutoGradable && !empty($grading)) {
                    $payload['submission_status'] = 'graded';
                    $payload['reviewed_at'] = now();
                }
            }
        }

        $attachments = $this->storeAttachments($request, 'homework_submissions');
        if (!empty($attachments)) {
            $payload['attachments'] = $attachments;
        }

        $submission = HomeworkSubmission::create($payload);
        $data = (new HomeworkSubmissionResource($submission))->resolve();

        $child = $child ?? $submission->child;
        $orgId = $homework->organization_id;
        $organization = MailContext::resolveOrganization($orgId, null, $homework, $request);
        $title = "Homework submitted: {$homework->title}";
        $message = $child
            ? "Student {$child->child_name} submitted homework."
            : 'A homework submission has been received.';

        $recipients = User::whereIn('role', ['admin', 'teacher', 'super_admin'])
            ->when($orgId, fn ($q) => $q->where('current_organization_id', $orgId))
            ->get();

        if ($homework->assigned_by) {
            $assigned = User::find($homework->assigned_by);
            if ($assigned) {
                $recipients = $recipients->push($assigned);
            }
        }

        $recipients = $recipients->unique('id');
        foreach ($recipients as $user) {
            AppNotification::create([
                'user_id' => $user->id,
                'title' => $title,
                'message' => $message,
                'type' => 'task',
                'status' => 'unread',
                'channel' => 'in-app',
            ]);

            if ($user->email) {
                $mail = new HomeworkNotificationMail(
                    $title,
                    $message,
                    "/admin/homework/{$homework->id}",
                    $organization,
                    'View Homework'
                );
                MailContext::sendMailable($user->email, $mail);
            }
        }

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
        $wasGraded = $submission->submission_status === 'graded';
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
                'manual_grades',
            ]));

            if (isset($payload['submission_status']) && $payload['submission_status'] === 'graded') {
                $payload['reviewed_at'] = now();
                if (!isset($payload['graded_by'])) {
                    $payload['graded_by'] = $user?->id;
                }
            }
        }

        $attachments = $this->storeAttachments($request, 'homework_submissions');
        if (!empty($attachments)) {
            $payload['attachments'] = array_values(array_merge($submission->attachments ?? [], $attachments));
        }

        if (!in_array($user->role, ['parent', 'guest_parent'], true)) {
            $assignment = $submission->assignment()->with('items')->first();
            $contentPayload = $this->parseContentPayload($payload['content'] ?? $submission->content);
            $responses = $contentPayload['responses'] ?? [];
            $grading = is_array($contentPayload['grading'] ?? null) ? $contentPayload['grading'] : [];

            if (
                ($payload['submission_status'] ?? null) === 'graded'
                && $assignment
                && ($assignment->grading_mode ?? 'manual') === 'auto'
                && !empty($responses)
            ) {
                [$autoGrading] = $this->buildAutoGrading($assignment, $responses);
                if (!empty($autoGrading)) {
                    $grading = array_merge($grading, $autoGrading);
                }
            }

            if (!empty($payload['manual_grades']) && $assignment) {
                $grading = $this->mergeManualGrading($grading, $payload['manual_grades'], $assignment);
            }

            if (!empty($grading)) {
                $summary = $this->buildGradingSummary($grading);
                $contentPayload['grading'] = $grading;
                $contentPayload['grading_summary'] = $summary;
                $payload['content'] = json_encode($contentPayload);
                if (empty($payload['grade']) && ($summary['max_score'] ?? 0) > 0) {
                    $payload['grade'] = $summary['score'] . '/' . $summary['max_score'];
                }
            }
        }

        unset($payload['manual_grades']);

        $submission->update($payload);
        $data = (new HomeworkSubmissionResource($submission))->resolve();

        if (($payload['submission_status'] ?? null) === 'graded' && ! $wasGraded) {
            $submission->load('assignment', 'child.user');
            $parent = $submission->child?->user;
            if ($parent) {
                $title = "Homework graded: {$submission->assignment?->title}";
                $message = "For \"{$submission->child?->child_name}\": Your child's homework has been graded.";
                AppNotification::create([
                    'user_id' => $parent->id,
                    'title' => $title,
                    'message' => $message,
                    'type' => 'task',
                    'status' => 'unread',
                    'channel' => 'in-app',
                ]);

                $organization = MailContext::resolveOrganization($submission->organization_id, $parent, $submission, $request);
                if ($parent->email) {
                    $mail = new HomeworkNotificationMail(
                        $title,
                        $message,
                        "/portal/homework/{$submission->assignment_id}",
                        $organization,
                        'View Homework',
                        $submission->child?->child_name
                    );
                    MailContext::sendMailable($parent->email, $mail);
                }
            }
        }

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

    private function parseContentPayload(mixed $content): array
    {
        if (is_array($content)) {
            return $content;
        }

        if (!is_string($content) || trim($content) === '') {
            return ['responses' => []];
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return ['responses' => [], 'raw' => $content];
    }

    private function mergeManualGrading(array $existing, array $manualGrades, HomeworkAssignment $assignment): array
    {
        $itemMap = $assignment->items->keyBy(fn ($item) => "item_{$item->id}");

        foreach ($manualGrades as $itemKey => $manual) {
            if (!isset($itemMap[$itemKey]) || !is_array($manual)) {
                continue;
            }

            $item = $itemMap[$itemKey];
            $score = isset($manual['score']) ? (float) $manual['score'] : 0;
            $maxScore = isset($manual['max_score']) ? (float) $manual['max_score'] : 0;
            $feedback = $manual['feedback'] ?? null;
            $rubric = is_array($manual['rubric'] ?? null) ? $manual['rubric'] : [];

            $entry = $existing[$itemKey] ?? [];
            $entry['item_type'] = $item->type;
            $entry['ref_id'] = $item->ref_id;
            $entry['question_id'] = $item->type === 'question_bank' ? $item->ref_id : ($entry['question_id'] ?? null);
            $entry['score'] = $score;
            $entry['max_score'] = $maxScore;
            $entry['feedback'] = $feedback;
            $entry['rubric'] = $rubric;
            $entry['manual_graded'] = true;
            $entry['auto_graded'] = (bool) ($entry['auto_graded'] ?? false);
            $entry['requires_manual_grading'] = false;
            if ($maxScore > 0) {
                $entry['is_correct'] = $score >= $maxScore;
                $entry['percentage'] = round(($score / $maxScore) * 100, 2);
            }

            $existing[$itemKey] = $entry;
        }

        return $existing;
    }

    private function buildGradingSummary(array $grading): array
    {
        $summary = [
            'score' => 0,
            'max_score' => 0,
            'auto_graded' => false,
            'manual_graded' => false,
        ];

        foreach ($grading as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $summary['score'] += (float) ($entry['score'] ?? 0);
            $summary['max_score'] += (float) ($entry['max_score'] ?? 0);
            if (!empty($entry['auto_graded'])) {
                $summary['auto_graded'] = true;
            }
            if (!empty($entry['manual_graded'])) {
                $summary['manual_graded'] = true;
            }
        }

        if ($summary['max_score'] > 0) {
            $summary['percentage'] = round(($summary['score'] / $summary['max_score']) * 100, 2);
        }

        return $summary;
    }

    private function buildAutoGrading(HomeworkAssignment $assignment, array $responses): array
    {
        $grading = [];
        $summary = [
            'score' => 0,
            'max_score' => 0,
            'auto_graded' => false,
        ];

        foreach ($assignment->items as $item) {
            if ($item->type !== 'question_bank' || ! $item->ref_id) {
                continue;
            }

            $key = "item_{$item->id}";
            if (!array_key_exists($key, $responses)) {
                continue;
            }

            $question = Question::find($item->ref_id);
            if (! $question) {
                continue;
            }

            $rawResponse = $responses[$key];
            $responsePayload = [];
            if (is_array($rawResponse)) {
                $responsePayload['selected_options'] = $rawResponse;
            } elseif (is_string($rawResponse)) {
                $responsePayload['answer'] = $rawResponse;
            } else {
                continue;
            }

            $result = $question->gradeResponse($responsePayload);
            $grading[$key] = array_merge($result, [
                'question_id' => $question->id,
                'auto_graded' => true,
            ]);

            $summary['score'] += $result['score'] ?? 0;
            $summary['max_score'] += $result['max_score'] ?? 0;
            $summary['auto_graded'] = true;
        }

        if ($summary['max_score'] > 0) {
            $summary['percentage'] = round(($summary['score'] / $summary['max_score']) * 100, 2);
        }

        return [$grading, $summary];
    }
}
