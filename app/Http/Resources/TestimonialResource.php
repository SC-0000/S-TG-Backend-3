<?php

namespace App\Http\Resources;

use Illuminate\Support\Facades\Storage;

class TestimonialResource extends ApiResource
{
    public function toArray($request): array
    {
        $attachments = $this->Attachments;
        $attachmentUrls = [];

        if (is_array($attachments)) {
            $attachmentUrls = collect($attachments)->map(fn ($path) => $path ? Storage::url($path) : null)
                ->filter()
                ->values()
                ->all();
        } elseif (is_string($attachments) && $attachments !== '') {
            $attachmentUrls = [Storage::url($attachments)];
        }

        return [
            'id' => $this->TestimonialID,
            'organization_id' => $this->organization_id,
            'user_name' => $this->UserName,
            'user_email' => $this->UserEmail,
            'message' => $this->Message,
            'rating' => $this->Rating,
            'attachments' => $attachments,
            'attachment_urls' => $attachmentUrls,
            'status' => $this->Status,
            'admin_comment' => $this->AdminComment,
            'submission_date' => $this->SubmissionDate?->toISOString(),
            'user_ip' => $this->UserIP,
            'display_order' => $this->DisplayOrder,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
