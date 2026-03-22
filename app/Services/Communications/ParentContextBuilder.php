<?php

namespace App\Services\Communications;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ParentContextBuilder
{
    /**
     * Build a rich context object for the AI agent from a parent's profile.
     */
    public function build(User $parent, Organization $org): array
    {
        $children = $parent->children()
            ->where('organization_id', $org->id)
            ->get(['id', 'child_name', 'age', 'year_group', 'school_name']);

        $childIds = $children->pluck('id')->toArray();

        // Upcoming lessons (next 7 days)
        $upcomingLessons = DB::table('live_sessions')
            ->join('child_live_session', 'live_sessions.id', '=', 'child_live_session.live_session_id')
            ->whereIn('child_live_session.child_id', $childIds)
            ->where('live_sessions.start_time', '>=', now())
            ->where('live_sessions.start_time', '<=', now()->addDays(7))
            ->where('live_sessions.status', '!=', 'cancelled')
            ->select('live_sessions.id', 'live_sessions.title', 'live_sessions.start_time', 'live_sessions.lesson_mode', 'child_live_session.child_id')
            ->orderBy('live_sessions.start_time')
            ->limit(20)
            ->get();

        // Recent assessments
        $recentAssessments = DB::table('assessment_submissions')
            ->join('assessments', 'assessments.id', '=', 'assessment_submissions.assessment_id')
            ->whereIn('assessment_submissions.child_id', $childIds)
            ->where('assessment_submissions.created_at', '>=', now()->subDays(30))
            ->select('assessments.title', 'assessment_submissions.score', 'assessment_submissions.total_marks', 'assessment_submissions.child_id', 'assessment_submissions.created_at')
            ->orderByDesc('assessment_submissions.created_at')
            ->limit(10)
            ->get();

        // Pending homework
        $pendingHomework = DB::table('homework_assignments')
            ->join('homework_targets', 'homework_assignments.id', '=', 'homework_targets.homework_assignment_id')
            ->leftJoin('homework_submissions', function ($join) {
                $join->on('homework_assignments.id', '=', 'homework_submissions.homework_assignment_id')
                    ->on('homework_targets.child_id', '=', 'homework_submissions.child_id');
            })
            ->whereIn('homework_targets.child_id', $childIds)
            ->where('homework_assignments.status', 'published')
            ->whereNull('homework_submissions.id')
            ->where('homework_assignments.due_date', '>=', now())
            ->select('homework_assignments.id', 'homework_assignments.title', 'homework_assignments.due_date', 'homework_targets.child_id')
            ->orderBy('homework_assignments.due_date')
            ->limit(10)
            ->get();

        // Payment status
        $outstandingBalance = DB::table('transactions')
            ->where('user_id', $parent->id)
            ->where('organization_id', $org->id)
            ->whereIn('status', ['pending', 'overdue', 'unpaid'])
            ->sum('amount');

        // Active services
        $activeServices = DB::table('services')
            ->join('child_service', 'services.id', '=', 'child_service.service_id')
            ->whereIn('child_service.child_id', $childIds)
            ->where('services.organization_id', $org->id)
            ->select('services.id', 'services.service_name', 'services.service_type')
            ->distinct()
            ->get();

        // Recent conversation summary
        $recentMessages = DB::table('communication_messages')
            ->where('organization_id', $org->id)
            ->where(function ($q) use ($parent) {
                $q->where('recipient_user_id', $parent->id)
                    ->orWhere('sender_id', $parent->id);
            })
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['channel', 'direction', 'body_text', 'created_at']);

        return [
            'parent' => [
                'id' => $parent->id,
                'name' => $parent->name,
                'email' => $parent->email,
            ],
            'children' => $children->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->child_name,
                'age' => $c->age,
                'year_group' => $c->year_group,
                'school' => $c->school_name,
            ])->toArray(),
            'upcoming_lessons' => $upcomingLessons->groupBy('child_id')->map(fn ($lessons) => $lessons->map(fn ($l) => [
                'id' => $l->id,
                'title' => $l->title,
                'start_time' => $l->start_time,
                'mode' => $l->lesson_mode,
            ])->toArray())->toArray(),
            'recent_assessments' => $recentAssessments->map(fn ($a) => [
                'title' => $a->title,
                'score' => $a->score . '/' . $a->total_marks,
                'child_id' => $a->child_id,
                'date' => $a->created_at,
            ])->toArray(),
            'pending_homework' => $pendingHomework->map(fn ($h) => [
                'id' => $h->id,
                'title' => $h->title,
                'due_date' => $h->due_date,
                'child_id' => $h->child_id,
            ])->toArray(),
            'payment_status' => [
                'outstanding_balance_pence' => (int) $outstandingBalance,
                'outstanding_balance_formatted' => '£' . number_format($outstandingBalance / 100, 2),
            ],
            'active_services' => $activeServices->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->service_name,
                'type' => $s->service_type,
            ])->toArray(),
            'recent_interactions' => $recentMessages->map(fn ($m) => [
                'channel' => $m->channel,
                'direction' => $m->direction,
                'preview' => mb_substr($m->body_text, 0, 100),
                'date' => $m->created_at,
            ])->toArray(),
        ];
    }

    /**
     * Format context as a system prompt string for the AI.
     */
    public function toSystemPrompt(array $context, Organization $org): string
    {
        $orgName = $org->getSetting('branding.organization_name') ?? $org->name;
        $children = collect($context['children']);
        $childList = $children->map(fn ($c) => "{$c['name']} (age {$c['age']}, Year {$c['year_group']})")->join(', ');

        $prompt = "You are a helpful assistant for {$orgName}, a tutoring service. ";
        $prompt .= "You are chatting with {$context['parent']['name']} on WhatsApp. ";
        $prompt .= "They have the following children enrolled: {$childList}.\n\n";

        // Upcoming lessons
        if (!empty($context['upcoming_lessons'])) {
            $prompt .= "UPCOMING LESSONS (next 7 days):\n";
            foreach ($context['upcoming_lessons'] as $childId => $lessons) {
                $childName = $children->firstWhere('id', $childId)['name'] ?? 'Unknown';
                foreach ($lessons as $l) {
                    $prompt .= "- {$childName}: {$l['title']} at {$l['start_time']} ({$l['mode']})\n";
                }
            }
            $prompt .= "\n";
        }

        // Pending homework
        if (!empty($context['pending_homework'])) {
            $prompt .= "PENDING HOMEWORK:\n";
            foreach ($context['pending_homework'] as $hw) {
                $childName = $children->firstWhere('id', $hw['child_id'])['name'] ?? 'Unknown';
                $prompt .= "- {$childName}: {$hw['title']} (due {$hw['due_date']})\n";
            }
            $prompt .= "\n";
        }

        // Payment status
        if ($context['payment_status']['outstanding_balance_pence'] > 0) {
            $prompt .= "PAYMENT: Outstanding balance of {$context['payment_status']['outstanding_balance_formatted']}.\n\n";
        }

        $prompt .= "Be helpful, concise, and friendly. If the parent asks to book, reschedule, or cancel a session, use the available tools. ";
        $prompt .= "If you are unsure or the request requires human judgment, escalate to a human staff member.";

        return $prompt;
    }
}
