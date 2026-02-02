<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Submissions\SubmissionGradeRequest;
use App\Http\Resources\AssessmentSubmissionResource;
use App\Jobs\GenerateAssessmentReportJob;
use App\Models\AIGradingFlag;
use App\Models\AppNotification;
use App\Models\AssessmentSubmission;
use App\Models\User;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubmissionController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = AssessmentSubmission::query()
            ->with(['assessment:id,title,organization_id', 'child:id,child_name,year_group,organization_id']);

        $this->applyRoleScope($request, $query);

        ApiQuery::applyFilters($query, $request, [
            'status' => true,
            'assessment_id' => true,
            'child_id' => true,
            'user_id' => true,
        ]);

        ApiQuery::applySort($query, $request, ['created_at', 'finished_at', 'marks_obtained'], '-finished_at');

        $submissions = $query->paginate(ApiPagination::perPage($request, 20));
        $data = AssessmentSubmissionResource::collection($submissions->items())->resolve();

        return $this->paginated($submissions, $data);
    }

    public function show(Request $request, AssessmentSubmission $submission): JsonResponse
    {
        if (!$this->canViewSubmission($request, $submission)) {
            return $this->error('Not found.', [], 404);
        }

        $submission->load([
            'assessment:id,title,organization_id',
            'child:id,child_name,year_group,organization_id',
            'items' => fn ($q) => $q->orderBy('created_at'),
            'items.aiGradingFlag',
        ]);

        $data = (new AssessmentSubmissionResource($submission))->resolve();

        return $this->success($data);
    }

    public function update(SubmissionGradeRequest $request, AssessmentSubmission $submission): JsonResponse
    {
        if (!$this->canManageSubmission($request, $submission)) {
            return $this->error('Unauthorized access.', [], 403);
        }

        $payload = $request->validated();

        foreach ($payload['items'] as $itemId => $marksAwarded) {
            $item = $submission->items()->find($itemId);
            if (!$item) {
                continue;
            }

            $hasFlag = AIGradingFlag::where('assessment_submission_item_id', $itemId)
                ->where('status', 'pending')
                ->exists();

            if ($item->marks_awarded === null || $item->requires_manual_grading || $hasFlag) {
                $originalGrade = $item->marks_awarded;

                $item->update([
                    'marks_awarded' => (float) $marksAwarded,
                    'is_correct' => $marksAwarded > 0,
                    'grading_metadata' => array_merge($item->grading_metadata ?? [], [
                        'manually_graded' => true,
                        'graded_by' => $request->user()?->id,
                        'graded_at' => now()->toDateTimeString(),
                        'original_ai_grade' => $originalGrade,
                        'grade_changed_due_to_flag' => $hasFlag,
                    ]),
                ]);

                if ($hasFlag) {
                    $flag = AIGradingFlag::where('assessment_submission_item_id', $itemId)
                        ->where('status', 'pending')
                        ->first();
                    if ($flag) {
                        $flag->update([
                            'status' => 'resolved',
                            'admin_user_id' => $request->user()?->id,
                            'final_grade' => (float) $marksAwarded,
                            'grade_changed' => $originalGrade != $marksAwarded,
                            'admin_response' => 'Grade reviewed and ' . ($originalGrade != $marksAwarded ? 'updated' : 'confirmed') . ' after student flag.',
                            'reviewed_at' => now(),
                        ]);
                    }
                }
            }
        }

        $totalObtained = $submission->items()->sum('marks_awarded') ?? 0;

        $currentAnswers = $submission->answers_json ?? [];
        $updatedAnswers = [];
        $allItems = $submission->items()->get();

        foreach ($currentAnswers as $index => $answer) {
            $updatedAnswer = $answer;
            $item = $allItems->get($index);
            if ($item) {
                if (isset($updatedAnswer['grading_result'])) {
                    $updatedAnswer['grading_result']['marks_awarded'] = $item->marks_awarded;
                    $updatedAnswer['grading_result']['is_correct'] = $item->is_correct;

                    if ($item->grading_metadata && isset($item->grading_metadata['manually_graded'])) {
                        $updatedAnswer['grading_result']['manually_reviewed'] = true;
                        $updatedAnswer['grading_result']['reviewed_by'] = $item->grading_metadata['graded_by'] ?? null;
                        $updatedAnswer['grading_result']['reviewed_at'] = $item->grading_metadata['graded_at'] ?? null;
                    }
                } else {
                    $updatedAnswer['grading_result'] = [
                        'marks_awarded' => $item->marks_awarded,
                        'is_correct' => $item->is_correct,
                        'manually_reviewed' => true,
                        'reviewed_by' => $request->user()?->id,
                        'reviewed_at' => now()->toDateTimeString(),
                    ];
                }

                $updatedAnswer['marks_awarded'] = $item->marks_awarded;
                $updatedAnswer['is_correct'] = $item->is_correct;
            }

            $updatedAnswers[] = $updatedAnswer;
        }

        $submission->update([
            'marks_obtained' => $totalObtained,
            'status' => 'graded',
            'graded_at' => now(),
            'meta->comment' => $payload['overall_comment'] ?? null,
            'answers_json' => $updatedAnswers,
        ]);

        if ($submission->status === 'graded') {
            $child = $submission->child;
            if ($submission->user_id) {
                AppNotification::create([
                    'user_id' => $submission->user_id,
                    'title' => "Assessment Graded: {$submission->assessment->title}",
                    'message' => "For \"{$child->child_name}\": Your child's assessment has been graded.",
                    'type' => 'assessment',
                    'status' => 'unread',
                    'channel' => 'in-app',
                ]);
            }

            dispatch(new GenerateAssessmentReportJob($submission));
        }

        $submission->load([
            'assessment:id,title,organization_id',
            'child:id,child_name,year_group,organization_id',
            'items.aiGradingFlag',
        ]);
        $data = (new AssessmentSubmissionResource($submission))->resolve();

        return $this->success([
            'submission' => $data,
            'message' => 'Marks saved successfully.',
        ]);
    }

    private function applyRoleScope(Request $request, $query): void
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id');

        if ($user?->isSuperAdmin()) {
            if ($request->filled('organization_id')) {
                $query->whereHas('assessment', function ($q) use ($request) {
                    $q->where('organization_id', $request->integer('organization_id'));
                });
            }
            return;
        }

        if ($user?->isAdmin() || $user?->isTeacher()) {
            $query->whereHas('assessment', function ($q) use ($orgId) {
                $q->visibleToOrg($orgId);
            });
            return;
        }

        if ($user && ($user->isParent() || $user->role === User::ROLE_GUEST_PARENT)) {
            $childIds = $user->children()->pluck('id')->all();
            $query->whereIn('child_id', $childIds);
            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function canViewSubmission(Request $request, AssessmentSubmission $submission): bool
    {
        $user = $request->user();
        if (!$user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        $orgId = $request->attributes->get('organization_id');
        if ($user->isAdmin() || $user->isTeacher()) {
            return $submission->assessment?->is_global || (int) $submission->assessment?->organization_id === (int) $orgId;
        }

        if ($user->isParent() || $user->role === User::ROLE_GUEST_PARENT) {
            return (int) $submission->child?->user_id === (int) $user->id;
        }

        return false;
    }

    private function canManageSubmission(Request $request, AssessmentSubmission $submission): bool
    {
        $user = $request->user();
        if (!$user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        $orgId = $request->attributes->get('organization_id');
        if ($user->isAdmin() || $user->isTeacher()) {
            return $submission->assessment?->is_global || (int) $submission->assessment?->organization_id === (int) $orgId;
        }

        return false;
    }
}
