<?php

namespace App\Http\Resources;

class AIUploadSessionResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'organization_id' => $this->organization_id,
            'content_type' => $this->content_type,
            'status' => $this->status,
            'user_prompt' => $this->user_prompt,
            'input_settings' => $this->input_settings,
            'source_data' => $this->source_data,
            'source_type' => $this->source_type,
            'quality_threshold' => $this->quality_threshold,
            'max_iterations' => $this->max_iterations,
            'early_stop_patience' => $this->early_stop_patience,
            'current_iteration' => $this->current_iteration,
            'current_quality_score' => $this->current_quality_score,
            'items_generated' => $this->items_generated,
            'items_approved' => $this->items_approved,
            'items_rejected' => $this->items_rejected,
            'error_message' => $this->error_message,
            'validation_errors' => $this->validation_errors,
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'processing_time_seconds' => $this->processing_time_seconds,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'proposals' => $this->whenLoaded('proposals', function () {
                return AIUploadProposalResource::collection($this->proposals)->resolve();
            }),
            'logs' => $this->whenLoaded('logs', function () {
                return AIUploadLogResource::collection($this->logs)->resolve();
            }),
        ];
    }
}
