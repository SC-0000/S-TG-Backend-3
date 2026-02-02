<?php

namespace App\Http\Controllers;

use App\Models\ContentLesson;
use App\Models\LessonSlide;
use App\Models\LessonProgress;
use App\Models\LessonQuestionResponse;
use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LessonQuestionController extends Controller
{
    /**
     * Submit a response to a question within a lesson.
     */
    public function submitResponse(Request $request, ContentLesson $lesson, LessonSlide $slide)
    {
        $child = $this->getChild($request);
        
        $validated = $request->validate([
            'block_id' => 'required|string',
            'question_id' => 'required|exists:questions,id',
            'answer_data' => 'required',
            'time_spent_seconds' => 'required|integer|min:0',
            'hints_used' => 'nullable|array',
        ]);
        
        // Get lesson progress
        $progress = LessonProgress::where('child_id', $child->id)
            ->where('lesson_id', $lesson->id)
            ->firstOrFail();
        
        // Get question
        $question = Question::findOrFail($validated['question_id']);
        
        // Check for existing attempts
        $existingAttempts = LessonQuestionResponse::where('child_id', $child->id)
            ->where('lesson_progress_id', $progress->id)
            ->where('question_id', $validated['question_id'])
            ->where('block_id', $validated['block_id'])
            ->count();
        
        // Create response
        $response = LessonQuestionResponse::create([
            'child_id' => $child->id,
            'lesson_progress_id' => $progress->id,
            'slide_id' => $slide->id,
            'block_id' => $validated['block_id'],
            'question_id' => $validated['question_id'],
            'answer_data' => $validated['answer_data'],
            'score_possible' => $question->marks,
            'attempt_number' => $existingAttempts + 1,
            'time_spent_seconds' => $validated['time_spent_seconds'],
            'hints_used' => $validated['hints_used'] ?? [],
            'answered_at' => now(),
        ]);
        
        // Auto-grade using Question model's gradeResponse method
        // Format answer based on question type
        $answerData = $validated['answer_data'];
        
        // For MCQ questions, ensure proper format
        if ($question->question_type === 'mcq') {
            // Extract selectedOption from the answer object if present
            $selectedValue = null;
            
            if (is_array($answerData) && isset($answerData['selectedOption'])) {
                // Frontend sends: {selectedOption: 2, answer: '', ...}
                $selectedValue = $answerData['selectedOption'];
            } elseif (is_array($answerData) && isset($answerData['selected_options'])) {
                // Already in correct format
                $selectedValue = $answerData['selected_options'];
            } else {
                // Raw value
                $selectedValue = $answerData;
            }
            
            // Convert indices to option IDs
            $selectedIndices = is_array($selectedValue) ? $selectedValue : [$selectedValue];
            $optionIds = [];
            
            $questionData = $question->question_data;
            foreach ($selectedIndices as $index) {
                // If index is numeric, convert to option ID
                if (is_numeric($index) && isset($questionData['options'][$index])) {
                    $optionIds[] = $questionData['options'][$index]['id'];
                } else {
                    // Already an option ID
                    $optionIds[] = $index;
                }
            }
            
            // Convert to selected_options format (always an array of option IDs)
            $answerData = [
                'selected_options' => $optionIds
            ];
        } elseif (!is_array($answerData)) {
            // For other question types, wrap in array if needed
            $answerData = [$answerData];
        }
        
        Log::info('Grading question', [
            'question_id' => $question->id,
            'question_type' => $question->question_type,
            'raw_answer' => $validated['answer_data'],
            'formatted_answer' => $answerData
        ]);
        
        $gradeResult = $question->gradeResponse($answerData);
        
        // Update response with grading results
        $response->update([
            'is_correct' => $gradeResult['is_correct'] ?? false,
            'score_earned' => $gradeResult['score'] ?? 0,
            'feedback' => $gradeResult['feedback'] ?? null,
        ]);
        
        // Update progress
        $progress->increment('questions_attempted');
        if ($response->is_correct) {
            $progress->increment('questions_correct');
        }
        $this->updateProgressQuestionScore($progress);
        
        // Check if lesson is complete
        $progress->checkCompletion();
        
        return response()->json([
            'success' => true,
            'response' => [
                'id' => $response->id,
                'is_correct' => $response->is_correct,
                'score_earned' => $response->score_earned,
                'score_possible' => $response->score_possible,
                'feedback' => $response->feedback,
                'attempt_number' => $response->attempt_number,
                'grading_details' => $gradeResult['details'] ?? null,
            ],
            'progress' => [
                'questions_attempted' => $progress->questions_attempted,
                'questions_correct' => $progress->questions_correct,
                'questions_score' => $progress->questions_score,
                'completion_percentage' => $progress->completion_percentage,
            ],
        ]);
    }

    /**
     * Get all responses for a lesson.
     */
    public function getResponses(Request $request, ContentLesson $lesson)
    {
        $child = $this->getChild($request);
        
        $progress = LessonProgress::where('child_id', $child->id)
            ->where('lesson_id', $lesson->id)
            ->firstOrFail();
        
        $responses = LessonQuestionResponse::where('lesson_progress_id', $progress->id)
            ->with(['question', 'slide'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($response) {
                return [
                    'id' => $response->id,
                    'question_id' => $response->question_id,
                    'question_text' => $response->question->question_text ?? null,
                    'slide_id' => $response->slide_id,
                    'slide_title' => $response->slide->title ?? null,
                    'block_id' => $response->block_id,
                    'answer_data' => $response->answer_data,
                    'is_correct' => $response->is_correct,
                    'score_earned' => $response->score_earned,
                    'score_possible' => $response->score_possible,
                    'attempt_number' => $response->attempt_number,
                    'feedback' => $response->feedback,
                    'answered_at' => $response->answered_at,
                ];
            });
        
        return response()->json([
            'responses' => $responses,
            'summary' => [
                'total_attempted' => $progress->questions_attempted,
                'total_correct' => $progress->questions_correct,
                'accuracy_percentage' => $progress->questions_attempted > 0 
                    ? round(($progress->questions_correct / $progress->questions_attempted) * 100, 1)
                    : 0,
                'average_score' => $progress->questions_score,
            ],
        ]);
    }

    /**
     * Retry a question (for questions that allow retries).
     */
    public function retryQuestion(Request $request, ContentLesson $lesson, LessonQuestionResponse $response)
    {
        $child = $this->getChild($request);
        
        // Verify response belongs to this child
        if ($response->child_id !== $child->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }
        
        // Check if retries are allowed (from block settings)
        $slide = $response->slide;
        $blocks = $slide->blocks;
        
        $questionBlock = collect($blocks)->firstWhere('id', $response->block_id);
        
        if (!$questionBlock) {
            return response()->json(['message' => 'Question block not found.'], 404);
        }
        
        $retryAllowed = $questionBlock['content']['retry_allowed'] ?? false;
        $maxAttempts = $questionBlock['content']['max_attempts'] ?? null;
        
        if (!$retryAllowed) {
            return response()->json(['message' => 'Retries not allowed for this question.'], 400);
        }
        
        $currentAttempts = LessonQuestionResponse::where('child_id', $child->id)
            ->where('lesson_progress_id', $response->lesson_progress_id)
            ->where('question_id', $response->question_id)
            ->where('block_id', $response->block_id)
            ->count();
        
        if ($maxAttempts && $currentAttempts >= $maxAttempts) {
            return response()->json(['message' => 'Maximum attempts reached.'], 400);
        }
        
        return response()->json([
            'success' => true,
            'can_retry' => true,
            'attempts_used' => $currentAttempts,
            'max_attempts' => $maxAttempts,
            'question' => [
                'id' => $response->question->id,
                'question_text' => $response->question->question_text,
                'question_type' => $response->question->question_type,
                'options' => $response->question->options,
            ],
        ]);
    }

    /**
     * Get responses for a specific slide.
     */
    public function getSlideResponses(Request $request, ContentLesson $lesson, LessonSlide $slide)
    {
        $child = $this->getChild($request);
        
        $progress = LessonProgress::where('child_id', $child->id)
            ->where('lesson_id', $lesson->id)
            ->firstOrFail();
        
        $responses = LessonQuestionResponse::where('lesson_progress_id', $progress->id)
            ->where('slide_id', $slide->id)
            ->with('question')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($response) {
                return [
                    'id' => $response->id,
                    'question_id' => $response->question_id,
                    'block_id' => $response->block_id,
                    'answer_data' => $response->answer_data,
                    'is_correct' => $response->is_correct,
                    'score_earned' => $response->score_earned,
                    'score_possible' => $response->score_possible,
                    'attempt_number' => $response->attempt_number,
                    'feedback' => $response->feedback,
                ];
            });
        
        return response()->json(['responses' => $responses]);
    }

    /**
     * Update progress question score.
     */
    protected function updateProgressQuestionScore(LessonProgress $progress)
    {
        $responses = LessonQuestionResponse::where('lesson_progress_id', $progress->id)
            ->get();
        
        $totalPossible = $responses->sum('score_possible');
        $totalEarned = $responses->sum('score_earned');
        
        $score = $totalPossible > 0 ? ($totalEarned / $totalPossible) * 100 : 0;
        
        $progress->update([
            'questions_score' => round($score, 2),
        ]);
    }

    /**
     * Helper to get current child.
     */
    protected function getChild(Request $request)
    {
        return $request->user()->children()->firstOrFail();
    }
}
