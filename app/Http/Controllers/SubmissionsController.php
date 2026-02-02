<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAssessmentReportJob;
use App\Models\AppNotification;
use App\Models\AssessmentSubmission;
use App\Models\Child;
// use Illuminate\Container\Attributes\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class SubmissionsController extends Controller
{
    /**
     * Display a graded submission result page.
     * Route:  GET /submissions/{submission}
     */
  public function show(AssessmentSubmission $submission)
{
    // Eager-load the assessment with questions_json and submission items
    $submission->load([
        'assessment:id,title,questions_json',
        'items' => function($query) {
            $query->orderBy('created_at');
        }
    ]);

    // Get original answers and questions data
    $answers = $submission->answers_json ?? [];
    $items = $submission->items;
    $questions = collect($submission->assessment->questions_json ?? []);

    Log::info('Flag submission debug - original data', [
        'submission_id' => $submission->id,
        'answers_count' => count($answers),
        'items_count' => $items->count(),
        'first_answer_has_id' => isset($answers[0]) ? (array_key_exists('assessment_submission_item_id', $answers[0])) : false
    ]);

    // Transform items directly to ensure reliable ID mapping
    $enhancedAnswers = [];
    foreach ($items as $index => $item) {
        $originalAnswer = $answers[$index] ?? [];
        $question = $questions->get($index, []);
        
        // Safely get item ID - handle both model and array cases
        $itemId = null;
        if (is_object($item)) {
            if (method_exists($item, 'getKey')) {
                $itemId = $item->getKey();
            } elseif (property_exists($item, 'id')) {
                $itemId = $item->id;
            }
        } elseif (is_array($item) && isset($item['id'])) {
            $itemId = $item['id'];
        }
        
        // Create enhanced answer with guaranteed database ID
        $enhancedAnswer = array_merge($originalAnswer, [
            'assessment_submission_item_id' => $itemId,
            'submission_item_id' => $itemId, // Fallback field name
            'question' => $question['question_text'] ?? null,
            'options' => $question['options'] ?? [],
        ]);
        
        $enhancedAnswers[] = $enhancedAnswer;
        Log::info(' originalAnswer', $originalAnswer);
        
        Log::info("Flag submission debug - mapped item {$index}", [
            'item_id' => $itemId,
            'item_type' => gettype($item),
            'has_grading_result' => isset($originalAnswer['grading_result']),
            'question_type' => $originalAnswer['type'] ?? 'unknown'
        ]);
    }

    Log::info('Flag submission debug - final data', [
        'enhanced_answers_count' => count($enhancedAnswers),
        'first_answer_id' => $enhancedAnswers[0]['assessment_submission_item_id'] ?? 'missing',
        'sample_answer_keys' => isset($enhancedAnswers[0]) ? array_keys($enhancedAnswers[0]) : []
    ]);

    // Pass the enhanced answers directly to ensure they reach the frontend
    // Clone submission data and replace answers_json with enhanced version
    $submissionData = $submission->toArray();
    $submissionData['answers_json'] = $enhancedAnswers;
    
    // Also ensure assessment and items are properly included
    $submissionData['assessment'] = $submission->assessment->toArray();
    $submissionData['items'] = $items->toArray();

    Log::info('Flag submission debug - sending to frontend', [
        'submission_keys' => array_keys($submissionData),
        'answers_json_count' => count($submissionData['answers_json']),
        'first_answer_sample' => $submissionData['answers_json'][0] ?? 'no answers',
        'has_assessment_submission_item_id' => isset($submissionData['answers_json'][0]['assessment_submission_item_id']),
    ]);

    return Inertia::render('@parent/Submissions/Show', [
        'submission' => $submissionData,
    ]);
}
public function AdminShow(AssessmentSubmission $submission)
{
    // We no longer have an items → question relationship.
    // Everything we need is already serialized into answers_json on the submission.

    // But let’s still eager-load the assessment title
    $submission->load('assessment:id,title');

    return Inertia::render('@admin/Submissions/AdminShow', [
        // Pass the raw model, which now contains `answers_json`
        // (an array of question_text, answer, is_correct, marks_awarded, etc.)
        'submission' => $submission,
    ]);
}
// app/Http/Controllers/SubmissionController.php
public function index()
{
    Log::info('Fetching submissions for index page');
    // Log::info('Submissions fetched', ['count' => $submissions->count()]);
    $submissions = AssessmentSubmission::    with([
            'child:id,child_name,year_group',
            'assessment:id,title',
            'assessment.service:id,service_name',
                
        ])
        ->whereHas('child')
        ->latest('finished_at')
        ->get()
        ->map(fn ($s) => [
            'id'            => $s->id,
            'child'         => $s->child,
            'assessment'    => $s->assessment,
            'service'       => $s->assessment->service->first(), // one service per assessment
            'marks'         => $s->marks_obtained,
            'total'         => $s->total_marks,
            'status'        => $s->status,
            'retake'        => $s->retake_number,
            'finished_at'   => $s->finished_at->toDateTimeString(),
            'grader_name'   => optional($s->grader)->name,
        ]);

    return Inertia::render('@admin/Submissions/Index', [
        'submissions' => $submissions,
    ]);
}
    public function edit(AssessmentSubmission $submission)
    {
        // Load the submission with enhanced items and assessment data
        $submission->load([
            'assessment:id,title',
            'child:id,child_name,year_group',
            'items' => function($query) {
                $query->orderBy('created_at');
            },
            'items.aiGradingFlag' => function($query) {
                $query->where('status', 'pending');
            }
        ]);

        // Transform items for frontend
        $enhancedItems = $submission->items->map(function($item) {
            // Safely get item properties - handle both model and array cases
            $itemId = is_object($item) && method_exists($item, 'getKey') ? $item->getKey() : null;
            $gradingMeta = is_object($item) ? ($item->grading_metadata ?? []) : ($item['grading_metadata'] ?? []);
            
            // Get flag data if exists - simplified approach
            $flagData = null;
            $hasPendingFlag = false;
            
            // Check if item has pending flags in database directly
            if ($itemId) {
                $flagExists = \App\Models\AIGradingFlag::where('assessment_submission_item_id', $itemId)
                    ->where('status', 'pending')
                    ->first();
                    
                if ($flagExists) {
                    $hasPendingFlag = true;
                    $flagData = [
                        'id' => $flagExists->getAttribute('id'),
                        'flag_reason' => $flagExists->getAttribute('flag_reason'),
                        'student_explanation' => $flagExists->getAttribute('student_explanation'),
                        'status' => $flagExists->getAttribute('status'),
                        'created_at' => $flagExists->getAttribute('created_at'),
                        'original_grade' => $flagExists->getAttribute('original_grade'),
                    ];
                }
            }
            
            return [
                'id' => $itemId,
                'question_type' => is_object($item) ? $item->question_type : ($item['question_type'] ?? null),
                'bank_question_id' => is_object($item) ? $item->bank_question_id : ($item['bank_question_id'] ?? null),
                'inline_question_index' => is_object($item) ? $item->inline_question_index : ($item['inline_question_index'] ?? null),
                'question_data' => is_object($item) ? $item->question_data : ($item['question_data'] ?? null),
                'student_answer' => is_object($item) ? $item->answer : ($item['answer'] ?? null),
                'is_correct' => is_object($item) ? $item->is_correct : ($item['is_correct'] ?? null),
                'marks_awarded' => is_object($item) ? $item->marks_awarded : ($item['marks_awarded'] ?? null),
                'grading_metadata' => $gradingMeta,
                'detailed_feedback' => is_object($item) ? $item->detailed_feedback : ($item['detailed_feedback'] ?? null),
                'time_spent' => is_object($item) ? $item->time_spent : ($item['time_spent'] ?? null),
                'requires_manual_grading' => $gradingMeta['requires_human_review'] ?? false,
                'auto_graded' => $gradingMeta['auto_graded'] ?? false,
                'confidence_level' => $gradingMeta['confidence_level'] ?? null,
                'grading_method' => $gradingMeta['grading_method'] ?? 'unknown',
                'ai_grading_flag' => $flagData, // Add flag data
                'is_flagged' => $flagData !== null,
                'has_pending_flag' => $flagData && $flagData['status'] === 'pending',
            ];
        });

        return Inertia::render('@admin/Submissions/Grade', [
            'submission' => [
                'id' => $submission->id,
                'assessment' => $submission->assessment,
                'child' => $submission->child,
                'total_marks' => $submission->total_marks,
                'marks_obtained' => $submission->marks_obtained,
                'status' => $submission->status,
                'finished_at' => $submission->finished_at,
                'items' => $enhancedItems,
            ],
        ]);
    }

    /* save */
    public function update(Request $request, AssessmentSubmission $submission)
    {
        $payload = $request->validate([
            'items'             => 'required|array',        // [item_id => marks_awarded]
            'overall_comment'   => 'nullable|string',
        ]);

        Log::info('Updating submission with enhanced structure', [
            'submission_id' => $submission->id,
            'items_to_update' => count($payload['items'])
        ]);

        // Update individual submission items
        foreach ($payload['items'] as $itemId => $marksAwarded) {
            $item = $submission->items()->find($itemId);
            
            if ($item) {
                // Check if this item has a pending flag
                $hasFlag = \App\Models\AIGradingFlag::where('assessment_submission_item_id', $itemId)
                    ->where('status', 'pending')
                    ->exists();
                
                // Allow updates if: never graded, requires manual review, or has a pending flag
                if ($item->marks_awarded === null || $item->requires_manual_grading || $hasFlag) {
                    $originalGrade = $item->marks_awarded;
                    
                    $item->update([
                        'marks_awarded' => (float) $marksAwarded,
                        'is_correct' => $marksAwarded > 0,
                        'grading_metadata' => array_merge($item->grading_metadata ?? [], [
                            'manually_graded' => true,
                            'graded_by' => auth()->id(),
                            'graded_at' => now()->toDateTimeString(),
                            'original_ai_grade' => $originalGrade, // Preserve original for reference
                            'grade_changed_due_to_flag' => $hasFlag,
                        ])
                    ]);
                    
                    // Resolve any pending flags for this item
                    if ($hasFlag) {
                        $flag = \App\Models\AIGradingFlag::where('assessment_submission_item_id', $itemId)
                            ->where('status', 'pending')
                            ->first();
                            
                        if ($flag) {
                            $flag->update([
                                'status' => 'resolved',
                                'admin_user_id' => auth()->id(),
                                'final_grade' => (float) $marksAwarded,
                                'grade_changed' => $originalGrade != $marksAwarded,
                                'admin_response' => 'Grade reviewed and ' . ($originalGrade != $marksAwarded ? 'updated' : 'confirmed') . ' after student flag.',
                                'reviewed_at' => now(),
                            ]);
                            
                            Log::info('Resolved flag for item', [
                                'flag_id' => $flag->getAttribute('id'),
                                'item_id' => $itemId,
                                'original_grade' => $originalGrade,
                                'final_grade' => $marksAwarded,
                                'grade_changed' => $originalGrade != $marksAwarded
                            ]);
                        }
                    }
                    
                    Log::info('Updated submission item', [
                        'item_id' => $itemId,
                        'marks_awarded' => $marksAwarded,
                        'had_flag' => $hasFlag,
                        'original_grade' => $originalGrade
                    ]);
                }
            }
        }

        // Recalculate total marks from ALL items (not just updated ones)
        $totalObtained = $submission->items()->sum('marks_awarded') ?? 0;
        
        Log::info('Recalculated total marks', [
            'submission_id' => $submission->id,
            'total_items' => $submission->items()->count(),
            'total_obtained' => $totalObtained,
            'items_updated_this_session' => count($payload['items'])
        ]);

        // Update answers_json to reflect the new grades (so parents see updated marks)
        $currentAnswers = $submission->answers_json ?? [];
        $updatedAnswers = [];
        
        // Get all current items to sync with answers_json
        $allItems = $submission->items()->get();
        
        foreach ($currentAnswers as $index => $answer) {
            $updatedAnswer = $answer;
            
            // Find the corresponding submission item
            $item = $allItems->get($index);
            if ($item) {
                // Update the grading result to reflect current database state
                if (isset($updatedAnswer['grading_result'])) {
                    $updatedAnswer['grading_result']['marks_awarded'] = $item->marks_awarded;
                    $updatedAnswer['grading_result']['is_correct'] = $item->is_correct;
                    
                    // Add admin review metadata if this item was manually reviewed
                    if ($item->grading_metadata && isset($item->grading_metadata['manually_graded'])) {
                        $updatedAnswer['grading_result']['manually_reviewed'] = true;
                        $updatedAnswer['grading_result']['reviewed_by'] = $item->grading_metadata['graded_by'] ?? null;
                        $updatedAnswer['grading_result']['reviewed_at'] = $item->grading_metadata['graded_at'] ?? null;
                    }
                } else {
                    // If no grading_result exists, create one
                    $updatedAnswer['grading_result'] = [
                        'marks_awarded' => $item->marks_awarded,
                        'is_correct' => $item->is_correct,
                        'manually_reviewed' => true,
                        'reviewed_by' => auth()->id(),
                        'reviewed_at' => now()->toDateTimeString(),
                    ];
                }
                
                // Also update the top-level fields that might be used by frontend
                $updatedAnswer['marks_awarded'] = $item->marks_awarded;
                $updatedAnswer['is_correct'] = $item->is_correct;
            }
            
            $updatedAnswers[] = $updatedAnswer;
        }

        Log::info('Updated answers_json for parent visibility', [
            'submission_id' => $submission->id,
            'answers_updated' => count($updatedAnswers),
            'items_with_updated_grades' => count(array_filter($updatedAnswers, fn($a) => isset($a['grading_result']['manually_reviewed'])))
        ]);

        // Update the main submission
        $submission->update([
            'marks_obtained' => $totalObtained,
            'status'         => 'graded',
            'graded_at'      => now(),
            'meta->comment'  => $payload['overall_comment'] ?? null,
            'answers_json'   => $updatedAnswers, // Update answers_json with new grades
        ]);

        // Send notification to parent
        if ($submission->status === 'graded') {
            $child = $submission->child;
            $text = [
                'title'   => "Assessment Graded: {$submission->assessment->title}",
                'message' => "Your child's assessment has been graded.",
                'type'    => 'assessment',
                'status'  => 'unread',
                'channel' => 'in-app',
            ];

            if ($submission->user_id) {
                AppNotification::create([
                    'user_id'  => $submission->user_id,
                    'title'    => $text['title'],
                    'message'  => "For \"{$child->child_name}\": {$text['message']}",
                    'type'     => $text['type'],
                    'status'   => $text['status'],
                    'channel'  => $text['channel'],
                ]);
            }
        }

        // Generate assessment report
        if ($submission->status === 'graded') {
            Log::info('Dispatching GenerateAssessmentReportJob for submission', [
                'submission_id' => $submission->id
            ]);
            dispatch(new GenerateAssessmentReportJob($submission));
        }

        return redirect()
            ->route('admin.submissions.show', $submission->id)
            ->with('success', 'Marks saved successfully using enhanced grading system.');
    }

}
