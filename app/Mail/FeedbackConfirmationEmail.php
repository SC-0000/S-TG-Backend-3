<?php

namespace App\Mail;

use App\Models\Feedback;
use App\Models\Organization;

class FeedbackConfirmationEmail extends BrandedMailable
{
    public $feedback;

    /**
     * Create a new message instance.
     *
     * @param Feedback $feedback
     * @param Organization|null $organization
     */
    public function __construct(Feedback $feedback, ?Organization $organization = null)
    {
        $this->feedback = $feedback;
        $this->organization = $organization
            ?? $this->resolveOrganization($feedback->organization_id);
    }

    public function build()
    {
        return $this->subject('Feedback Confirmation')
                    ->view('emails.feedback_confirmation')
                    ->text('emails.feedback_confirmation_plain')
                    ->with($this->brandingData([
                        'feedback' => $this->feedback,
                    ]));
    }
}
