<?php

namespace App\Services\AI\BackgroundAgents\Agents;

use App\Models\ClientHealthScore;
use App\Models\User;
use App\Services\AI\BackgroundAgents\AbstractBackgroundAgent;
use App\Services\ClientHealthService;
use App\Services\Tasks\TaskService;
use Illuminate\Support\Facades\Log;

class ClientHealthAgent extends AbstractBackgroundAgent
{
    public static function getAgentType(): string
    {
        return 'client_health';
    }

    public static function getDefaultSchedule(): string
    {
        return '0 6 * * *'; // Daily at 6 AM
    }

    public static function getDescription(): string
    {
        return 'Computes client health scores and creates admin tasks for flagged clients requiring attention.';
    }

    public static function getEstimatedTokensPerRun(): int
    {
        return 0; // No AI tokens needed — pure data computation
    }

    protected function execute(): void
    {
        if (!$this->organization) {
            return;
        }

        $orgId = $this->organization->id;
        $healthService = app(ClientHealthService::class);
        $taskService = app(TaskService::class);

        // Step 1: Recompute health scores for all parents in this org
        $computed = $healthService->computeForOrganization($orgId);
        $this->incrementProcessed($computed);

        Log::info("ClientHealthAgent: computed scores for {$computed} parents in org {$orgId}");

        // Step 2: Create tasks for flagged clients
        $flaggedScores = ClientHealthScore::forOrganization($orgId)
            ->whereNotNull('flags')
            ->where('flags', '!=', '[]')
            ->where('overall_score', '<', 50)
            ->with('user:id,name')
            ->get();

        $taskFlagMap = [
            'inactive_30d'         => 'client_reengagement',
            'failed_payment'       => 'client_payment_followup',
            'no_upcoming_bookings' => 'client_booking_nudge',
            'credits_expiring'     => 'client_credit_expiring',
        ];

        foreach ($flaggedScores as $score) {
            $flags = $score->flags ?? [];
            $parentName = $score->user?->name ?? "Parent #{$score->user_id}";

            foreach ($flags as $flag) {
                if (!isset($taskFlagMap[$flag])) {
                    continue;
                }

                $taskType = $taskFlagMap[$flag];

                try {
                    $taskService->createFromEvent($taskType, [
                        'title'           => self::taskTitle($flag, $parentName),
                        'description'     => self::taskDescription($flag, $parentName, $score->overall_score),
                        'source'          => 'agent',
                        'source_model'    => $score->user,
                        'organization_id' => $orgId,
                        'user_id'         => $score->user_id,
                        'metadata'        => [
                            'user_id'       => $score->user_id,
                            'flag'          => $flag,
                            'overall_score' => $score->overall_score,
                        ],
                    ]);
                } catch (\Throwable $e) {
                    // Duplicate prevention in TaskService will catch repeat tasks
                    Log::debug("ClientHealthAgent: task creation skipped for {$flag} on user {$score->user_id}: {$e->getMessage()}");
                }
            }
        }
    }

    private static function taskTitle(string $flag, string $parentName): string
    {
        return match ($flag) {
            'inactive_30d'         => "Re-engage: {$parentName} (inactive 30+ days)",
            'failed_payment'       => "Payment follow-up: {$parentName}",
            'no_upcoming_bookings' => "Booking nudge: {$parentName} (no upcoming sessions)",
            'credits_expiring'     => "Credits expiring: {$parentName}",
            default                => "Client action: {$parentName}",
        };
    }

    private static function taskDescription(string $flag, string $parentName, int $score): string
    {
        return match ($flag) {
            'inactive_30d'         => "{$parentName} has been inactive for 30+ days. Health score: {$score}/100. Consider reaching out to re-engage.",
            'failed_payment'       => "{$parentName} has a failed payment in the last 30 days. Health score: {$score}/100. Follow up on payment status.",
            'no_upcoming_bookings' => "{$parentName} has no upcoming sessions booked. Health score: {$score}/100. Suggest booking a session.",
            'credits_expiring'     => "{$parentName} has credits expiring within 7 days. Health score: {$score}/100. Remind them to use or renew credits.",
            default                => "Action needed for {$parentName}. Health score: {$score}/100.",
        };
    }

}
