<?php

namespace App\Services\AI;

use App\Models\AgentTokenBalance;
use App\Models\AgentTokenPricing;
use App\Models\AgentTokenTransaction;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TokenBillingService
{
    /**
     * Check if org has enough balance for estimated token usage.
     */
    public function hasBalance(Organization $org, int $estimatedTokens = 1): bool
    {
        $balance = AgentTokenBalance::getOrCreate($org->id);
        return $balance->hasBalance($estimatedTokens);
    }

    /**
     * Get current balance for an organization.
     */
    public function getBalance(Organization $org): int
    {
        return AgentTokenBalance::getOrCreate($org->id)->balance;
    }

    /**
     * Deduct platform tokens from org balance. Returns the transaction record.
     */
    public function deduct(
        Organization $org,
        int $platformTokens,
        string $sourceType,
        ?int $sourceId = null,
        string $description = 'Agent token consumption',
        array $metadata = []
    ): AgentTokenTransaction {
        return DB::transaction(function () use ($org, $platformTokens, $sourceType, $sourceId, $description, $metadata) {
            $balance = AgentTokenBalance::where('organization_id', $org->id)->lockForUpdate()->first();
            if (!$balance) {
                $balance = AgentTokenBalance::getOrCreate($org->id);
                $balance = AgentTokenBalance::where('organization_id', $org->id)->lockForUpdate()->first();
            }

            $balance->balance -= $platformTokens;
            $balance->lifetime_consumed += $platformTokens;
            $balance->save();

            $transaction = AgentTokenTransaction::create([
                'organization_id' => $org->id,
                'type' => AgentTokenTransaction::TYPE_CONSUMPTION,
                'amount' => -$platformTokens,
                'balance_after' => $balance->balance,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'description' => $description,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);

            if ($balance->isLowBalance()) {
                $this->notifyLowBalance($org, $balance);
            }

            return $transaction;
        });
    }

    /**
     * Purchase/credit tokens to org balance.
     */
    public function purchase(
        Organization $org,
        int $tokens,
        string $sourceType = 'manual',
        ?int $sourceId = null,
        ?User $by = null,
        string $description = 'Token purchase'
    ): AgentTokenTransaction {
        return DB::transaction(function () use ($org, $tokens, $sourceType, $sourceId, $by, $description) {
            $balance = AgentTokenBalance::where('organization_id', $org->id)->lockForUpdate()->first();
            if (!$balance) {
                $balance = AgentTokenBalance::getOrCreate($org->id);
                $balance = AgentTokenBalance::where('organization_id', $org->id)->lockForUpdate()->first();
            }

            $balance->balance += $tokens;
            $balance->lifetime_purchased += $tokens;
            $balance->save();

            return AgentTokenTransaction::create([
                'organization_id' => $org->id,
                'type' => AgentTokenTransaction::TYPE_PURCHASE,
                'amount' => $tokens,
                'balance_after' => $balance->balance,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'description' => $description,
                'metadata' => ['purchased_by' => $by?->id],
                'created_by' => $by?->id,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Refund tokens back to org balance.
     */
    public function refund(
        Organization $org,
        int $tokens,
        string $reason = 'Refund',
        ?int $sourceId = null
    ): AgentTokenTransaction {
        return DB::transaction(function () use ($org, $tokens, $reason, $sourceId) {
            $balance = AgentTokenBalance::where('organization_id', $org->id)->lockForUpdate()->first();
            if (!$balance) {
                $balance = AgentTokenBalance::getOrCreate($org->id);
                $balance = AgentTokenBalance::where('organization_id', $org->id)->lockForUpdate()->first();
            }

            $balance->balance += $tokens;
            $balance->save();

            return AgentTokenTransaction::create([
                'organization_id' => $org->id,
                'type' => AgentTokenTransaction::TYPE_REFUND,
                'amount' => $tokens,
                'balance_after' => $balance->balance,
                'source_type' => 'refund',
                'source_id' => $sourceId,
                'description' => $reason,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Convert real API token usage into platform tokens using the pricing table.
     */
    public function calculatePlatformTokens(
        string $model,
        string $operationType,
        int $inputTokens = 0,
        int $outputTokens = 0
    ): int {
        $pricing = AgentTokenPricing::getActivePricing($model, $operationType);

        if (!$pricing) {
            Log::warning('[TokenBilling] No pricing found', [
                'model' => $model,
                'operation' => $operationType,
            ]);
            // Fallback: 1 platform token per 1k real tokens
            return max(1, (int) ceil(($inputTokens + $outputTokens) / 1000));
        }

        return $pricing->calculateTokens($inputTokens, $outputTokens);
    }

    /**
     * Get usage summary for an organization over a period.
     */
    public function getUsageSummary(Organization $org, $from = null, $to = null): array
    {
        $from = $from ?? now()->startOfMonth();
        $to = $to ?? now();

        $transactions = AgentTokenTransaction::where('organization_id', $org->id)
            ->forPeriod($from, $to);

        $consumed = (clone $transactions)->debits()->sum(DB::raw('ABS(amount)'));
        $purchased = (clone $transactions)->credits()->where('type', AgentTokenTransaction::TYPE_PURCHASE)->sum('amount');

        $byAgent = AgentTokenTransaction::where('organization_id', $org->id)
            ->forPeriod($from, $to)
            ->where('type', AgentTokenTransaction::TYPE_CONSUMPTION)
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.agent_type')) as agent_type, SUM(ABS(amount)) as total_tokens")
            ->groupBy('agent_type')
            ->pluck('total_tokens', 'agent_type')
            ->toArray();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'consumed' => (int) $consumed,
            'purchased' => (int) $purchased,
            'by_agent' => $byAgent,
            'balance' => $this->getBalance($org),
        ];
    }

    /**
     * Get the exchange rate (tokens per £1).
     */
    public function getExchangeRate(): int
    {
        return (int) (config('agents.exchange_rate', 100));
    }

    /**
     * Notify org admins of low balance.
     */
    protected function notifyLowBalance(Organization $org, AgentTokenBalance $balance): void
    {
        Log::info('[TokenBilling] Low balance alert', [
            'organization_id' => $org->id,
            'balance' => $balance->balance,
            'threshold' => $balance->low_balance_threshold,
        ]);

        // TODO: Send in-app notification and email to org admin
    }
}
