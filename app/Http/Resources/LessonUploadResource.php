<?php

namespace App\Http\Resources;

class LessonUploadResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'child_id' => $this->child_id,
            'lesson_id' => $this->lesson_id,
            'slide_id' => $this->slide_id,
            'block_id' => $this->block_id,
            'file_path' => $this->file_path,
            'file_url' => $this->file_url,
            'file_type' => $this->file_type,
            'file_size_kb' => $this->file_size_kb,
            'original_filename' => $this->original_filename,
            'status' => $this->status,
            'score' => $this->score,
            'feedback' => $this->feedback,
            'ai_analysis' => $this->ai_analysis,
            'reviewed_by' => $this->reviewed_by,
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'child' => $this->whenLoaded('child', function () {
                return [
                    'id' => $this->child?->id,
                    'child_name' => $this->child?->child_name,
                    'user_id' => $this->child?->user_id,
                ];
            }),
            'lesson' => $this->whenLoaded('lesson', function () {
                return [
                    'id' => $this->lesson?->id,
                    'title' => $this->lesson?->title,
                ];
            }),
            'slide' => $this->whenLoaded('slide', function () {
                return [
                    'id' => $this->slide?->id,
                    'title' => $this->slide?->title,
                    'order_position' => $this->slide?->order_position,
                ];
            }),
            'reviewer' => $this->whenLoaded('reviewer', function () {
                return [
                    'id' => $this->reviewer?->id,
                    'name' => $this->reviewer?->name,
                    'email' => $this->reviewer?->email,
                ];
            }),
        ];
    }
}
