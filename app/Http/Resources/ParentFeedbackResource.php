<?php

namespace App\Http\Resources;

use Illuminate\Support\Facades\Storage;

class ParentFeedbackResource extends ApiResource
{
    public function toArray($request): array
    {
        $attachments = $this->attachments ?? [];
        $attachmentUrls = collect($attachments)->map(function ($path) {
            return $path ? Storage::url($path) : null;
        })->filter()->values();

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'user_email' => $this->user_email,
            'category' => $this->category,
            'message' => $this->message,
            'details' => $this->details,
            'attachments' => $attachments,
            'attachment_urls' => $attachmentUrls,
            'status' => $this->status,
            'admin_response' => $this->admin_response,
            'submitted_at' => $this->submitted_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user?->id,
                    'name' => $this->user?->name,
                    'email' => $this->user?->email,
                ];
            }),
        ];
    }
}
