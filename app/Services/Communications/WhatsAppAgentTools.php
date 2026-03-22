<?php

namespace App\Services\Communications;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Tool definitions and executors for the WhatsApp AI Agent.
 * Each method corresponds to a tool the AI can call.
 */
class WhatsAppAgentTools
{
    /**
     * Get the tool definitions for the AI model (function-calling format).
     */
    public static function getToolDefinitions(): array
    {
        return [
            [
                'name' => 'check_schedule',
                'description' => 'Check upcoming lessons/sessions for a child in a date range',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'child_id' => ['type' => 'integer', 'description' => 'The child ID'],
                        'days_ahead' => ['type' => 'integer', 'description' => 'Number of days to look ahead (default 7)'],
                    ],
                    'required' => ['child_id'],
                ],
            ],
            [
                'name' => 'get_progress',
                'description' => 'Get a child\'s recent assessment scores and homework completion status',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'child_id' => ['type' => 'integer', 'description' => 'The child ID'],
                    ],
                    'required' => ['child_id'],
                ],
            ],
            [
                'name' => 'get_balance',
                'description' => 'Get the parent\'s current payment balance and outstanding amounts',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'parent_user_id' => ['type' => 'integer', 'description' => 'The parent user ID'],
                    ],
                    'required' => ['parent_user_id'],
                ],
            ],
            [
                'name' => 'get_upcoming_homework',
                'description' => 'Get pending homework assignments for a child',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'child_id' => ['type' => 'integer', 'description' => 'The child ID'],
                    ],
                    'required' => ['child_id'],
                ],
            ],
            [
                'name' => 'escalate_to_human',
                'description' => 'Escalate the conversation to a human staff member when you cannot help or the parent requests it',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'reason' => ['type' => 'string', 'description' => 'Brief reason for escalation'],
                    ],
                    'required' => ['reason'],
                ],
            ],
        ];
    }

    /**
     * Execute a tool call and return the result.
     */
    public static function execute(string $toolName, array $args, Organization $org): array
    {
        return match ($toolName) {
            'check_schedule' => self::checkSchedule($args, $org),
            'get_progress' => self::getProgress($args, $org),
            'get_balance' => self::getBalance($args, $org),
            'get_upcoming_homework' => self::getUpcomingHomework($args, $org),
            'escalate_to_human' => self::escalateToHuman($args, $org),
            default => ['error' => "Unknown tool: {$toolName}"],
        };
    }

    protected static function checkSchedule(array $args, Organization $org): array
    {
        $childId = $args['child_id'];
        $daysAhead = $args['days_ahead'] ?? 7;

        $lessons = DB::table('live_sessions')
            ->join('child_live_session', 'live_sessions.id', '=', 'child_live_session.live_session_id')
            ->where('child_live_session.child_id', $childId)
            ->where('live_sessions.organization_id', $org->id)
            ->where('live_sessions.start_time', '>=', now())
            ->where('live_sessions.start_time', '<=', now()->addDays($daysAhead))
            ->where('live_sessions.status', '!=', 'cancelled')
            ->select('live_sessions.id', 'live_sessions.title', 'live_sessions.start_time', 'live_sessions.lesson_mode')
            ->orderBy('live_sessions.start_time')
            ->get();

        if ($lessons->isEmpty()) {
            return ['message' => 'No upcoming lessons found in the next ' . $daysAhead . ' days.'];
        }

        return [
            'lessons' => $lessons->map(fn ($l) => [
                'id' => $l->id,
                'title' => $l->title,
                'start_time' => $l->start_time,
                'mode' => $l->lesson_mode,
            ])->toArray(),
        ];
    }

    protected static function getProgress(array $args, Organization $org): array
    {
        $childId = $args['child_id'];

        $assessments = DB::table('assessment_submissions')
            ->join('assessments', 'assessments.id', '=', 'assessment_submissions.assessment_id')
            ->where('assessment_submissions.child_id', $childId)
            ->where('assessment_submissions.created_at', '>=', now()->subDays(30))
            ->select('assessments.title', 'assessment_submissions.score', 'assessment_submissions.total_marks', 'assessment_submissions.created_at')
            ->orderByDesc('assessment_submissions.created_at')
            ->limit(5)
            ->get();

        $homeworkCompleted = DB::table('homework_submissions')
            ->where('child_id', $childId)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $homeworkTotal = DB::table('homework_targets')
            ->join('homework_assignments', 'homework_assignments.id', '=', 'homework_targets.homework_assignment_id')
            ->where('homework_targets.child_id', $childId)
            ->where('homework_assignments.created_at', '>=', now()->subDays(30))
            ->count();

        return [
            'recent_assessments' => $assessments->map(fn ($a) => [
                'title' => $a->title,
                'score' => "{$a->score}/{$a->total_marks}",
                'date' => $a->created_at,
            ])->toArray(),
            'homework_completion' => "{$homeworkCompleted}/{$homeworkTotal} in the last 30 days",
        ];
    }

    protected static function getBalance(array $args, Organization $org): array
    {
        $parentId = $args['parent_user_id'];

        $outstanding = DB::table('transactions')
            ->where('user_id', $parentId)
            ->where('organization_id', $org->id)
            ->whereIn('status', ['pending', 'overdue', 'unpaid'])
            ->sum('amount');

        return [
            'outstanding_balance' => '£' . number_format($outstanding / 100, 2),
            'has_overdue' => $outstanding > 0,
        ];
    }

    protected static function getUpcomingHomework(array $args, Organization $org): array
    {
        $childId = $args['child_id'];

        $homework = DB::table('homework_assignments')
            ->join('homework_targets', 'homework_assignments.id', '=', 'homework_targets.homework_assignment_id')
            ->leftJoin('homework_submissions', function ($join) {
                $join->on('homework_assignments.id', '=', 'homework_submissions.homework_assignment_id')
                    ->on('homework_targets.child_id', '=', 'homework_submissions.child_id');
            })
            ->where('homework_targets.child_id', $childId)
            ->where('homework_assignments.organization_id', $org->id)
            ->where('homework_assignments.status', 'published')
            ->whereNull('homework_submissions.id')
            ->where('homework_assignments.due_date', '>=', now())
            ->select('homework_assignments.title', 'homework_assignments.due_date')
            ->orderBy('homework_assignments.due_date')
            ->limit(10)
            ->get();

        if ($homework->isEmpty()) {
            return ['message' => 'No pending homework assignments.'];
        }

        return [
            'homework' => $homework->map(fn ($h) => [
                'title' => $h->title,
                'due_date' => $h->due_date,
            ])->toArray(),
        ];
    }

    protected static function escalateToHuman(array $args, Organization $org): array
    {
        return [
            'action' => 'escalate',
            'reason' => $args['reason'],
            'message' => 'I\'m connecting you with a team member who can help with this. They\'ll be in touch shortly.',
        ];
    }
}
