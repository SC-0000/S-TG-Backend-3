<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TeacherApplicationReceived extends Mailable
{
    use Queueable, SerializesModels;

    public $teacherName;
    public $email;

    /**
     * Create a new message instance.
     */
    public function __construct($teacherName, $email)
    {
        $this->teacherName = $teacherName;
        $this->email = $email;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject('Teacher Application Received')
                    ->view('emails.teacher_application_received');
    }
}
