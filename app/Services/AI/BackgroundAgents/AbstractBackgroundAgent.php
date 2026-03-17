<?php

namespace App\Services\AI\BackgroundAgents;

use App\Models\BackgroundAgentAction;
use App\Models\BackgroundAgentConfig;
use App\Models\BackgroundAgentRun;
use App\Models\Organization;
use App\Services\AI\AIUtilityService;
use App\Services\AI\TokenBillingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

abstract class AbstractBackgroundAgent
{
    protected ?Organization $organization;
    protected BackgroundAgentRun $currentRun;
    protected AIUtilityService $ai;
    protected TokenBillingService $billing;
    protected int $tokensConsumedThisRun = 0;
    protected string $model = 'gpt-5-nano';

    public function __construct(?Organization $organization = null)
    {
        $this->organization = $organization;
        $this->ai = app(AIUtilityService::class);
        $this->billing = app(TokenBillingService::class);
    }

    /**
     * Agent-specific execution logic — implement in each agent.
     */
    abstract protected function execute(): void;

    /**
     * The agent_type identifier (e.g. 'data_quality', 'assessment_feedback').
     */
    abstract public static function getAgentType(): string;

    /**
     * Default cron schedule (e.g. '0 2 * * *' for 2 AM daily).
     */
    abstract public static function getDefaultSchedule(): string;

    /**
     * Human-readable agent description.
     */
    abstract public static function getDescription(): string;

    /**
     * Estimated platform tokens consumed per run (for budget display).
     */
    abstract public static function getEstimatedTokensPerRun(): int;

    /**
     * Event classes this agent responds to (override in subclass).
     */
    public static function getEventTriggers(): array
    {
        return [];
    }

