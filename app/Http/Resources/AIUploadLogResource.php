<?php

namespace App\Http\Resources;

class AIUploadLogResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->session_id,
            'proposal_id' => $this->proposal_id,
            'level' => $this->level,
            'action' => $this->action,
            'message' => $this->message,
            'context' => $this->context,
            'ai_model' => $this->ai_model,
            'tokens_input' => $this->tokens_input,
            'tokens_output' => $this->tokens_output,
            'cost_usd' => $this->cost_usd,
            'duration_ms' => $this->duration_ms,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
