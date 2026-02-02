<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AIGradingFlag;
use App\Models\AdminTask;
use App\Models\AssessmentSubmissionItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class FlagController extends Controller
{
    /**
     * Display all AI grading flags for admin review
     */
    public function index(Request $request)
    {
        $flags = AIGradingFlag::with([
            'child.parent',
            'submissionItem.submission.assessment',
            'adminUser'
        ])
        ->when($request->status, function ($query, $status) {
            $query->where('status', $status);
        })
        ->when($request->reason, function ($query, $reason) {
            $query->where('flag_reason', $reason);
        })
        ->orderBy('created_at', 'desc')
        ->paginate(15);

        return Inertia::render('Admin/Flags/Index', [
            'flags' => $flags,
            'filters' => $request->only(['status', 'reason']),
            'stats' => [
                'pending' => AIGradingFlag::where('status', 'pending')->count(),
                'resolved' => AIGradingFlag::where('status', 'resolved')->count(),
                'dismissed' => AIGradingFlag::where('status', 'dismissed')->count(),
                'total' => AIGradingFlag::count(),
            ]
        ]);
    }

    /**
     * Show a specific flag with detailed information
     */
    public function show(AIGradingFlag $flag)
    {
        $flag->load([
            'child.parent',
            'submissionItem.submission.assessment',
            'submissionItem.question',
            'adminUser'
        ]);

        return Inertia::render('Admin/Flags/Show', [
            'flag' => $flag
        ]);
    }

    /**
     * Resolve a flag (approve/dismiss)
     */
    public function resolve(Request $request, AIGradingFlag $flag)
    {
        $request->validate([
            'resolution' => 'required|in:approved,dismissed',
            'admin_comment' => 'nullable|string|max:1000',
            'new_grade' => 'nullable|numeric|min:0',
        ]);

        DB::transaction(function () use ($request, $flag) {
            // Update flag status and admin details
            $flag->update([
                'status' => 'resolved',
                'admin_user_id' => Auth::id(),
                'admin_comment' => $request->admin_comment,
                'resolution' => $request->resolution,
                'resolved_at' => now(),
            ]);

            // If approved and new grade provided, update the submission item
            if ($request->resolution === 'approved' && $request->filled('new_grade')) {
                $flag->submissionItem->update([
                    'marks_awarded' => $request->new_grade,
                    'grading_notes' => 'Grade adjusted due to student flag: ' . ($request->admin_comment ?: 'No comment provided'),
                ]);

                // Recalculate submission total
                $this->recalculateSubmissionTotal($flag->submissionItem->submission);
            }

            // Complete related admin task if exists
            AdminTask::where('flag_id', $flag->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'completed',
                    'completed_by' => Auth::id(),
                    'completed_at' => now(),
                    'notes' => 'Flag resolved: ' . $request->resolution
                ]);
        });

        return back()->with('success', 'Flag has been resolved successfully.');
    }

    /**
     * Bulk resolve multiple flags
     */
    public function bulkResolve(Request $request)
    {
        $request->validate([
            'flag_ids' => 'required|array',
            'flag_ids.*' => 'exists:ai_grading_flags,id',
            'resolution' => 'required|in:approved,dismissed',
            'admin_comment' => 'nullable|string|max:500',
        ]);

        $updatedCount = 0;

        DB::transaction(function () use ($request, &$updatedCount) {
            $flags = AIGradingFlag::whereIn('id', $request->flag_ids)
                ->where('status', 'pending')
                ->get();

            foreach ($flags as $flag) {
                $flag->update([
                    'status' => 'resolved',
                    'admin_user_id' => Auth::id(),
                    'admin_comment' => $request->admin_comment,
                    'resolution' => $request->resolution,
                    'resolved_at' => now(),
                ]);

                // Complete related admin task
                AdminTask::where('flag_id', $flag->id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'completed',
                        'completed_by' => Auth::id(),
                        'completed_at' => now(),
                        'notes' => 'Bulk resolved: ' . $request->resolution
                    ]);

                $updatedCount++;
            }
        });

        return back()->with('success', "Successfully resolved {$updatedCount} flags.");
    }

    /**
     * Create an AdminTask for a new flag
     */
    public static function createAdminTaskForFlag(AIGradingFlag $flag)
    {
        $flag->load(['child', 'submissionItem.submission.assessment']);
        
        $task = AdminTask::create([
            'title' => 'Review AI Grading Flag',
            'description' => sprintf(
                'Student %s has flagged a question in assessment "%s" for review. Reason: %s',
                $flag->child->child_name,
                $flag->submissionItem->submission->assessment->title,
                str_replace('_', ' ', $flag->flag_reason)
            ),
            'task_type' => 'flag_review',
            'priority' => 'medium',
            'status' => 'pending',
            'assigned_to' => null, // Can be assigned later
            'flag_id' => $flag->id,
             route('admin.submissions.grade',  $flag->submissionItem->submission_id),
            'metadata' => [
                'submission_id' => $flag->submissionItem->submission_id,
                'item_id' => $flag->assessment_submission_item_id,
                'child_id' => $flag->child_id,
                'original_grade' => $flag->original_grade,
                'flag_reason' => $flag->flag_reason,
                'student_explanation' => $flag->student_explanation,
            ],
            'due_date' => now()->addDays(2), // 2 days to review
            'created_by' => $flag->user_id,
        ]);

        return $task;
    }

    /**
     * Recalculate submission total when individual grades change
     */
    private function recalculateSubmissionTotal($submission)
    {
        $totalMarks = $submission->items()->sum('marks');
        $obtainedMarks = $submission->items()->sum('marks_awarded');
        
        $submission->update([
            'total_marks' => $totalMarks,
            'marks_obtained' => $obtainedMarks,
        ]);
    }

    /**
     * Get flag statistics for dashboard
     */
    public function stats()
    {
        $stats = [
            'pending_flags' => AIGradingFlag::where('status', 'pending')->count(),
            'resolved_today' => AIGradingFlag::where('status', 'resolved')
                ->whereDate('resolved_at', today())
                ->count(),
            'avg_resolution_time' => AIGradingFlag::where('status', 'resolved')
                ->whereNotNull('resolved_at')
                ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as avg_hours')
                ->value('avg_hours'),
            'flag_reasons' => AIGradingFlag::where('status', 'pending')
                ->groupBy('flag_reason')
                ->selectRaw('flag_reason, count(*) as count')
                ->pluck('count', 'flag_reason')
                ->toArray(),
        ];

        return response()->json($stats);
    }
}
