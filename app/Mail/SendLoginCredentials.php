<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\User;

class SendLoginCredentials extends Mailable
{
    use SerializesModels;

    public $user;
    public $password;

    /**
     * Create a new message instance.
     *
     * @param  \App\Models\User  $user
     * @param  string  $password
     * @return void
     */
    public function __construct(User $user, $password)
    {
        $this->user = $user;
        $this->password = $password;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Your account has been created')
                    ->view('emails.send_login_credentials')
                    ->text('emails.send_login_credentials_plain')
                    ->with([
                        'userName' => $this->user->name,
                        'userEmail' => $this->user->email,
                        'password' => $this->password,  // The generated password
                        'loginUrl' => route('home'),  // The login page URL
                    ]);
    }
}
