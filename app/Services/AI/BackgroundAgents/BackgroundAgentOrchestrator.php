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

                // Create pending run and dispatch the job
                $run = BackgroundAgentRun::create([
                    'organization_id' => $org->id,
                    'agent_type' => $type,
                    'trigger_type' => 'scheduled',
                    'trigger_reference' => "cron:{$schedule}",
                    'status' => BackgroundAgentRun::STATUS_PENDING,
                ]);

                RunBackgroundAgentJob::dispatch($type, $org->id, 'scheduled', "cron:{$schedule}", [], $run->id);

                Log::debug("[BackgroundAgentOrchestrator] Dispatched {$type} for org {$org->id}", ['run_id' => $run->id]);
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

            // Create pending run so it's trackable immediately
            $run = BackgroundAgentRun::create([
                'organization_id' => $organizationId,
                'agent_type' => $type,
                'trigger_type' => 'event',
                'trigger_reference' => $eventClass,
                'status' => BackgroundAgentRun::STATUS_PENDING,
                'summary' => $context, // Store event context so the agent can read it
            ]);

            RunBackgroundAgentJob::dispatch($type, $organizationId, 'event', $eventClass, $context, $run->id);

            Log::debug("[BackgroundAgentOrchestrator] Event-dispatched {$type} for org {$organizationId}", [
                'event' => $eventClass,
                'run_id' => $run->id,
            ]);
        }
    }

    /**
     * Manually trigger an agent for a specific organization.
     * Creates a pending run record immediately so the UI can track it,
     * then dispatches the job which will pick up the pending run.
     */
    public function dispatchManual(string $agentType, int $organizationId): BackgroundAgentRun
    {
        $class = BackgroundAgentRegistry::get($agentType);
        if (!$class) {
            throw new \InvalidArgumentException("Unknown agent type: {$agentType}");
        }

        // Create a pending run record immediately so the UI sees it right away
        $run = BackgroundAgentRun::create([
            'organization_id' => $organizationId,
            'agent_type' => $agentType,
            'trigger_type' => 'manual',
            'trigger_reference' => 'admin_trigger',
            'status' => BackgroundAgentRun::STATUS_PENDING,
        ]);

        RunBackgroundAgentJob::dispatch($agentType, $organizationId, 'manual', 'admin_trigger', [], $run->id);

        Log::info("[BackgroundAgentOrchestrator] Manual dispatch {$agentType} for org {$organizationId}", [
            'run_id' => $run->id,
        ]);

        return $run;
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
