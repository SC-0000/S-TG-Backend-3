<?php
namespace App\Jobs;

use App\Models\AssessmentSubmission;
use App\Services\AssessmentReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

use App\Mail\AssessmentReportMail;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

class GenerateAssessmentReportJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $submission;

    public function __construct(AssessmentSubmission $submission)
    {
        $this->submission = $submission;
    }

    public function handle()
    {
        $submission = $this->submission->load('assessment', 'child.user', 'items.bankQuestion');
        Log::info('Generating enhanced assessment report for submission', [
            'submission_id' => $submission->id,
            'child_id' => $submission->child_id,
            'assessment_id' => $submission->assessment_id,
        ]);
        
        // Only generate once
        // if ($submission->report_generated_at) return;
        
        // Fetch all previous submissions for this child, excluding the current one
        $pastSubs = AssessmentSubmission::with('assessment')
            ->where('child_id', $submission->child_id)
            ->where('id', '<>', $submission->id)
            ->orderBy('finished_at', 'desc')
            ->get();

        // Generate AI analysis using enhanced prompt
        $prompt = $this->buildEnhancedPrompt($submission, $pastSubs);
        Log::info('Enhanced OpenAI prompt built', [
            'submission_id' => $submission->id,
            'prompt_length' => strlen($prompt),
        ]);
        
        $response = OpenAI::chat()->create([
            'model' => 'gpt-5',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a qualified educational psychologist and assessment specialist with expertise in cognitive development and academic evaluation. Provide professional, comprehensive assessment reports for parents that explain educational concepts, cognitive skills, and learning implications in clear, authoritative language. Use evidence-based insights and educational terminology appropriate for formal academic reports.'],
                ['role' => 'user', 'content' => $prompt],
            ]
        ]);

        $reportText = $response['choices'][0]['message']['content'];
        Log::info('Enhanced OpenAI response received', [
            'submission_id' => $submission->id,
            'report_text_length' => strlen($reportText),
        ]);
        
        // Format questions using the new submission items structure
        $formattedQuestions = [];
        foreach ($submission->items as $index => $item) {
            $formattedQuestions[] = AssessmentReportService::formatQuestionForEmail($item, $index);
        }
        
        // Generate performance insights
        $insights = AssessmentReportService::generatePerformanceInsights($submission);
        
        Log::info('Enhanced report data prepared', [
            'submission_id' => $submission->id,
            'questions_formatted' => count($formattedQuestions),
            'performance_level' => $insights['performance_level'],
        ]);
        
        // Send enhanced email (no PDF attachment)
        Mail::to($submission->child->user->email)->bcc('zaid.a@pa.team')
            ->send(new AssessmentReportMail($submission, $reportText, $formattedQuestions, $insights));
            
        Log::info('Enhanced email report sent', [
            'submission_id' => $submission->id,
            'email' => $submission->child->user->email,
            'questions_count' => count($formattedQuestions),
        ]);
        
        // $submission->report_generated_at = now();
        $submission->save();
    }

    private function buildEnhancedPrompt(AssessmentSubmission $submission, $pastSubs)
    {
        $assessment = $submission->assessment;

        // Build assessment history
        $historyLines = ["Previous assessment performance for {$submission->child->child_name}:"];
        foreach ($pastSubs->take(5) as $past) {
            $score = "{$past->marks_obtained}/{$past->total_marks}";
            $percentage = $past->total_marks > 0 ? round(($past->marks_obtained / $past->total_marks) * 100, 1) : 0;
            $date = $past->finished_at->toDateString();
            $title = $past->assessment->title;
            $historyLines[] = "- {$title}: {$score} ({$percentage}%) on {$date}";
        }
        $historyText = implode("\n", $historyLines);

        // Build enhanced question analysis using new submission items structure
        $questionAnalysis = [];
        foreach ($submission->items as $index => $item) {
            $questionData = $item->question_data ?? [];
            $gradingMetadata = $item->grading_metadata ?? [];
            
            // Get question text from different sources
            $questionText = '';
            if ($item->isFromQuestionBank() && $item->bankQuestion) {
                $questionText = $item->bankQuestion->question_text ?? $item->bankQuestion->title ?? '';
            } else {
                $questionText = $questionData['question_text'] ?? $questionData['title'] ?? '';
            }
            
            if (empty($questionText)) {
                $questionText = "Question " . ($index + 1);
            }
            
            $studentAnswer = is_array($item->answer) ? json_encode($item->answer) : ($item->answer ?? 'No answer provided');
            $isCorrect = $item->is_correct ?? false;
            $marksAwarded = $item->marks_awarded ?? 0;
            $maxMarks = $questionData['marks'] ?? ($item->bankQuestion->marks ?? 1);
            $feedback = $item->detailed_feedback ?? '';
            $confidence = $item->getAIConfidence();
            $manuallyReviewed = $gradingMetadata['manually_reviewed'] ?? false;
            $questionType = $item->question_type ?? 'unknown';
            
            $questionAnalysis[] = [
                'question_number' => $index + 1,
                'type' => $questionType,
                'question' => $questionText,
                'student_answer' => $studentAnswer,
                'correct' => $isCorrect ? 'Yes' : 'No',
                'marks' => "{$marksAwarded}/{$maxMarks}",
                'confidence' => $confidence ? round($confidence, 1) . '%' : 'N/A',
                'manually_reviewed' => $manuallyReviewed ? 'Yes' : 'No',
                'feedback' => $feedback
            ];
        }

        $lines = [
            "Generate a comprehensive, encouraging assessment report for a parent. Focus on actionable insights and learning strategies.",
            "",
            "STUDENT INFORMATION:",
            "Child Name: {$submission->child->child_name}",
            "Parent Name: {$submission->child->user->name}",
            "Assessment: {$assessment->title}",
            "Overall Score: {$submission->marks_obtained}/{$submission->total_marks} (" . round(($submission->marks_obtained / $submission->total_marks) * 100, 1) . "%)",
            "Completion Date: {$submission->finished_at->format('F j, Y')}",
            "",
            "ASSESSMENT HISTORY:",
            $historyText,
            "",
            "DETAILED QUESTION ANALYSIS:",
        ];

        foreach ($questionAnalysis as $qa) {
            $lines[] = "Question {$qa['question_number']} ({$qa['type']}): {$qa['question']}";
            $lines[] = "Student Answer: {$qa['student_answer']}";
            $lines[] = "Correct: {$qa['correct']} | Marks: {$qa['marks']} | AI Confidence: {$qa['confidence']}";
            if ($qa['manually_reviewed'] === 'Yes') {
                $lines[] = "Manually Reviewed: Yes (Admin verified)";
            }
            if ($qa['feedback']) {
                $lines[] = "Feedback: {$qa['feedback']}";
            }
            $lines[] = "";
        }

        $lines[] = "REPORT REQUIREMENTS:";
        $lines[] = "1. Provide an encouraging, constructive tone suitable for parents";
        $lines[] = "2. Highlight specific strengths demonstrated in the assessment";
        $lines[] = "3. Identify areas for improvement with specific, actionable suggestions";
        $lines[] = "4. Consider the question types and learning patterns shown";
        $lines[] = "5. Reference any manually reviewed questions and their significance";
        $lines[] = "6. Provide study recommendations based on performance patterns";
        $lines[] = "7. Compare current performance with historical data if available";
        $lines[] = "8. Use clear headings and bullet points for easy reading";

        return implode("\n", $lines);
    }
}
