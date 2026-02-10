<?php

namespace App\Mail;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class TeacherRejected extends BrandedMailable
{
    use Queueable, SerializesModels;

    public $name;

    public function __construct(string $name, ?Organization $organization = null)
    {
        $this->name = $name;
        $this->organization = $organization ?? $this->resolveOrganization();
    }

    public function build()
    {
        return $this->subject('Your application status')
                    ->view('emails.teacher_rejected')
                    ->with($this->brandingData([
                        'name' => $this->name,
                    ]));
    }
}
