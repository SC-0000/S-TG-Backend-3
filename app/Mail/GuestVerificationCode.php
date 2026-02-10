<?php

namespace App\Mail;

use App\Models\Organization;

class GuestVerificationCode extends BrandedMailable
{
    public $code;
    public $email;

    /**
     * Create a new message instance.
     *
     * @param string $code
     * @param string|null $email
     * @param Organization|null $organization
     */
    public function __construct(string $code, ?string $email = null, ?Organization $organization = null)
    {
        $this->code = $code;
        $this->email = $email;
        $this->organization = $organization ?? $this->resolveOrganization();
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Your verification code')
                    ->view('emails.guest_verification_code')
                    ->text('emails.guest_verification_code_plain')
                    ->with($this->brandingData([
                        'code' => $this->code,
                        'email' => $this->email,
                    ]));
    }
}
