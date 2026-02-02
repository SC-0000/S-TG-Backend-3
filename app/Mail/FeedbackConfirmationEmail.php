<?php

namespace App\Mail;

use App\Models\Feedback;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class FeedbackConfirmationEmail extends Mailable
{
    use SerializesModels;

    public $feedback;

    /**
     * Create a new message instance.
     *
     * @param  \App\Models\Feedback  $feedback
     * @return void
     */
    public function __construct(Feedback $feedback)
    {
        $this->feedback = $feedback;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Thank you for your feedback!')
                    ->view('emails.feedback_confirmation')
                    ->text('emails.feedback_confirmation_plain');
    }
}
