<?php

namespace App\Http\Resources;

class AIGradingFlagResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'assessment_submission_item_id' => $this->assessment_submission_item_id,
            'user_id' => $this->user_id,
            'child_id' => $this->child_id,
            'flag_reason' => $this->flag_reason,
            'student_explanation' => $this->student_explanation,
            'status' => $this->status,
            'admin_response' => $this->admin_response,
            'admin_user_id' => $this->admin_user_id,
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'original_grade' => $this->original_grade,
            'final_grade' => $this->final_grade,
            'grade_changed' => (bool) $this->grade_changed,
            'reason_label' => $this->reason_label,
            'status_label' => $this->status_label,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'child' => $this->whenLoaded('child', function () {
                return [
                    'id' => $this->child?->id,
                    'child_name' => $this->child?->child_name,
                    'user_id' => $this->child?->user_id,
                ];
            }),
            'parent' => $this->whenLoaded('child', function () {
                return $this->child?->user ? [
                    'id' => $this->child->user->id,
                    'name' => $this->child->user->name,
                    'email' => $this->child->user->email,
                ] : null;
            }),
            'submission_item' => $this->whenLoaded('submissionItem', function () {
                $item = $this->submissionItem;
                return [
                    'id' => $item?->id,
                    'assessment_submission_id' => $item?->assessment_submission_id,
                    'question_id' => $item?->question_id,
                    'marks' => $item?->marks,
                    'marks_awarded' => $item?->marks_awarded,
                    'is_correct' => $item?->is_correct,
                    'submission' => $item?->relationLoaded('submission') ? [
                        'id' => $item->submission?->id,
                        'assessment_id' => $item->submission?->assessment_id,
                    ] : null,
                    'assessment' => $item?->relationLoaded('submission') && $item->submission?->relationLoaded('assessment')
                        ? [
                            'id' => $item->submission->assessment?->id,
                            'title' => $item->submission->assessment?->title,
                            'organization_id' => $item->submission->assessment?->organization_id,
                        ]
                        : null,
                    'question' => $item?->relationLoaded('question') ? [
                        'id' => $item->question?->id,
                        'title' => $item->question?->title,
                        'question_type' => $item->question?->question_type,
                    ] : null,
                ];
            }),
            'admin_user' => $this->whenLoaded('adminUser', function () {
                return [
                    'id' => $this->adminUser?->id,
                    'name' => $this->adminUser?->name,
                    'email' => $this->adminUser?->email,
                ];
            }),
        ];
    }
}
