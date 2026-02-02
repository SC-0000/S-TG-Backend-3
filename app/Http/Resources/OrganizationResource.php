<?php

namespace App\Http\Resources;

class OrganizationResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status,
            'owner_id' => $this->owner_id,
            'settings' => $this->when(isset($this->settings), $this->settings),
            'counts' => [
                'users' => $this->when(isset($this->users_count), $this->users_count),
                'articles' => $this->when(isset($this->articles_count), $this->articles_count),
                'assessments' => $this->when(isset($this->assessments_count), $this->assessments_count),
                'live_lesson_sessions' => $this->when(isset($this->live_lesson_sessions_count), $this->live_lesson_sessions_count),
                'content_lessons' => $this->when(isset($this->content_lessons_count), $this->content_lessons_count),
                'services' => $this->when(isset($this->services_count), $this->services_count),
                'children' => $this->when(isset($this->children_count), $this->children_count),
                'transactions' => $this->when(isset($this->transactions_count), $this->transactions_count),
                'applications' => $this->when(isset($this->applications_count), $this->applications_count),
            ],
            'owner' => $this->whenLoaded('owner', fn () => [
                'id' => $this->owner?->id,
                'name' => $this->owner?->name,
                'email' => $this->owner?->email,
            ]),
        ];
    }
}
