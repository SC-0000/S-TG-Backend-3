<?php

namespace App\Http\Resources;

class HomeworkAssignmentResource extends ApiResource
{
    public function toArray($request): array
    {
        $requestOrigin = request()?->getSchemeAndHttpHost();
        $attachments = $this->attachments ?? [];
        $attachments = collect($attachments)->map(function ($path) use ($requestOrigin) {
            if (!$path) {
                return null;
            }
            if (is_string($path) && preg_match('/^https?:\/\//i', $path)) {
                return $path;
            }
            $relativePath = str_starts_with($path, '/storage/')
                ? $path
                : '/storage/' . ltrim((string) $path, '/');

            return $requestOrigin ? "{$requestOrigin}{$relativePath}" : $relativePath;
        })->filter()->values()->all();

        $items = $this->relationLoaded('items') ? $this->items : collect();
        $targets = $this->relationLoaded('targets') ? $this->targets : collect();

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'title' => $this->title,
            'description' => $this->description,
            'subject' => $this->subject,
            'journey_category_id' => $this->journey_category_id,
            'due_date' => $this->due_date?->toISOString(),
            'available_from' => $this->available_from?->toISOString(),
            'grading_mode' => $this->grading_mode,
            'status' => $this->status,
            'visibility' => $this->visibility,
            'assigned_by' => $this->assigned_by,
            'assigned_by_role' => $this->assigned_by_role,
            'settings' => $this->settings,
            'attachments' => $attachments,
            'items' => HomeworkItemResource::collection($items)->resolve(),
            'hydrated_items' => $this->hydrated_items ?? null,
            'targets' => HomeworkTargetResource::collection($targets)->resolve(),
            'targets_count' => $this->targets_count ?? null,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
