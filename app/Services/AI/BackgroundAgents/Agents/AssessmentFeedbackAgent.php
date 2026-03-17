<?php

namespace App\Services\AI\BackgroundAgents\Agents;

use App\Events\AssessmentSubmitted;
use App\Mail\BrandedMailable;
use App\Models\AssessmentSubmission;
use App\Models\BackgroundAgentAction;
use App\Services\AI\BackgroundAgents\AbstractBackgroundAgent;
use App\Services\AssessmentReportService;
use App\Support\MailContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AssessmentFeedbackAgent extends AbstractBackgroundAgent
{
    protected string $model = 'gpt-5-nano'; // Primary model for report generation

    public static function getAgentType(): string
    {
        return 'assessment_feedback';
    }

    public static function getDefaultSchedule(): string
    {
        return '0 * * * *'; // Hourly sweep for missed submissions
    }

    public static function getDescription(): string
    {
        return 'Generates AI-powered assessment reports with PDF and sends branded emails to parents.';
    }

    public static function getEstimatedTokensPerRun(): int
    {
        return 100;
    }

    public static function getEventTriggers(): array
    {
        return [AssessmentSubmitted::class];
    }

    protected function execute(): void
    {
        $orgId = $this->organization?->id;
        $isEventDriven = $this->currentRun->trigger_type === 'event';

        if ($isEventDriven) {
            $this->processEventSubmission();
        } else {
            $this->sweepUnreportedSubmissions();
        }
    }

    /**
     * Process a single submission triggered by event.
     */
    protected function processEventSubmission(): void
    {
        $summary = $this->currentRun->summary ?? [];
        $submissionId = $summary['submission_id'] ?? null;

        if (!$submissionId) {
            return;
        }

        $submission = AssessmentSubmission::with('assessment', 'child.user', 'items.bankQuestion')
            ->find($submissionId);

        if ($submission && $submission->status === 'completed') {
            $this->incrementProcessed();
            $this->generateReport($submission);
        }
    }

    /**
     * Sweep for completed submissions that haven't had reports generated.
     */
    protected function sweepUnreportedSubmissions(): void
    {
        $query = AssessmentSubmission::with('assessment', 'child.user', 'items.bankQuestion')
            ->where('status', 'completed')
            ->whereNull('report_generated_at')
            ->where('finished_at', '>=', now()->subDays(7)); // Only last 7 days

        if ($this->organization) {
            $query->whereHas('child', function ($q) {
                $q->where('organization_id', $this->organization->id);
            });
        }

        $maxPerRun = $this->getConfig('max_reports_per_run', 10);

        $submissions = $query->limit($maxPerRun)->get();

        foreach ($submissions as $submission) {
            if (!$this->hasRemainingBudget()) {
                Log::info("[AssessmentFeedbackAgent] Stopping: budget exhausted");
                break;
            }

            try {
                $this->generateReport($submission);
                $this->incrementProcessed();
            } catch (\Exception $e) {
                $this->logAction(
                    BackgroundAgentAction::ACTION_GENERATE_TEXT,
                    $submission,
                    "Report generation failed for submission #{$submission->id}",
                    null, null, 0,
                    BackgroundAgentAction::STATUS_FAILED,
                    $e->getMessage()
                );
            }
        }
    }

    /**
     * Generate a full assessment report for a submission.
     */
    protected function generateReport(AssessmentSubmission $submission): void
    {
        $submission->loadMissing('assessment', 'child.user', 'items.bankQuestion');

        if (!$submission->child?->user?->email) {
            Log::warning("[AssessmentFeedbackAgent] No parent email for submission #{$submission->id}");
            return;
        }

        // Build enhanced prompt (reusing logic from GenerateAssessmentReportJob)
        $prompt = $this->buildEnhancedPrompt($submission);

        // Generate AI analysis
        $reportText = $this->aiGenerateText(
            $prompt,
            'You are a qualified educational psychologist and assessment specialist with expertise in cognitive development and academic evaluation. Provide professional, comprehensive assessment reports for parents that explain educational concepts, cognitive skills, and learning implications in clear, authoritative language.',
            ['model' => 'gpt-5', 'max_tokens' => 3000]
        );

        // Format questions for display
        $formattedQuestions = [];
        foreach ($submission->items as $index => $item) {
            $formattedQuestions[] = AssessmentReportService::formatQuestionForEmail($item, $index);
        }

        // Generate performance insights
        $insights = AssessmentReportService::generatePerformanceInsights($submission);

        // Generate PDF report
        $pdfPath = $this->generatePdfReport($submission, $reportText, $formattedQuestions, $insights);

        // Send branded email
        $this->sendReportEmail($submission, $reportText, $formattedQuestions, $insights, $pdfPath);

        // Mark as generated
        $submission->update(['report_generated_at' => now()]);

        $this->incrementAffected();

        $this->logAction(
            BackgroundAgentAction::ACTION_SEND_EMAIL,
            $submission,
            "Assessment report generated and sent for submission #{$submission->id} to {$submission->child->user->email}",
            null,
            ['report_length' => strlen($reportText), 'pdf_path' => $pdfPath, 'performance_level' => $insights['performance_level']]
        );
    }

    /**
     * Generate PDF report using dompdf.
     */
    protected function generatePdfReport(
        AssessmentSubmission $submission,
        string $reportText,
        array $formattedQuestions,
        array $insights
    ): ?string {
        try {
            $pdf = Pdf::loadView('pdfs.assessment_report', [
                'submission' => $submission,
                'reportText' => $reportText,
                'formattedQuestions' => $formattedQuestions,
                'insights' => $insights,
                'child' => $submission->child,
                'assessment' => $submission->assessment,
                'organization' => $this->organization,
            ]);

            $filename = 'reports/assessment-' . $submission->id . '-' . Str::uuid() . '.pdf';
            Storage::disk('local')->put($filename, $pdf->output());

            // Track PDF generation cost
            $pdfTokens = $this->billing->calculatePlatformTokens('pdf', 'pdf_generation', 0, 0);
            $this->tokensConsumedThisRun += $pdfTokens;

            return $filename;
        } catch (\Exception $e) {
            Log::warning("[AssessmentFeedbackAgent] PDF generation failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Send the branded report email.
     */
    protected function sendReportEmail(
        AssessmentSubmission $submission,
        string $reportText,
        array $formattedQuestions,
        array $insights,
        ?string $pdfPath
    ): void {
        $organization = MailContext::resolveOrganization(
            $submission->child->organization_id ?? null,
            $submission->child->user ?? null,
            $submission
        );

        $mailable = new \App\Mail\AssessmentReportMail(
            $submission,
            $reportText,
            $formattedQuestions,
            $insights,
            $organization
        );

        // Attach PDF if generated
        if ($pdfPath && Storage::disk('local')->exists($pdfPath)) {
            $mailable->attach(Storage::disk('local')->path($pdfPath), [
                'as' => 'Assessment-Report-' . $submission->assessment->title . '.pdf',
                'mime' => 'application/pdf',
            ]);
        }

        Mail::to($submission->child->user->email)->send($mailable);
    }

    /**
     * Build the enhanced AI prompt for report generation.
     * Reuses logic from GenerateAssessmentReportJob::buildEnhancedPrompt.
     */
    protected function buildEnhancedPrompt(AssessmentSubmission $submission): string
    {
        $assessment = $submission->assessment;

        // Fetch past submissions
        $pastSubs = AssessmentSubmission::with('assessment')
            ->where('child_id', $submission->child_id)
            ->where('id', '<>', $submission->id)
            ->orderBy('finished_at', 'desc')
            ->take(5)
            ->get();

        // Build history
        $historyLines = ["Previous assessment performance for {$submission->child->child_name}:"];
        foreach ($pastSubs as $past) {
            $score = "{$past->marks_obtained}/{$past->total_marks}";
            $percentage = $past->total_marks > 0 ? round(($past->marks_obtained / $past->total_marks) * 100, 1) : 0;
            $date = $past->finished_at?->toDateString() ?? 'unknown';
            $title = $past->assessment->title ?? 'Untitled';
            $historyLines[] = "- {$title}: {$score} ({$percentage}%) on {$date}";
        }

        // Build question analysis
        $questionAnalysis = [];
        foreach ($submission->items as $index => $item) {
            $questionData = $item->question_data ?? [];
            $questionText = '';
            if ($item->isFromQuestionBank() && $item->bankQuestion) {
                $questionText = $item->bankQuestion->question_text ?? $item->bankQuestion->title ?? '';
            } else {
                $questionText = $questionData['question_text'] ?? $questionData['title'] ?? '';
            }
            if (empty($questionText)) $questionText = "Question " . ($index + 1);

            $studentAnswer = is_array($item->answer) ? json_encode($item->answer) : ($item->answer ?? 'No answer');
            $maxMarks = $questionData['marks'] ?? ($item->bankQuestion->marks ?? 1);

            $questionAnalysis[] = implode("\n", [
                "Question " . ($index + 1) . " ({$item->question_type}): {$questionText}",
                "Student Answer: {$studentAnswer}",
                "Correct: " . ($item->is_correct ? 'Yes' : 'No') . " | Marks: {$item->marks_awarded}/{$maxMarks}",
                $item->detailed_feedback ? "Feedback: {$item->detailed_feedback}" : '',
            ]);
        }

        $percentage = $submission->total_marks > 0
            ? round(($submission->marks_obtained / $submission->total_marks) * 100, 1)
            : 0;

        return implode("\n", [
            "Generate a comprehensive, encouraging assessment report for a parent.",
            "",
            "STUDENT INFORMATION:",
            "Child Name: {$submission->child->child_name}",
            "Parent Name: {$submission->child->user->name}",
            "Assessment: {$assessment->title}",
            "Overall Score: {$submission->marks_obtained}/{$submission->total_marks} ({$percentage}%)",
            "Completion Date: " . ($submission->finished_at?->format('F j, Y') ?? 'N/A'),
            "",
            "ASSESSMENT HISTORY:",
            implode("\n", $historyLines),
            "",
            "DETAILED QUESTION ANALYSIS:",
            implode("\n\n", $questionAnalysis),
            "",
            "REPORT REQUIREMENTS:",
            "1. Encouraging, constructive tone for parents",
            "2. Highlight specific strengths",
            "3. Identify areas for improvement with actionable suggestions",
            "4. Provide study recommendations based on performance patterns",
            "5. Compare with historical data if available",
            "6. Use clear headings and bullet points",
        ]);
    }
}
