<?php

namespace App\Mail;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class TeacherApplicationReceived extends BrandedMailable
{
    use Queueable, SerializesModels;

    public $name;
    public $email;

    public function __construct(string $name, string $email, ?Organization $organization = null)
    {
        $this->name = $name;
        $this->email = $email;
        $this->organization = $organization ?? $this->resolveOrganization();
    }

    public function build()
    {
        return $this->subject('Application Received')
                    ->view('emails.teacher_application_received')
                    ->with($this->brandingData([
                        'name' => $this->name,
                        'email' => $this->email,
                    ]));
    }
}
