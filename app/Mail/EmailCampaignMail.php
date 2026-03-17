<?php

namespace App\Mail;

use App\Models\NewsletterCampaign;
use App\Models\Organization;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class EmailCampaignMail extends BrandedMailable
{
    public function __construct(
        public NewsletterCampaign $campaign,
        public ?Organization $organization,
        public string $contentHtml,
        public ?string $contentText = null,
        public ?string $unsubscribeUrl = null,
        public ?string $recipientName = null
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->campaign->subject
        );
    }

    public function content(): Content
    {
        $this->setOrganization($this->organization);
        $branding = $this->brandingData([
            'contentHtml' => $this->contentHtml,
            'contentText' => $this->contentText,
            'unsubscribeUrl' => $this->unsubscribeUrl,
            'recipientName' => $this->recipientName,
            'subject' => $this->campaign->subject,
        ]);

        return new Content(
            view: 'emails.campaigns.default',
            with: $branding
        );
    }
}
