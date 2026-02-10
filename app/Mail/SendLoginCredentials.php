<?php

namespace App\Mail;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;

class SendLoginCredentials extends BrandedMailable
{
    use Queueable, SerializesModels;

    public $user;
    public $password;

    public function __construct(User $user, string $password, ?Organization $organization = null)
    {
        $this->user = $user;
        $this->password = $password;
        $this->organization = $organization ?? $this->resolveOrganization(null, $user);
    }

    public function build()
    {
        $userName = $this->user->name
            ?? trim(($this->user->first_name ?? '') . ' ' . ($this->user->last_name ?? ''))
            ?: ($this->user->email ?? 'there');
        $userEmail = $this->user->email ?? '';
        $loginUrl = route('login');

        return $this->subject('Your Login Credentials')
                    ->view('emails.send_login_credentials')
                    ->text('emails.send_login_credentials_plain')
                    ->with($this->brandingData([
                        'user' => $this->user,
                        'password' => $this->password,
                        'userName' => $userName,
                        'userEmail' => $userEmail,
                        'loginUrl' => $loginUrl,
                    ]));
    }
}
