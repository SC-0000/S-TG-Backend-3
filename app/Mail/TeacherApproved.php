<?php

namespace App\Mail;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class TeacherApproved extends BrandedMailable
{
    use Queueable, SerializesModels;

    public $user;

    public function __construct(User $user, ?Organization $organization = null)
    {
        $this->user = $user;
        $this->organization = $organization ?? $this->resolveOrganization(null, $user);
    }

    public function build()
    {
        return $this->subject('Your application has been approved')
                    ->view('emails.teacher_approved')
                    ->with($this->brandingData([
                        'user' => $this->user,
                    ]));
    }
}
