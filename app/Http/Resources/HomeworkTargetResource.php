<?php

namespace App\Http\Resources;

class HomeworkTargetResource extends ApiResource
{
    public function toArray($request): array
    {
        $child = $this->child;
        $parent = $child?->user;

        return [
            'id' => $this->id,
            'homework_id' => $this->homework_id,
            'child_id' => $this->child_id,
            'child_name' => $child?->child_name,
            'year_group' => $child?->year_group,
            'parent_id' => $parent?->id,
            'parent_name' => $parent?->name,
            'assigned_by' => $this->assigned_by,
            'assigned_at' => $this->assigned_at?->toISOString(),
        ];
    }
}
