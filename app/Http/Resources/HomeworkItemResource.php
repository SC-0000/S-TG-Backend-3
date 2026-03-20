<?php

namespace App\Http\Resources;

class HomeworkItemResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'homework_id' => $this->homework_id,
            'type' => $this->type,
            'ref_id' => $this->ref_id,
            'payload' => $this->payload,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
