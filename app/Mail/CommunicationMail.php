<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class CommunicationMail extends BrandedMailable
{
    public function __construct(
        protected string $subject,
        protected string $bodyText,
        protected ?string $bodyHtml = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subject);
    }

    public function content(): Content
    {
        return new Content(
            view: $this->bodyHtml ? 'emails.communication-html' : 'emails.communication-text',
            with: array_merge($this->brandingData(), [
                'bodyText' => $this->bodyText,
                'bodyHtml' => $this->bodyHtml,
                'emailSubject' => $this->subject,
            ]),
        );
    }
}
