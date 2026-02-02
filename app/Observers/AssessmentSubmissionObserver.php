<?php

namespace App\Observers;

use App\Jobs\GenerateAssessmentReportJob;
use App\Mail\AssessmentReportMail;
use App\Models\AssessmentSubmission;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Mail;

class AssessmentSubmissionObserver
{
    /**
     * Handle the AssessmentSubmission "created" event.
     */
  
    public function created(AssessmentSubmission $submission)
{
    // 1. Generate the report
    // $pdf = Pdf::loadView('pdfs.assessment_report', [
    //     'submission' => $submission->load('assessment', 'child', 'child.user')
    // ]);

    // // 2. Save it to disk (optional)
    // $path = storage_path("app/reports/assessment_{$submission->id}.pdf");
    // $pdf->save($path);

    // // 3. Email the parent
    // Mail::to($submission->child->user->email)
    //     ->send(new AssessmentReportMail($submission, $path));
}
    /**
     * Handle the AssessmentSubmission "updated" event.
     */
   public function updated(AssessmentSubmission $submission)
{
    if ($submission->isDirty('status') && $submission->status === 'graded') {
        dispatch(new GenerateAssessmentReportJob($submission));
    }
}

    /**
     * Handle the AssessmentSubmission "deleted" event.
     */
    public function deleted(AssessmentSubmission $assessmentSubmission): void
    {
        //
    }

    /**
     * Handle the AssessmentSubmission "restored" event.
     */
    public function restored(AssessmentSubmission $assessmentSubmission): void
    {
        //
    }

    /**
     * Handle the AssessmentSubmission "force deleted" event.
     */
    public function forceDeleted(AssessmentSubmission $assessmentSubmission): void
    {
        //
    }
}
