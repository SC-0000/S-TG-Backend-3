<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\AgentTokenBalance;
use App\Models\AgentTokenPricing;
use App\Models\AgentTokenTransaction;
use App\Models\Organization;
use App\Services\AI\TokenBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentTokenController extends ApiController
{
    protected TokenBillingService $billing;

    public function __construct(TokenBillingService $billing)
    {
        $this->billing = $billing;
    }

    /**
     * GET /api/admin/agents/tokens/balance
     * Current balance and usage summary.
     */
    public function balance(Request $request): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');
        if (!$orgId) {
            return $this->error('Organization context required', [], 400);
        }

        $org = Organization::findOrFail($orgId);
        $balance = AgentTokenBalance::getOrCreate($orgId);
        $usage = $this->billing->getUsageSummary($org);

        return $this->success([
            'balance' => $balance->balance,
            'lifetime_purchased' => $balance->lifetime_purchased,
            'lifetime_consumed' => $balance->lifetime_consumed,
            'low_balance_threshold' => $balance->low_balance_threshold,
            'is_low_balance' => $balance->isLowBalance(),
            'auto_topup_enabled' => $balance->auto_topup_enabled,
            'auto_topup_amount' => $balance->auto_topup_amount,
            'exchange_rate' => $this->billing->getExchangeRate(),
            'current_period_usage' => $usage,
        ]);
    }

    /**
     * GET /api/admin/agents/tokens/transactions
     * Paginated transaction history.
     */
    public function transactions(Request $request): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');
        if (!$orgId) {
            return $this->error('Organization context required', [], 400);
        }

        $query = AgentTokenTransaction::where('organization_id', $orgId)
            ->orderByDesc('created_at');

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $transactions = $query->paginate($request->integer('per_page', 20));

        return $this->success($transactions);
    }

    /**
     * POST /api/admin/agents/tokens/purchase
     * Purchase tokens for the organization.
     */
    public function purchase(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => 'required|integer|min:100',
        ]);

        $orgId = $request->attributes->get('organization_id');
        if (!$orgId) {
            return $this->error('Organization context required', [], 400);
        }

        $org = Organization::findOrFail($orgId);
        $tokens = $request->integer('amount');

        // TODO: Integrate with Stripe/payment gateway for actual charge
        // For now, manual credit by admin
        $transaction = $this->billing->purchase(
            $org,
            $tokens,
            'manual',
            null,
            $request->user(),
            "Manual token purchase of {$tokens} tokens"
        );

        $balance = AgentTokenBalance::getOrCreate($orgId);

        return $this->success([
            'transaction' => $transaction,
            'new_balance' => $balance->balance,
        ]);
    }

    /**
     * GET /api/admin/agents/tokens/usage
     * Usage breakdown by agent and period.
     */
    public function usage(Request $request): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');
        if (!$orgId) {
            return $this->error('Organization context required', [], 400);
        }

        $org = Organization::findOrFail($orgId);
        $from = $request->date('from', now()->startOfMonth());
        $to = $request->date('to', now());

        $summary = $this->billing->getUsageSummary($org, $from, $to);

        // Daily breakdown
        $dailyUsage = AgentTokenTransaction::where('organization_id', $orgId)
            ->where('type', AgentTokenTransaction::TYPE_CONSUMPTION)
            ->forPeriod($from, $to)
            ->selectRaw("DATE(created_at) as date, SUM(ABS(amount)) as tokens")
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('tokens', 'date');

        return $this->success([
            'summary' => $summary,
            'daily_usage' => $dailyUsage,
        ]);
    }

    /**
     * PUT /api/admin/agents/tokens/settings
     * Update token settings (threshold, auto-topup).
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');
        if (!$orgId) {
            return $this->error('Organization context required', [], 400);
        }

        $balance = AgentTokenBalance::getOrCreate($orgId);

        if ($request->has('low_balance_threshold')) {
            $balance->low_balance_threshold = $request->integer('low_balance_threshold');
        }
        if ($request->has('auto_topup_enabled')) {
            $balance->auto_topup_enabled = $request->boolean('auto_topup_enabled');
        }
        if ($request->has('auto_topup_amount')) {
            $balance->auto_topup_amount = $request->integer('auto_topup_amount');
        }

        $balance->save();

        return $this->success($balance);
    }

    // ─── SUPER ADMIN PRICING ENDPOINTS ───────────────────────────

    /**
     * GET /api/super-admin/agent-pricing
     */
    public function pricingIndex(): JsonResponse
    {
        $pricing = AgentTokenPricing::orderBy('ai_model')
            ->orderBy('operation_type')
            ->orderByDesc('effective_from')
            ->get();

        return $this->success($pricing);
    }

    /**
     * POST /api/super-admin/agent-pricing
     */
    public function pricingStore(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'ai_model' => 'required|string|max:50',
            'operation_type' => 'required|string|max:50',
            'platform_tokens_per_1k_input' => 'integer|min:0',
            'platform_tokens_per_1k_output' => 'integer|min:0',
            'platform_tokens_flat' => 'nullable|integer|min:0',
            'effective_from' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $pricing = AgentTokenPricing::create($request->only([
            'name', 'ai_model', 'operation_type',
            'platform_tokens_per_1k_input', 'platform_tokens_per_1k_output',
            'platform_tokens_flat', 'effective_from', 'notes',
        ]));

        return $this->success($pricing, [], 201);
    }

    /**
     * PUT /api/super-admin/agent-pricing/{id}
     */
    public function pricingUpdate(Request $request, int $id): JsonResponse
    {
        $pricing = AgentTokenPricing::findOrFail($id);

        $pricing->update($request->only([
            'name', 'ai_model', 'operation_type',
            'platform_tokens_per_1k_input', 'platform_tokens_per_1k_output',
            'platform_tokens_flat', 'is_active', 'effective_from', 'notes',
        ]));

        return $this->success($pricing);
    }

    /**
     * DELETE /api/super-admin/agent-pricing/{id}
     */
    public function pricingDestroy(int $id): JsonResponse
    {
        $pricing = AgentTokenPricing::findOrFail($id);
        $pricing->update(['is_active' => false]);

        return $this->success(['message' => 'Pricing rule deactivated']);
    }
}
