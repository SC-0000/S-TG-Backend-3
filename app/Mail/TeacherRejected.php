<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TeacherRejected extends Mailable
{
    use Queueable, SerializesModels;

    public $teacherName;

    /**
     * Create a new message instance.
     */
    public function __construct($teacherName)
    {
        $this->teacherName = $teacherName;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject('Teacher Application Update')
                    ->view('emails.teacher_rejected');
    }
}
