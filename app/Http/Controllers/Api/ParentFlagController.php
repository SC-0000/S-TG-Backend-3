<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\Flags\FlagStoreRequest;
use App\Models\AIGradingFlag;
use App\Models\AssessmentSubmissionItem;
use App\Models\Child;
use Illuminate\Http\JsonResponse;

class ParentFlagController extends ApiController
{
    public function store(FlagStoreRequest $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $data = $request->validated();

        $submissionItem = AssessmentSubmissionItem::with('submission')
            ->where('id', $data['assessment_submission_item_id'])
            ->first();

        if (!$submissionItem) {
            return $this->error('Submission item not found.', [], 404);
        }

        $child = Child::where('id', $data['child_id'])
            ->where('user_id', $user->id)
            ->first();

        if (!$child) {
            return $this->error('Child not found or unauthorized.', [], 403);
        }

        if ((int) $submissionItem->submission->child_id !== (int) $child->id) {
            return $this->error('Submission does not belong to this child.', [], 403);
        }

        $existingFlag = AIGradingFlag::where('assessment_submission_item_id', $data['assessment_submission_item_id'])
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if ($existingFlag) {
            return $this->error('This question has already been flagged for review.', [], 409);
        }

        $flag = AIGradingFlag::create([
            'assessment_submission_item_id' => $data['assessment_submission_item_id'],
            'user_id' => $user->id,
            'child_id' => $data['child_id'],
            'flag_reason' => $data['flag_reason'],
            'student_explanation' => $data['student_explanation'],
            'original_grade' => $data['original_grade'],
            'status' => 'pending',
        ]);

        \App\Http\Controllers\Admin\FlagController::createAdminTaskForFlag($flag);

        return $this->success([
            'flag' => [
                'id' => $flag->id,
                'status' => $flag->status,
            ],
            'message' => 'Review request submitted successfully.',
        ], status: 201);
    }
}
