<?php

namespace App\Services\AI\BackgroundAgents;

use App\Jobs\RunBackgroundAgentJob;
use App\Models\BackgroundAgentConfig;
use App\Models\BackgroundAgentRun;
use App\Models\Organization;
use Cron\CronExpression;
use Illuminate\Support\Facades\Log;

class BackgroundAgentOrchestrator
{
    /**
     * Dispatch all scheduled agents that are due to run.
     * Called from routes/console.php every 5 minutes.
     */
    public function dispatchScheduled(): void
    {
        $scheduledAgents = BackgroundAgentRegistry::scheduledAgents();

        foreach ($scheduledAgents as $type => $class) {
            // Skip stub agents
            if (method_exists($class, 'isStub') && $class::isStub()) {
                continue;
            }

            $defaultSchedule = $class::getDefaultSchedule();
            if (!$defaultSchedule) {
                continue;
            }

            // Get all active organizations
            $organizations = Organization::active()->get();

            foreach ($organizations as $org) {
                $config = BackgroundAgentConfig::getOrCreate($org->id, $type);

                if (!$config->is_enabled) {
                    continue;
                }

                // Use schedule override or default
                $schedule = $config->schedule_override ?: $defaultSchedule;

                // Check if it's time to run based on cron expression
                if (!$this->isDue($schedule, $config->last_run_at)) {
                    continue;
                }

                // Dispatch the job
                RunBackgroundAgentJob::dispatch($type, $org->id, 'scheduled', "cron:{$schedule}");

                Log::debug("[BackgroundAgentOrchestrator] Dispatched {$type} for org {$org->id}");
            }
        }
    }

    /**
     * Dispatch an agent in response to an event.
     */
    public function dispatchForEvent(string $eventClass, int $organizationId, array $context = []): void
    {
        $agents = BackgroundAgentRegistry::eventDrivenAgents($eventClass);

        foreach ($agents as $type => $class) {
            if (method_exists($class, 'isStub') && $class::isStub()) {
                continue;
            }

            $config = BackgroundAgentConfig::getOrCreate($organizationId, $type);
            if (!$config->is_enabled) {
                continue;
            }

            RunBackgroundAgentJob::dispatch($type, $organizationId, 'event', $eventClass, $context);

            Log::debug("[BackgroundAgentOrchestrator] Event-dispatched {$type} for org {$organizationId}", [
                'event' => $eventClass,
            ]);
        }
    }

    /**
     * Manually trigger an agent for a specific organization. Returns the run.
     */
    public function dispatchManual(string $agentType, int $organizationId): void
    {
        $class = BackgroundAgentRegistry::get($agentType);
        if (!$class) {
            throw new \InvalidArgumentException("Unknown agent type: {$agentType}");
        }

        RunBackgroundAgentJob::dispatch($agentType, $organizationId, 'manual', 'admin_trigger');
    }

    /**
     * Check if a cron schedule is due based on last run time.
     */
    protected function isDue(string $cronExpression, ?\DateTimeInterface $lastRunAt): bool
    {
        try {
            $cron = new CronExpression($cronExpression);
            $nextDue = $cron->getNextRunDate($lastRunAt ?? now()->subDay());

            return $nextDue <= now();
        } catch (\Exception $e) {
            Log::warning("[BackgroundAgentOrchestrator] Invalid cron expression: {$cronExpression}");
            return false;
        }
    }
}
