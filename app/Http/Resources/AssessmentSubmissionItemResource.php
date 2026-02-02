<?php

namespace App\Http\Resources;

class AssessmentSubmissionItemResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'question_type' => $this->question_type,
            'bank_question_id' => $this->bank_question_id,
            'inline_question_index' => $this->inline_question_index,
            'question_data' => $this->question_data,
            'answer' => $this->answer,
            'is_correct' => $this->is_correct,
            'marks_awarded' => $this->marks_awarded,
            'time_spent' => $this->time_spent,
            'grading_metadata' => $this->grading_metadata,
            'detailed_feedback' => $this->detailed_feedback,
            'ai_grading_flag' => $this->whenLoaded('aiGradingFlag', function () {
                return [
                    'id' => $this->aiGradingFlag?->id,
                    'status' => $this->aiGradingFlag?->status,
                    'flag_reason' => $this->aiGradingFlag?->flag_reason,
                    'student_explanation' => $this->aiGradingFlag?->student_explanation,
                ];
            }),
        ];
    }
}
