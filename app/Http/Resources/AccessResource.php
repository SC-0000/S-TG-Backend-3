<?php

namespace App\Http\Resources;

class AccessResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'child_id' => $this->child_id,
            'lesson_id' => $this->lesson_id,
            'content_lesson_id' => $this->content_lesson_id,
            'assessment_id' => $this->assessment_id,
            'lesson_ids' => $this->lesson_ids,
            'course_ids' => $this->course_ids,
            'module_ids' => $this->module_ids,
            'assessment_ids' => $this->assessment_ids,
            'transaction_id' => $this->transaction_id,
            'invoice_id' => $this->invoice_id,
            'purchase_date' => $this->purchase_date?->toISOString(),
            'due_date' => $this->due_date?->toDateString(),
            'access' => (bool) $this->access,
            'payment_status' => $this->payment_status,
            'refund_id' => $this->refund_id,
            'metadata' => $this->metadata,
            'child' => $this->whenLoaded('child', function () {
                return [
                    'id' => $this->child?->id,
                    'child_name' => $this->child?->child_name,
                    'organization_id' => $this->child?->organization_id,
                    'user' => $this->child?->user
                        ? [
                            'id' => $this->child->user->id,
                            'name' => $this->child->user->name,
                            'email' => $this->child->user->email,
                        ]
                        : null,
                ];
            }),
            'lesson_session' => $this->whenLoaded('lesson', function () {
                return [
                    'id' => $this->lesson?->id,
                    'session_code' => $this->lesson?->session_code,
                    'status' => $this->lesson?->status,
                    'scheduled_start_time' => $this->lesson?->scheduled_start_time?->toISOString(),
                ];
            }),
            'content_lesson' => $this->whenLoaded('contentLesson', function () {
                return [
                    'id' => $this->contentLesson?->id,
                    'title' => $this->contentLesson?->title,
                ];
            }),
            'assessment' => $this->whenLoaded('assessment', function () {
                return [
                    'id' => $this->assessment?->id,
                    'title' => $this->assessment?->title,
                ];
            }),
        ];
    }
}
