<?php

namespace App\Mail;

use App\Models\Application;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class VerificationReminder extends BrandedMailable
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
        $verifyUrl = rtrim($this->portalBaseUrl($this->organization) ?? config('app.frontend_url'), '/')
            . '/applications/verify/' . $this->application->verification_token;

        return $this->subject('Reminder: Verify Your Email to Complete Your Application')
                    ->view('emails.verification_reminder')
                    ->text('emails.verification_reminder_plain')
                    ->with($this->brandingData([
                        'application'   => $this->application,
                        'applicantName' => $this->application->applicant_name,
                        'verifyUrl'     => $verifyUrl,
                    ]));
    }
}
