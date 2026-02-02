<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Application;

class VerifyApplicationEmail extends Mailable
{
    use SerializesModels;

    public $application;

    /**
     * Create a new message instance.
     *
     * @param  \App\Models\Application  $application
     * @return void
     */
    public function __construct(Application $application)
    {
        $this->application = $application;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Please verify your application email')
                    ->view('emails.verify_application')
                    ->text('emails.verify_application_plain');
    }
}
