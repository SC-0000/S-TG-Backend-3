<?php

namespace App\Mail;

use App\Models\AssessmentSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AssessmentReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public $submission;
    public $reportText;
    public $formattedQuestions;
    public $insights;

    /**
     * Create a new message instance.
     */
    public function __construct(
        AssessmentSubmission $submission, 
        $reportText = null, 
        $formattedQuestions = [], 
        $insights = []
    ) {
        $this->submission = $submission;
        $this->reportText = $reportText;
        $this->formattedQuestions = $formattedQuestions;
        $this->insights = $insights;
    }

    public function build()
    {
        return $this->subject("📊 Professional Assessment Report - {$this->submission->child->child_name}")
                    ->view('emails.reports.assessment_enhanced')
                    ->text('emails.reports.assessment_enhanced_plain')
                    ->with([
                        'submission' => $this->submission,
                        'reportText' => $this->reportText,
                        'formattedQuestions' => $this->formattedQuestions,
                        'insights' => $this->insights,
                    ]);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "📊 Professional Assessment Report - {$this->submission->child->child_name}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.reports.assessment_enhanced',
            text: 'emails.reports.assessment_enhanced_plain',
            with: [
                'submission' => $this->submission,
                'reportText' => $this->reportText,
                'formattedQuestions' => $this->formattedQuestions,
                'insights' => $this->insights,
            ]
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
