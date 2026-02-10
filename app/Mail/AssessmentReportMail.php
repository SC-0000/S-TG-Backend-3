<?php

namespace App\Mail;

use App\Models\AssessmentSubmission;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AssessmentReportMail extends BrandedMailable
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
        $insights = [],
        ?Organization $organization = null
    ) {
        $this->submission = $submission;
        $this->reportText = $reportText;
        $this->formattedQuestions = $formattedQuestions;
        $this->insights = $insights;
        $this->organization = $organization ?? $this->resolveOrganizationFromSubmission($submission);
    }

    public function build()
    {
        return $this->subject("📊 Professional Assessment Report - {$this->submission->child->child_name}")
                    ->view('emails.reports.assessment_enhanced')
                    ->text('emails.reports.assessment_enhanced_plain')
                    ->with($this->brandingData([
                        'submission' => $this->submission,
                        'reportText' => $this->reportText,
                        'formattedQuestions' => $this->formattedQuestions,
                        'insights' => $this->insights,
                    ]));
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
            with: $this->brandingData([
                'submission' => $this->submission,
                'reportText' => $this->reportText,
                'formattedQuestions' => $this->formattedQuestions,
                'insights' => $this->insights,
            ])
        );
    }

    protected function resolveOrganizationFromSubmission(AssessmentSubmission $submission): ?Organization
    {
        if ($submission->child && $submission->child->organization_id) {
            return Organization::find($submission->child->organization_id);
        }

        if ($submission->assessment && $submission->assessment->organization_id) {
            return Organization::find($submission->assessment->organization_id);
        }

        if ($submission->user) {
            $resolved = $this->resolveOrganization(null, $submission->user);
            if ($resolved) {
                return $resolved;
            }
        }

        return $this->resolveOrganization();
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
