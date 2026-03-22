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

        // Calculate price based on exchange rate
        $exchangeRate = $this->billing->getExchangeRate();
        $priceInPence = (int) ceil(($tokens / ($exchangeRate ?: 100)) * 100); // price in pence

        // Ensure org has billing customer
        $billingService = app(\App\Services\BillingService::class);
        if (! $org->billing_customer_id) {
            $customerId = $billingService->createOrganizationCustomer($org);
            if (! $customerId) {
                return $this->error('Could not set up billing for this organization. Please configure payment methods first.', [], 422);
            }
            $org->update(['billing_customer_id' => $customerId]);
        }

        // Check if org has a payment method saved
        $paymentMethods = $billingService->getPaymentMethods($org->billing_customer_id);
        $hasPaymentMethod = ! empty($paymentMethods['data'] ?? []);

        if (! $hasPaymentMethod) {
            // Return setup required response — frontend will show SetupWidget
            $billingBase = config('services.billingsystems.base_uri');
            $apiBase = str_contains($billingBase, '/api') ? $billingBase : rtrim($billingBase, '/') . '/api/v1';

            return $this->success([
                'requires_payment_setup' => true,
                'billing_customer_id'    => $org->billing_customer_id,
                'api_key'                => app(\App\Services\BillingService::class)->getPublishableKey(),
                'api_base_url'           => $apiBase,
                'message'                => 'Please add a payment method before purchasing tokens.',
            ]);
        }

        // Create invoice in I-BLS-2
        $invoiceId = $billingService->createInvoice([
            'customer_id' => $org->billing_customer_id,
            'currency'    => 'gbp',
            'due_date'    => now()->addDays(1)->toDateString(),
            'items'       => [
                [
                    'description' => "{$tokens} AI Agent Tokens",
                    'quantity'    => 1,
                    'unit_amount' => $priceInPence,
                ],
            ],
            'auto_bill' => true,
        ]);

        if (! $invoiceId) {
            return $this->error('Could not create invoice. Please try again.', [], 500);
        }

        // Finalize the invoice so it can be paid
        $billingService->finalizeInvoice($invoiceId);

        // Try autopay with default payment method
        $autopayResult = $billingService->enableAutopay($invoiceId);

        if ($autopayResult['success'] ?? false) {
            // Payment succeeded — credit tokens
            $transaction = $this->billing->purchase(
                $org,
                $tokens,
                'billing',
                null,
                $request->user(),
                "Token purchase of {$tokens} tokens (invoice: {$invoiceId})"
            );

            $balance = AgentTokenBalance::getOrCreate($orgId);

            // Create local invoice record so it shows in Plans & Billing
            $priceDecimal = $priceInPence / 100;
            \App\Models\OrganizationInvoice::create([
                'organization_id'    => $org->id,
                'period_start'       => now()->toDateString(),
                'period_end'         => now()->toDateString(),
                'line_items'         => [[
                    'label'      => "{$tokens} AI Agent Tokens",
                    'quantity'   => 1,
                    'unit_price' => $priceDecimal,
                    'total'      => $priceDecimal,
                ]],
                'subtotal'           => $priceDecimal,
                'tax'                => 0,
                'total'              => $priceDecimal,
                'status'             => 'paid',
                'paid_at'            => now(),
                'billing_invoice_id' => $invoiceId,
            ]);

            return $this->success([
                'transaction'    => $transaction,
                'new_balance'    => $balance->balance,
                'invoice_id'     => $invoiceId,
                'payment_status' => 'paid',
                'message'        => "Payment of \u00a3" . number_format($priceDecimal, 2) . " processed. {$tokens} tokens credited.",
            ]);
        }

        // Autopay failed — return invoice for manual payment via widget
        $billingBase = config('services.billingsystems.base_uri');
        $apiBase = str_contains($billingBase, '/api') ? $billingBase : rtrim($billingBase, '/') . '/api/v1';

        return $this->success([
            'requires_payment'       => true,
            'invoice_id'             => $invoiceId,
            'billing_customer_id'    => $org->billing_customer_id,
            'api_key'                => app(\App\Services\BillingService::class)->getPublishableKey(),
            'api_base_url'           => $apiBase,
            'tokens'                 => $tokens,
            'amount_pence'           => $priceInPence,
            'message'                => 'Please complete payment to receive your tokens.',
        ]);
    }

    /**
     * POST /api/admin/agents/tokens/confirm-purchase
     * Called after payment widget completes — credits tokens after verifying invoice is paid.
     */
    public function confirmPurchase(Request $request): JsonResponse
    {
        $request->validate([
            'invoice_id' => 'required|string',
            'tokens'     => 'required|integer|min:100',
        ]);

        $orgId = $request->attributes->get('organization_id');
        if (!$orgId) {
            return $this->error('Organization context required', [], 400);
        }

        $org = Organization::findOrFail($orgId);

        // Verify invoice is paid in billing system
        $billingService = app(\App\Services\BillingService::class);
        $customer = $billingService->getCustomerById($org->billing_customer_id);
        $invoices = $customer['data']['invoices'] ?? [];
        $invoiceId = $request->input('invoice_id');

        $isPaid = collect($invoices)->contains(function ($inv) use ($invoiceId) {
            return ($inv['id'] ?? '') === $invoiceId && strtolower($inv['status'] ?? '') === 'paid';
        });

        if (! $isPaid) {
            return $this->error('Invoice has not been paid yet. Please complete payment first.', [], 422);
        }

        $tokens = $request->integer('tokens');

        $transaction = $this->billing->purchase(
            $org,
            $tokens,
            'billing',
            null,
            $request->user(),
            "Token purchase of {$tokens} tokens (invoice: {$invoiceId})"
        );

        $balance = AgentTokenBalance::getOrCreate($orgId);

        return $this->success([
            'transaction' => $transaction,
            'new_balance' => $balance->balance,
            'message'     => "Successfully purchased {$tokens} tokens.",
        ]);
    }

    /**
     * GET /api/admin/agents/tokens/billing-setup
     * Returns billing customer_id and api_key for the org, creating customer if needed.
     */
    public function billingSetup(Request $request): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');
        if (!$orgId) {
            return $this->error('Organization context required', [], 400);
        }

        $org = Organization::findOrFail($orgId);
        $billingService = app(\App\Services\BillingService::class);

        if (! $org->billing_customer_id) {
            $customerId = $billingService->createOrganizationCustomer($org);
            if ($customerId) {
                $org->update(['billing_customer_id' => $customerId]);
            }
        }

        $paymentMethods = [];
        if ($org->billing_customer_id) {
            $methods = $billingService->getPaymentMethods($org->billing_customer_id);
            $paymentMethods = $methods['data'] ?? [];
        }

        $billingBaseUrl = config('services.billingsystems.base_uri');
        $apiBaseUrl = $billingBaseUrl;
        if (! str_contains($billingBaseUrl, '/api')) {
            $apiBaseUrl = rtrim($billingBaseUrl, '/') . '/api/v1';
        }

        return $this->success([
            'billing_customer_id' => $org->billing_customer_id,
            'api_key'             => $billingService->getPublishableKey(),
            'api_base_url'        => $apiBaseUrl,
            'payment_methods'     => $paymentMethods,
            'has_payment_method'  => ! empty($paymentMethods),
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
