<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class GuestVerificationCode extends Mailable
{
    public $code;
    public $email;

    /**
     * Create a new message instance.
     *
     * @param string $code
     * @param string|null $email
     */
    public function __construct(string $code, ?string $email = null)
    {
        $this->code = $code;
        $this->email = $email;
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
                    ->with([
                        'code' => $this->code,
                        'email' => $this->email,
                    ]);
    }
}
