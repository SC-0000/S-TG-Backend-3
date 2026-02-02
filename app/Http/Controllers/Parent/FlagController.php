<?php

namespace App\Http\Controllers\Parent;

use App\Http\Controllers\Controller;
use App\Models\AIGradingFlag;
use App\Models\AssessmentSubmissionItem;
use App\Models\Child;
use Illuminate\Container\Attributes\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log as FacadesLog;
use Inertia\Inertia;

class FlagController extends Controller
{
    /**
     * Store a new AI grading flag
     */
    public function store(Request $request)
    {
        // Debug: Log what we actually receive
        \Illuminate\Support\Facades\Log::info('Flag submission received:', [
            'all_data' => $request->all(),
            'assessment_submission_item_id' => $request->input('assessment_submission_item_id'),
            'child_id' => $request->input('child_id'),
            'original_grade' => $request->input('original_grade'),
            'student_explanation' => $request->input('student_explanation'),
            'student_explanation_length' => strlen($request->input('student_explanation', '')),
            'flag_reason' => $request->input('flag_reason'),
        ]);

        try {
    $data = $request->validate([
        'assessment_submission_item_id' => 'required|exists:assessment_submission_items,id',
        'child_id' => 'required|exists:children,id',
        'flag_reason' => 'required|in:incorrect_grade,unfair_scoring,missed_content,ai_misunderstood,partial_credit_issue,other',
        'student_explanation' => 'required|string|min:10|max:1000',
        'original_grade' => 'required|numeric|min:0',
    ]);
    \Illuminate\Support\Facades\Log::info('Flag submission validated successfully.', ['validated' => $data]);
} catch (\Illuminate\Validation\ValidationException $e) {
    \Illuminate\Support\Facades\Log::warning('Flag validation failed', [
        'errors' => $e->errors(),
        'input'  => $request->all(),
    ]);
    throw $e; // keep Laravel’s normal 422 response
}
        \Illuminate\Support\Facades\Log::info('Flag submission validated successfully.');
        // Verify that the submission item belongs to the user's child
        $submissionItem = AssessmentSubmissionItem::with(['submission'])
            ->where('id', $request->assessment_submission_item_id)
            ->first();

        if (!$submissionItem) {
            return back()->withErrors(['error' => 'Submission item not found']);
        }
        \Illuminate\Support\Facades\Log::info('child id from request: ' . $request->child_id);
        // Verify the child belongs to this user
        \Illuminate\Support\Facades\Log::info('Verifying child ownership', [
            'child_id' => $request->child_id,
            'user_id' => Auth::id()
        ]);
        $child = Child::where('id', $request->child_id)
            ->where('user_id', Auth::id())
            ->first();

        if (!$child) {
            \Illuminate\Support\Facades\Log::warning('Child not found or unauthorized', [
                'child_id' => $request->child_id,
                'user_id' => Auth::id()
            ]);
            return back()->withErrors(['error' => 'Child not found or unauthorized']);
        }
        \Illuminate\Support\Facades\Log::info('Child ownership verified', ['child_id' => $child->id]);

        // Verify the submission belongs to this child
        if ($submissionItem->submission->child_id != $request->child_id) {
            return back()->withErrors(['error' => 'Submission does not belong to this child']);
        }
        \Illuminate\Support\Facades\Log::info('Submission ownership verified', ['submission_id' => $submissionItem->submission->id]);

        // Check if this item is already flagged by this user
        $existingFlag = AIGradingFlag::where('assessment_submission_item_id', $request->assessment_submission_item_id)
            ->where('user_id', Auth::id())
            ->where('status', 'pending')
            ->first();

        if ($existingFlag) {
            return back()->withErrors(['error' => 'This question has already been flagged for review']);
        }
        \Illuminate\Support\Facades\Log::info('No existing pending flag found for this submission item by this user.');
        // Create the flag
        $flag = AIGradingFlag::create([
            'assessment_submission_item_id' => $request->assessment_submission_item_id,
            'user_id' => Auth::id(),
            'child_id' => $request->child_id,
            'flag_reason' => $request->flag_reason,
            'student_explanation' => $request->student_explanation,
            'original_grade' => $request->original_grade,
            'status' => 'pending',
        ]);
        \Illuminate\Support\Facades\Log::info('AI grading flag created', ['flag_id' => $flag->id]);
        // Auto-create AdminTask for the flag
        \App\Http\Controllers\Admin\FlagController::createAdminTaskForFlag($flag);

        return back()->with('success', 'Review request submitted successfully! Your teacher will review this question.');
    }

    /**
     * Get flags for the current user's children
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        
        $flags = AIGradingFlag::with([
            'child',
            'submissionItem.submission.assessment',
            'adminUser'
        ])
        ->whereHas('child', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })
        ->orderBy('created_at', 'desc')
        ->paginate(10);

        return Inertia::render('Parent/Flags/Index', [
            'flags' => $flags
        ]);
    }

    /**
     * Show a specific flag
     */
    public function show(AIGradingFlag $flag)
    {
        // Verify the flag belongs to the current user's child
        $user = Auth::user();
        
        if ($flag->child->user_id !== $user->id) {
            return back()->withErrors(['error' => 'Unauthorized']);
        }

        $flag->load([
            'child',
            'submissionItem.submission.assessment',
            'adminUser'
        ]);

        return Inertia::render('Parent/Flags/Show', [
            'flag' => $flag
        ]);
    }
}
