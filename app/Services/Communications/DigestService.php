<?php

namespace App\Services\Communications;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DigestService
{
    /**
     * Generate a daily digest summary for an admin or teacher.
     */
    public function generateDailyDigest(User $user, Organization $org): array
    {
        $since = now()->subDay()->startOfDay();

        return [
            'period' => 'daily',
            'date' => now()->toDateString(),
            'new_bookings' => $this->getNewBookings($org, $since),
            'completed_lessons' => $this->getCompletedLessons($org, $since),
            'overdue_payments' => $this->getOverduePayments($org),
            'pending_tasks' => $this->getPendingTasks($org, $user),
            'agent_actions' => $this->getAgentActions($org, $since),
            'messages_summary' => $this->getMessagesSummary($org, $since),
        ];
    }

    /**
     * Generate a weekly digest summary.
     */
    public function generateWeeklyDigest(User $user, Organization $org): array
    {
        $since = now()->subWeek()->startOfDay();
        $daily = $this->generateDailyDigest($user, $org);

        // Add weekly trends
        $daily['period'] = 'weekly';
        $daily['trends'] = [
            'lessons_this_week' => DB::table('live_sessions')
                ->where('organization_id', $org->id)
                ->where('start_time', '>=', $since)
                ->where('status', 'completed')
                ->count(),
            'new_students_this_week' => DB::table('children')
                ->where('organization_id', $org->id)
                ->where('created_at', '>=', $since)
                ->count(),
            'revenue_this_week' => DB::table('transactions')
                ->where('organization_id', $org->id)
                ->where('created_at', '>=', $since)
                ->whereIn('status', ['completed', 'paid', 'successful'])
                ->sum('amount'),
        ];

        return $daily;
    }

    /**
     * Format digest as email body text.
     */
    public function formatAsText(array $digest, Organization $org): string
    {
        $orgName = $org->getSetting('branding.organization_name') ?? $org->name;
        $period = $digest['period'] === 'weekly' ? 'Weekly' : 'Daily';
        $lines = ["{$period} Summary for {$orgName}\n"];

        if ($digest['completed_lessons'] > 0) {
            $lines[] = "Completed Lessons: {$digest['completed_lessons']}";
        }
        if ($digest['new_bookings'] > 0) {
            $lines[] = "New Bookings: {$digest['new_bookings']}";
        }
        if ($digest['overdue_payments'] > 0) {
            $lines[] = "Overdue Payments: {$digest['overdue_payments']}";
        }
        if ($digest['pending_tasks'] > 0) {
            $lines[] = "Pending Tasks: {$digest['pending_tasks']}";
        }
        if ($digest['agent_actions'] > 0) {
            $lines[] = "Agent Actions: {$digest['agent_actions']}";
        }

        $msgSummary = $digest['messages_summary'];
        if (array_sum($msgSummary) > 0) {
            $lines[] = "\nMessages sent: " . implode(', ', array_map(
                fn ($count, $ch) => "{$ch}: {$count}",
                $msgSummary,
                array_keys($msgSummary)
            ));
        }

        if (isset($digest['trends'])) {
            $lines[] = "\nWeekly Trends:";
            $lines[] = "  Lessons completed: {$digest['trends']['lessons_this_week']}";
            $lines[] = "  New students: {$digest['trends']['new_students_this_week']}";
            $lines[] = "  Revenue: £" . number_format(($digest['trends']['revenue_this_week'] ?? 0) / 100, 2);
        }

        return implode("\n", $lines);
    }

    protected function getNewBookings(Organization $org, $since): int
    {
        return DB::table('live_sessions')
            ->where('organization_id', $org->id)
            ->where('created_at', '>=', $since)
            ->count();
    }

    protected function getCompletedLessons(Organization $org, $since): int
    {
        return DB::table('live_sessions')
            ->where('organization_id', $org->id)
            ->where('status', 'completed')
            ->where('updated_at', '>=', $since)
            ->count();
    }

    protected function getOverduePayments(Organization $org): int
    {
        return DB::table('transactions')
            ->where('organization_id', $org->id)
            ->whereIn('status', ['overdue', 'unpaid'])
            ->count();
    }

    protected function getPendingTasks(Organization $org, User $user): int
    {
        return DB::table('admin_tasks')
            ->where('organization_id', $org->id)
            ->where('status', 'open')
            ->count();
    }

    protected function getAgentActions(Organization $org, $since): int
    {
        return DB::table('background_agent_actions')
            ->join('background_agent_runs', 'background_agent_runs.id', '=', 'background_agent_actions.run_id')
            ->where('background_agent_runs.organization_id', $org->id)
            ->where('background_agent_actions.created_at', '>=', $since)
            ->count();
    }

    protected function getMessagesSummary(Organization $org, $since): array
    {
        return DB::table('communication_messages')
            ->where('organization_id', $org->id)
            ->where('direction', 'outbound')
            ->where('created_at', '>=', $since)
            ->selectRaw('channel, COUNT(*) as count')
            ->groupBy('channel')
            ->pluck('count', 'channel')
            ->toArray();
    }
}
