<?php

namespace App\Jobs;

use App\Models\BackgroundAgentRun;
use App\Models\Organization;
use App\Services\AI\BackgroundAgents\BackgroundAgentRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunBackgroundAgentJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 minutes
    public int $tries = 2;
    public array $backoff = [60, 300];

    public function __construct(
        public string $agentType,
        public ?int $organizationId,
        public string $triggerType = 'scheduled',
        public ?string $triggerReference = null,
        public array $context = [],
        public ?int $pendingRunId = null
    ) {
        $this->onQueue(config('queue.agent_queue', 'default'));
    }

    /**
     * Unique ID prevents the same agent running twice concurrently for the same org.
     */
    public function uniqueId(): string
    {
        return "{$this->agentType}_{$this->organizationId}";
    }

    /**
     * Unique lock expires after 15 minutes.
     */
    public int $uniqueFor = 900;

    public function handle(): void
    {
        $class = BackgroundAgentRegistry::get($this->agentType);

        if (!$class) {
            Log::error("[RunBackgroundAgentJob] Unknown agent type: {$this->agentType}");
            $this->markPendingRunFailed('Unknown agent type');
            return;
        }

        $organization = $this->organizationId
            ? Organization::find($this->organizationId)
            : null;

        if ($this->organizationId && !$organization) {
            Log::error("[RunBackgroundAgentJob] Organization not found: {$this->organizationId}");
            $this->markPendingRunFailed('Organization not found');
            return;
        }

        /** @var \App\Services\AI\BackgroundAgents\AbstractBackgroundAgent $agent */
        $agent = new $class($organization);

        $run = $agent->run($this->triggerType, $this->triggerReference, $this->context, $this->pendingRunId);

        Log::info("[RunBackgroundAgentJob] Completed", [
            'agent_type' => $this->agentType,
            'org_id' => $this->organizationId,
            'run_id' => $run->id,
            'status' => $run->status,
        ]);
    }

    /**
     * If the job fails entirely, mark the pending run as failed so the UI reflects it.
     */
    public function failed(?\Throwable $exception): void
    {
        $this->markPendingRunFailed($exception?->getMessage() ?? 'Job failed');
    }

    protected function markPendingRunFailed(string $error): void
    {
        if ($this->pendingRunId) {
            $run = BackgroundAgentRun::find($this->pendingRunId);
            if ($run && $run->status === BackgroundAgentRun::STATUS_PENDING) {
                $run->markFailed($error);
            }
        }
    }

    public function tags(): array
    {
        return [
            'background-agent',
            "agent:{$this->agentType}",
            "org:{$this->organizationId}",
        ];
    }
}