    /**
     * Template method: wraps execute() with logging, budget checks, and cost tracking.
     */
    final public function run(string $triggerType, ?string $triggerReference = null, array $context = []): BackgroundAgentRun
    {
        $agentType = static::getAgentType();

        // Check if enabled for this org
        if ($this->organization) {
            $config = BackgroundAgentConfig::getOrCreate($this->organization->id, $agentType);
            if (!$config->is_enabled) {
                return $this->createSkippedRun($triggerType, $triggerReference, 'Agent disabled for this organization');
            }
        }

        // Check token balance
        if ($this->organization && !$this->billing->hasBalance($this->organization, static::getEstimatedTokensPerRun())) {
            return $this->createSkippedRun($triggerType, $triggerReference, 'Insufficient token balance');
        }

        // Create run record
        $this->currentRun = BackgroundAgentRun::create([
            'organization_id' => $this->organization?->id,
            'agent_type' => $agentType,
            'trigger_type' => $triggerType,
            'trigger_reference' => $triggerReference,
            'status' => BackgroundAgentRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        try {
            $this->execute();

            // Deduct consumed tokens
            if ($this->organization && $this->tokensConsumedThisRun > 0) {
                $this->billing->deduct(
                    $this->organization,
                    $this->tokensConsumedThisRun,
                    'agent_run',
                    $this->currentRun->id,
                    "{$agentType} agent run",
                    ['agent_type' => $agentType, 'run_id' => $this->currentRun->id]
                );
            }

            $this->currentRun->update([
                'platform_tokens_used' => $this->tokensConsumedThisRun,
            ]);

            $this->currentRun->markCompleted([
                'tokens_used' => $this->tokensConsumedThisRun,
                'items_processed' => $this->currentRun->items_processed,
                'items_affected' => $this->currentRun->items_affected,
            ]);

            // Update last_run_at on config
            if ($this->organization) {
                BackgroundAgentConfig::getOrCreate($this->organization->id, $agentType)
                    ->update(['last_run_at' => now()]);
            }

            Log::info("[BackgroundAgent] {$agentType} completed", [
                'org_id' => $this->organization?->id,
                'run_id' => $this->currentRun->id,
                'items_processed' => $this->currentRun->items_processed,
                'items_affected' => $this->currentRun->items_affected,
                'tokens_used' => $this->tokensConsumedThisRun,
            ]);

        } catch (\Throwable $e) {
            $this->currentRun->markFailed($e->getMessage());

            Log::error("[BackgroundAgent] {$agentType} failed", [
                'org_id' => $this->organization?->id,
                'run_id' => $this->currentRun->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $this->currentRun;
    }

    /**
     * Generate text via AI, tracking cost.
     */
    protected function aiGenerateText(string $prompt, string $systemPrompt = '', array $options = []): string
    {
        $result = $this->ai->generateText($prompt, $systemPrompt, array_merge(['model' => $this->model], $options));

        $platformTokens = $this->billing->calculatePlatformTokens(
            $result['model'],
            'text_generation',
            $result['usage']['prompt_tokens'],
            $result['usage']['completion_tokens']
        );

        $this->tokensConsumedThisRun += $platformTokens;

        $this->logAction(
            BackgroundAgentAction::ACTION_GENERATE_TEXT,
            null,
            'Generated text (' . $result['usage']['total_tokens'] . ' API tokens → ' . $platformTokens . ' platform tokens)',
            null,
            null,
            $platformTokens
        );

        return $result['text'];
    }

    /**
     * Generate structured JSON via AI, tracking cost.
     */
    protected function aiGenerateStructured(string $prompt, string $systemPrompt = '', array $options = []): array
    {
        $result = $this->ai->generateStructuredOutput($prompt, $systemPrompt, array_merge(['model' => $this->model], $options));

        $platformTokens = $this->billing->calculatePlatformTokens(
            $result['model'],
            'text_generation',
            $result['usage']['prompt_tokens'],
            $result['usage']['completion_tokens']
        );

        $this->tokensConsumedThisRun += $platformTokens;

        return $result['data'];
    }

    /**
     * Generate an image via Nana Banana Pro, tracking cost.
     */
    protected function aiGenerateImage(string $prompt, array $options = []): array
    {
        $result = $this->ai->generateImage($prompt, $this->organization, $options);

        $platformTokens = $this->billing->calculatePlatformTokens(
            'nana-banana-pro',
            'image_generation',
            0,
            0
        );

        $this->tokensConsumedThisRun += $platformTokens;

        $this->logAction(
            BackgroundAgentAction::ACTION_GENERATE_IMAGE,
            null,
            'Generated image (' . $platformTokens . ' platform tokens)',
            null,
            ['url' => $result['url']],
            $platformTokens
        );

        return $result;
    }

    /**
     * Log an action for the current run.
     */
    protected function logAction(
        string $actionType,
        ?Model $target,
        string $description,
        ?array $before = null,
        ?array $after = null,
        int $platformTokens = 0,
        string $status = BackgroundAgentAction::STATUS_SUCCESS,
        ?string $errorMessage = null
    ): BackgroundAgentAction {
        return BackgroundAgentAction::create([
            'run_id' => $this->currentRun->id,
            'action_type' => $actionType,
            'target_type' => $target ? get_class($target) : null,
            'target_id' => $target?->id,
            'description' => $description,
            'before_value' => $before,
            'after_value' => $after,
            'platform_tokens_used' => $platformTokens,
            'status' => $status,
            'error_message' => $errorMessage,
            'created_at' => now(),
        ]);
    }

    /**
     * Increment items processed counter.
     */
    protected function incrementProcessed(int $count = 1): void
    {
        $this->currentRun->increment('items_processed', $count);
    }

    /**
     * Increment items affected counter.
     */
    protected function incrementAffected(int $count = 1): void
    {
        $this->currentRun->increment('items_affected', $count);
    }

    /**
     * Check if there's remaining budget for more AI calls.
     */
    protected function hasRemainingBudget(): bool
    {
        if (!$this->organization) {
            return true;
        }

        $remaining = $this->billing->getBalance($this->organization) - $this->tokensConsumedThisRun;
        return $remaining > 0;
    }

    /**
     * Read a config value for this agent from BackgroundAgentConfig.settings.
     */
    protected function getConfig(string $key, mixed $default = null): mixed
    {
        if (!$this->organization) {
            return $default;
        }

        $config = BackgroundAgentConfig::getOrCreate($this->organization->id, static::getAgentType());
        return $config->getSetting($key, $default);
    }

    /**
     * Create a skipped run record.
     */
    protected function createSkippedRun(string $triggerType, ?string $triggerReference, string $reason): BackgroundAgentRun
    {
        $run = BackgroundAgentRun::create([
            'organization_id' => $this->organization?->id,
            'agent_type' => static::getAgentType(),
            'trigger_type' => $triggerType,
            'trigger_reference' => $triggerReference,
            'status' => BackgroundAgentRun::STATUS_SKIPPED,
            'started_at' => now(),
            'completed_at' => now(),
            'error_message' => $reason,
        ]);

        Log::info("[BackgroundAgent] " . static::getAgentType() . " skipped: {$reason}", [
            'org_id' => $this->organization?->id,
        ]);

        return $run;
    }
}
