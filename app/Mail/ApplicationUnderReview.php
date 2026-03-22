<?php

namespace App\Mail;

use App\Models\Application;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class ApplicationUnderReview extends BrandedMailable
{
    use Queueable, SerializesModels;

    public Application $application;

    public function __construct(Application $application, ?Organization $organization = null)
    {
        $this->application  = $application;
        $this->organization = $organization
            ?? $this->resolveOrganization($application->organization_id, $application->user ?? null);
    }

    public function build()
    {
        return $this->subject('Your Application Is Under Review')
                    ->view('emails.application_under_review')
                    ->text('emails.application_under_review_plain')
                    ->with($this->brandingData([
                        'application'   => $this->application,
                        'applicantName' => $this->application->applicant_name,
                    ]));
    }
}
