<?php

namespace App\Services\AI;

use App\Models\AgentTokenBalance;
use App\Models\AgentTokenPricing;
use App\Models\AgentTokenTransaction;
use App\Models\AppNotification;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

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

            if ($balance->balance < $platformTokens) {
                throw new \App\Exceptions\InsufficientTokenBalanceException(
                    "Insufficient token balance. Required: {$platformTokens}, available: {$balance->balance}",
                );
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

        // Communication channel breakdown (sms, whatsapp, etc.)
        $byChannel = AgentTokenTransaction::where('organization_id', $org->id)
            ->forPeriod($from, $to)
            ->where('type', AgentTokenTransaction::TYPE_CONSUMPTION)
            ->whereIn('source_type', ['sms', 'whatsapp', 'whatsapp_ai_response', 'voice', 'call_transcription'])
            ->selectRaw('source_type as channel, SUM(ABS(amount)) as total_tokens, COUNT(*) as message_count')
            ->groupBy('source_type')
            ->get()
            ->keyBy('channel')
            ->map(fn ($row) => [
                'tokens' => (int) $row->total_tokens,
                'messages' => (int) $row->message_count,
            ])
            ->toArray();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'consumed' => (int) $consumed,
            'purchased' => (int) $purchased,
            'by_agent' => $byAgent,
            'by_channel' => $byChannel,
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
     * Notify org admins of low balance. Rate-limited to once per hour per org.
     */
    protected function notifyLowBalance(Organization $org, AgentTokenBalance $balance): void
    {
        $cacheKey = "low_balance_notified:{$org->id}";
        if (Cache::has($cacheKey)) {
            return;
        }

        Log::info('[TokenBilling] Low balance alert', [
            'organization_id' => $org->id,
            'balance' => $balance->balance,
            'threshold' => $balance->low_balance_threshold,
        ]);

        // Attempt auto-topup if enabled
        if ($balance->auto_topup_enabled && $balance->auto_topup_amount > 0) {
            $this->processAutoTopup($org, $balance);
            return;
        }

        // Send in-app notification to all org admins
        $adminIds = $org->users()
            ->wherePivotIn('role', ['org_admin', 'super_admin'])
            ->wherePivot('status', 'active')
            ->pluck('users.id');

        foreach ($adminIds as $adminId) {
            AppNotification::create([
                'user_id' => $adminId,
                'title' => 'Low AI Token Balance',
                'message' => "Your AI token balance is low ({$balance->balance} remaining). Top up to avoid service interruption.",
                'type' => 'billing',
                'status' => 'unread',
                'channel' => 'in_app',
            ]);
        }

        // Rate limit: don't notify again for 1 hour
        Cache::put($cacheKey, true, now()->addHour());
    }

    /**
     * Process auto-topup when balance is low.
     */
    protected function processAutoTopup(Organization $org, AgentTokenBalance $balance): void
    {
        $tokens = $balance->auto_topup_amount;

        try {
            $billingService = app(\App\Services\BillingService::class);

            if (!$org->billing_customer_id) {
                Log::warning('[TokenBilling] Auto-topup skipped: no billing customer', ['organization_id' => $org->id]);
                return;
            }

            $methods = $billingService->getPaymentMethods($org->billing_customer_id);
            if (empty($methods['data'] ?? [])) {
                Log::warning('[TokenBilling] Auto-topup skipped: no payment method', ['organization_id' => $org->id]);
                return;
            }

            $exchangeRate = $this->getExchangeRate();
            $amountGbp = $tokens / $exchangeRate;
            $amountPence = (int) round($amountGbp * 100);

            $invoiceId = $billingService->createInvoice([
                'customer_id' => $org->billing_customer_id,
                'currency' => 'gbp',
                'due_date' => now()->toDateString(),
                'items' => [[
                    'description' => "Auto top-up: {$tokens} AI tokens",
                    'quantity' => 1,
                    'unit_amount' => $amountPence,
                ]],
            ]);

            if ($invoiceId) {
                $billingService->finalizeInvoice($invoiceId);
                $autopayResult = $billingService->enableAutopay($invoiceId);

                if ($autopayResult['success'] ?? false) {
                    $this->purchase($org, $tokens, 'auto_topup', null, null, 'Auto top-up purchase');

                    Log::info('[TokenBilling] Auto-topup successful', [
                        'organization_id' => $org->id,
                        'tokens' => $tokens,
                        'amount_gbp' => $amountGbp,
                    ]);
                } else {
                    Log::warning('[TokenBilling] Auto-topup payment failed', ['organization_id' => $org->id]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('[TokenBilling] Auto-topup error', [
                'organization_id' => $org->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
