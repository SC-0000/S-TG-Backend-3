<?php

namespace App\Mail;

use App\Models\Application;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class ApplicationRejected extends BrandedMailable
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
        $contactUrl = $this->portalUrl('/contact', $this->organization)
            ?? $this->portalUrl('/', $this->organization)
            ?? config('app.frontend_url');

        return $this->subject('Update on Your Application')
                    ->view('emails.application_rejected')
                    ->text('emails.application_rejected_plain')
                    ->with($this->brandingData([
                        'application'   => $this->application,
                        'applicantName' => $this->application->applicant_name,
                        'feedback'      => $this->application->admin_feedback,
                        'contactUrl'    => $contactUrl,
                    ]));
    }
}
