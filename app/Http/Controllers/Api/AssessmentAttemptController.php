<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Assessments\AssessmentAttemptSubmitRequest;
use App\Http\Resources\AssessmentSubmissionResource;
use App\Jobs\GenerateAssessmentReportJob;
use App\Models\Access;
use App\Models\AdminTask;
use App\Models\Assessment;
use App\Models\Child;
use App\Models\User;
use App\Services\SubmissionGradingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AssessmentAttemptController extends ApiController
{
    public function start(Request $request, Assessment $assessment): JsonResponse
    {
        if ($response = $this->ensureAssessmentScope($request, $assessment)) {
            return $response;
        }

        $user = $request->user();

        try {
            if (!empty($user?->billing_customer_id) && class_exists(\App\Jobs\SyncBillingInvoicesJob::class)) {
                \App\Jobs\SyncBillingInvoicesJob::dispatch($user->billing_customer_id);
                Log::info('api.assessment.start: dispatched SyncBillingInvoicesJob', [
                    'user_id' => $user?->id,
                    'billing_customer_id' => $user?->billing_customer_id,
                    'assessment_id' => $assessment->id,
                ]);
            } elseif (class_exists(\App\Jobs\SyncAllOpenOrders::class)) {
                \App\Jobs\SyncAllOpenOrders::dispatch();
                Log::info('api.assessment.start: dispatched SyncAllOpenOrders', [
                    'user_id' => $user?->id,
                    'assessment_id' => $assessment->id,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('api.assessment.start: failed to dispatch billing sync job', [
                'error' => $e->getMessage(),
                'user_id' => $user?->id,
                'assessment_id' => $assessment->id,
            ]);
        }

        $childQuery = $this->childQueryForUser($user);

        $children = $childQuery
            ->whereIn('id', function ($query) use ($assessment) {
                $query->select('child_id')
                    ->from('access')
                    ->where('access', true)
                    ->where('payment_status', 'paid')
                    ->where(function ($sub) use ($assessment) {
                        $sub->where('assessment_id', $assessment->id)
                            ->orWhereJsonContains('assessment_ids', $assessment->id);
                    });
            })
            ->withCount([
                'assessmentSubmissions as attempts_so_far' => function ($q) use ($assessment, $user) {
                    $q->where('assessment_id', $assessment->id);
                    if ($user && !$user->isAdmin() && !$user->isSuperAdmin()) {
                        $q->where('user_id', $user->id);
                    }
                },
            ])
            ->get();

        $childId = $request->integer('child_id') ?: null;
        if ($childId && !$assessment->retake_allowed) {
            $already = $assessment->submissions()
                ->where('child_id', $childId)
                ->count();
            if ($already >= 1) {
                return $this->error('This assessment can only be taken once. Retake not allowed.', [], 422);
            }
        }

        $startToken = now()->toIso8601String();
        $allQuestions = $assessment->getAllQuestions();

        return $this->success([
            'assessment' => [
                'id' => $assessment->id,
                'title' => $assessment->title,
                'type' => $assessment->type,
                'time_limit' => $assessment->time_limit,
                'retake_allowed' => $assessment->retake_allowed,
                'availability' => $assessment->availability?->toISOString(),
                'deadline' => $assessment->deadline?->toISOString(),
                'questions' => $allQuestions,
                'start_token' => $startToken,
            ],
            'children' => $children,
            'start_token' => $startToken,
            'is_guest_parent' => ($user?->role ?? '') === User::ROLE_GUEST_PARENT,
            'onboarding_complete' => (bool) ($user?->onboarding_complete ?? false),
        ]);
    }

    public function submit(AssessmentAttemptSubmitRequest $request, Assessment $assessment): JsonResponse
    {
        if ($response = $this->ensureAssessmentScope($request, $assessment)) {
            return $response;
        }

        $payload = $request->validated();
        $user = $request->user();
        $childId = (int) $payload['child_id'];

        if (!$this->canSubmitForChild($user, $assessment, $childId)) {
            return $this->error('You do not have access to this assessment.', [], 403);
        }

        if (!$assessment->retake_allowed) {
            $already = $assessment->submissions()->where('child_id', $childId)->count();
            if ($already >= 1) {
                return $this->error('This assessment can only be taken once. Retake not allowed.', [], 422);
            }
        }

        $gradingService = app(SubmissionGradingService::class);
        $bankQuestions = $assessment->bankQuestions()->orderBy('order_position')->get();

        $totalMarks = 0;
        $obtainedMarks = 0;
        $needsManual = false;
        $submissionItems = [];

        foreach ($bankQuestions as $index => $question) {
            $questionMarks = (int) $question->marks;
            $totalMarks += $questionMarks;

            $studentAnswer = $payload['answers'][$index] ?? [];
            if (!is_array($studentAnswer)) {
                $studentAnswer = ['response' => $studentAnswer];
            }

            $gradingResult = $gradingService->gradeQuestionResponse($question, $studentAnswer, $index);
            $marksEarned = (int) round($gradingResult['marks_awarded']);
            $obtainedMarks += $marksEarned;

            if ($gradingResult['grading_metadata']['requires_human_review'] ?? false) {
                $needsManual = true;
            }

            $submissionItems[] = $gradingResult;
        }

        $status = $needsManual ? 'pending' : 'graded';

        $submission = DB::transaction(function () use ($assessment, $childId, $payload, $totalMarks, $obtainedMarks, $status, $submissionItems, $user) {
            $submission = $assessment->submissions()->create([
                'user_id' => $user?->id,
                'child_id' => $childId,
                'retake_number' => $assessment->submissions()
                        ->where('child_id', $childId)
                        ->count() + 1,
                'total_marks' => $totalMarks,
                'marks_obtained' => $obtainedMarks,
                'status' => $status,
                'started_at' => Carbon::parse($payload['started_at']),
                'finished_at' => now(),
                'answers_json' => $submissionItems,
            ]);

            foreach ($submissionItems as $itemData) {
                $submission->items()->create([
                    'question_id' => null,
                    'question_type' => $itemData['question_type'],
                    'bank_question_id' => $itemData['bank_question_id'],
                    'inline_question_index' => $itemData['inline_question_index'],
                    'question_data' => $itemData['question_data'],
                    'answer' => $itemData['answer'],
                    'is_correct' => $itemData['is_correct'],
                    'marks_awarded' => $itemData['marks_awarded'],
                    'grading_metadata' => $itemData['grading_metadata'],
                    'detailed_feedback' => $itemData['detailed_feedback'],
                    'time_spent' => $itemData['time_spent'],
                ]);
            }

            return $submission;
        });

        if ($status === 'pending') {
            $child = Child::find($childId);
            $assignedTeacher = $child?->assignedTeachers()->first();
            $assignedTo = $assignedTeacher ? $assignedTeacher->id : null;

            $relatedLink = route('admin.submissions.grade', $submission->id);
            if ($assignedTeacher && $assignedTeacher->role === 'teacher' && route()->has('teacher.submissions.grade')) {
                $relatedLink = route('teacher.submissions.grade', $submission->id);
            }

            AdminTask::create([
                'task_type' => 'Grade Assessment Submission',
                'assigned_to' => $assignedTo,
                'status' => 'Pending',
                'related_entity' => $relatedLink,
                'priority' => 'Medium',
                'description' => "Manual grading required for submission #{$submission->id}. Assessment: {$assessment->title}. Student: {$child?->child_name}",
            ]);
        }

        if ($status === 'graded') {
            dispatch(new GenerateAssessmentReportJob($submission));
        }

        $submission->load(['assessment:id,title', 'child:id,child_name,year_group', 'items']);
        $data = (new AssessmentSubmissionResource($submission))->resolve();

        return $this->success([
            'submission' => $data,
            'message' => $status === 'graded'
                ? 'Assessment submitted and graded successfully.'
                : 'Assessment submitted. Some questions require manual grading.',
        ], status: 201);
    }

    private function ensureAssessmentScope(Request $request, Assessment $assessment): ?JsonResponse
    {
        $user = $request->user();
        if ($user?->isSuperAdmin()) {
            return null;
        }

        $orgId = $request->attributes->get('organization_id');
        if ($assessment->is_global || (int) $assessment->organization_id === (int) $orgId) {
            return null;
        }

        return $this->error('Not found.', [], 404);
    }

    private function childQueryForUser(?User $user)
    {
        if (!$user) {
            return Child::query()->whereRaw('1 = 0');
        }

        if ($user->isAdmin() || $user->isSuperAdmin()) {
            return Child::query();
        }

        if ($user->isParent() || $user->role === User::ROLE_GUEST_PARENT) {
            return $user->children();
        }

        return Child::query()->whereRaw('1 = 0');
    }

    private function canSubmitForChild(?User $user, Assessment $assessment, int $childId): bool
    {
        if (!$user) {
            return false;
        }

        if ($user->isAdmin() || $user->isSuperAdmin()) {
            return $this->hasAccessRecord($assessment, $childId);
        }

        if ($user->isParent() || $user->role === User::ROLE_GUEST_PARENT) {
            $ownsChild = Child::where('id', $childId)
                ->where('user_id', $user->id)
                ->exists();

            if (!$ownsChild) {
                return false;
            }

            return $this->hasAccessRecord($assessment, $childId);
        }

        return false;
    }

    private function hasAccessRecord(Assessment $assessment, int $childId): bool
    {
        return Access::where('child_id', $childId)
            ->where('access', true)
            ->where('payment_status', 'paid')
            ->where(function ($q) use ($assessment) {
                $q->where('assessment_id', $assessment->id)
                    ->orWhereJsonContains('assessment_ids', $assessment->id);
            })
            ->exists();
    }
}
