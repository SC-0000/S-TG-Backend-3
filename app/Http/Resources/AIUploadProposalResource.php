<?php

namespace App\Http\Resources;

class AIUploadProposalResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->session_id,
            'content_type' => $this->content_type,
            'status' => $this->status,
            'proposed_data' => $this->proposed_data,
            'original_data' => $this->original_data,
            'is_valid' => (bool) $this->is_valid,
            'validation_errors' => $this->validation_errors,
            'quality_score' => $this->quality_score,
            'quality_metrics' => $this->quality_metrics,
            'parent_proposal_id' => $this->parent_proposal_id,
            'parent_type' => $this->parent_type,
            'order_position' => $this->order_position,
            'created_model_type' => $this->created_model_type,
            'created_model_id' => $this->created_model_id,
            'user_modifications' => $this->user_modifications,
            'modified_by' => $this->modified_by,
            'modified_at' => $this->modified_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
