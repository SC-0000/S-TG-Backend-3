<?php

namespace App\Mail;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class WelcomeSetupAccount extends BrandedMailable
{
    use Queueable, SerializesModels;

    public User $user;
    public string $setupUrl;

    public function __construct(User $user, string $setupUrl, ?Organization $organization = null)
    {
        $this->user         = $user;
        $this->setupUrl     = $setupUrl;
        $this->organization = $organization ?? $this->resolveOrganization(null, $user);
    }

    public function build()
    {
        $userName = $this->user->name
            ?? trim(($this->user->first_name ?? '') . ' ' . ($this->user->last_name ?? ''))
            ?: ($this->user->email ?? 'there');

        return $this->subject('Welcome — Set Up Your Account')
                    ->view('emails.welcome_setup_account')
                    ->text('emails.welcome_setup_account_plain')
                    ->with($this->brandingData([
                        'user'     => $this->user,
                        'userName' => $userName,
                        'setupUrl' => $this->setupUrl,
                    ]));
    }
}
