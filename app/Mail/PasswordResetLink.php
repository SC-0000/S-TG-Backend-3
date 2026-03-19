<?php

namespace App\Mail;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class PasswordResetLink extends BrandedMailable
{
    use Queueable, SerializesModels;

    public User $user;
    public string $token;

    public function __construct(User $user, string $token, ?Organization $organization = null)
    {
        $this->user = $user;
        $this->token = $token;
        $this->organization = $organization ?? $this->resolveOrganization(null, $user);
    }

    public function build()
    {
        $userName = $this->user->name
            ?? trim(($this->user->first_name ?? '') . ' ' . ($this->user->last_name ?? ''))
            ?: ($this->user->email ?? 'there');

        $base = $this->organization?->public_domain
            ?? $this->organization?->portal_domain
            ?? config('app.frontend_url');

        $base = $this->normalizeWebsite($base);
        $resetUrl = rtrim((string) $base, '/') . '/reset-password/' . $this->token;

        $email = $this->user->email;
        if ($email) {
            $resetUrl .= '?email=' . urlencode($email);
        }

        $expires = (int) (config('auth.passwords.users.expire') ?? 60);

        return $this->subject('Reset your password')
            ->view('emails.password_reset')
            ->text('emails.password_reset_plain')
            ->with($this->brandingData([
                'user' => $this->user,
                'userName' => $userName,
                'resetUrl' => $resetUrl,
                'expires' => $expires,
            ]));
    }
}
