<?php

namespace App\Mail;

use App\Models\Application;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class VerifyApplicationEmail extends BrandedMailable
{
    use Queueable, SerializesModels;

    public $application;

    public function __construct(Application $application, ?Organization $organization = null)
    {
        $this->application = $application;
        $this->organization = $organization
            ?? $this->resolveOrganization($application->organization_id, $application->user ?? null);
    }

    public function build()
    {
        return $this->subject('Verify Your Application')
                    ->view('emails.verify_application')
                    ->text('emails.verify_application_plain')
                    ->with($this->brandingData([
                        'application' => $this->application,
                    ]));
    }
}
