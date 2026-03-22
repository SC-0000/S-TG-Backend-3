<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Api\ApiController;
use App\Models\AgentTokenBalance;
use App\Models\AgentTokenTransaction;
use App\Models\OrganizationPlan;
use App\Models\PlatformPricing;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillingController extends ApiController
{
    public function overview(): JsonResponse
    {
        $statusScope = Transaction::PAID_STATUSES;

        $totalRevenue = (float) Transaction::whereIn('status', $statusScope)->sum('total');
        $revenueToday = (float) Transaction::whereIn('status', $statusScope)
            ->whereDate('created_at', today())
            ->sum('total');
        $revenueMonth = (float) Transaction::whereIn('status', $statusScope)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('total');

        $activeSubscriptions = DB::table('user_subscriptions')
            ->where('status', 'active')
            ->count();

        // Platform subscription metrics
        $platformPlanIds = Subscription::where('owner_type', 'platform')->pluck('id');
        $platformSubscribers = DB::table('user_subscriptions')
            ->whereIn('subscription_id', $platformPlanIds)
            ->where('status', 'active')
            ->count();
        $platformPlanCount = $platformPlanIds->count();

        // Org subscription metrics
        $orgPlanCount = Subscription::where('owner_type', 'organization')->count();
        $orgSubscribers = DB::table('user_subscriptions')
            ->whereNotIn('subscription_id', $platformPlanIds)
            ->where('status', 'active')
            ->count();

        // Organization plan revenue (admin/teacher AI subscriptions via Plans & Billing)
        $activeOrgPlans = OrganizationPlan::where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->get();

        $orgPlanMonthlyRevenue = 0;
        foreach ($activeOrgPlans as $plan) {
            $qty = $plan->quantity ?? 1;
            $orgPlanMonthlyRevenue += $qty * $plan->getEffectivePrice();
        }
        $orgPlanMonthlyRevenue = round($orgPlanMonthlyRevenue, 2);

        $aiWorkspacePlans = $activeOrgPlans->where('category', 'ai_workspace');
        $aiWorkspaceRevenue = 0;
        foreach ($aiWorkspacePlans as $plan) {
            $qty = $plan->quantity ?? 1;
            $aiWorkspaceRevenue += $qty * $plan->getEffectivePrice();
        }
        $aiWorkspaceRevenue = round($aiWorkspaceRevenue, 2);

        $orgsWithActivePlans = $activeOrgPlans->pluck('organization_id')->unique()->count();

        // Agent token totals across all orgs
        $totalTokensPurchased = (int) AgentTokenBalance::sum('lifetime_purchased');
        $totalTokensConsumed = (int) AgentTokenBalance::sum('lifetime_consumed');
        $totalTokenBalance = (int) AgentTokenBalance::sum('balance');

        return $this->success([
            'metrics' => [
                'total_revenue' => $totalRevenue,
                'revenue_today' => $revenueToday,
                'revenue_month' => $revenueMonth,
                'active_subscriptions' => $activeSubscriptions,
                'total_plans' => Subscription::count(),
                'total_transactions' => Transaction::count(),
                'platform_plans' => $platformPlanCount,
                'platform_subscribers' => $platformSubscribers,
                'org_plans' => $orgPlanCount,
                'org_subscribers' => $orgSubscribers,
                // Organization plan revenue (admin/teacher AI, seats, etc.)
                'org_plan_monthly_revenue' => $orgPlanMonthlyRevenue,
                'ai_workspace_monthly_revenue' => $aiWorkspaceRevenue,
                'ai_workspace_plans' => $aiWorkspacePlans->count(),
                'orgs_with_active_plans' => $orgsWithActivePlans,
                'total_active_org_plans' => $activeOrgPlans->count(),
                // Agent tokens
                'total_tokens_purchased' => $totalTokensPurchased,
                'total_tokens_consumed' => $totalTokensConsumed,
                'total_token_balance' => $totalTokenBalance,
            ],
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $query = Transaction::query()->with('user:id,name,email,current_organization_id');

        ApiQuery::applyFilters($query, $request, [
            'status' => true,
            'type' => true,
            'user_id' => true,
        ]);

        if ($request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
            $query->whereHas('user', fn ($q) => $q->where('current_organization_id', $orgId));
        }

        ApiQuery::applySort($query, $request, ['created_at', 'total', 'status'], '-created_at');

        $transactions = $query->paginate(ApiPagination::perPage($request));
        $data = $transactions->getCollection()->map(function ($transaction) {
            return [
                'id' => $transaction->id,
                'user_id' => $transaction->user_id,
                'user' => $transaction->user ? [
                    'id' => $transaction->user->id,
                    'name' => $transaction->user->name,
                    'email' => $transaction->user->email,
                ] : null,
                'type' => $transaction->type,
                'status' => $transaction->status,
                'payment_method' => $transaction->payment_method,
                'subtotal' => $transaction->subtotal,
                'discount' => $transaction->discount,
                'tax' => $transaction->tax,
                'total' => $transaction->total,
                'paid_at' => $transaction->paid_at?->toISOString(),
                'created_at' => $transaction->created_at?->toISOString(),
            ];
        })->all();

        return $this->paginated($transactions, $data);
    }

    public function subscriptions(Request $request): JsonResponse
    {
        $query = DB::table('user_subscriptions as us')
            ->join('users', 'users.id', '=', 'us.user_id')
            ->join('subscriptions', 'subscriptions.id', '=', 'us.subscription_id')
            ->select(
                'us.id',
                'us.user_id',
                'users.name as user_name',
                'users.email',
                'subscriptions.id as subscription_id',
                'subscriptions.name as plan_name',
                'subscriptions.slug',
                'us.status',
                'us.starts_at',
                'us.ends_at',
                'us.source'
            );

        if ($request->filled('plan')) {
            $query->where('subscriptions.slug', $request->plan);
        }

        if ($request->filled('status')) {
            $query->where('us.status', $request->status);
        }

        if ($request->filled('user_id')) {
            $query->where('us.user_id', $request->integer('user_id'));
        }

        $rows = $query->orderByDesc('us.id')
            ->paginate(ApiPagination::perPage($request));

        $data = collect($rows->items())->map(function ($row) {
            return [
                'id' => $row->id,
                'user_id' => $row->user_id,
                'user_name' => $row->user_name,
                'user_email' => $row->email,
                'subscription_id' => $row->subscription_id,
                'plan_name' => $row->plan_name,
                'plan_slug' => $row->slug,
                'status' => $row->status,
                'starts_at' => $row->starts_at,
                'ends_at' => $row->ends_at,
                'source' => $row->source,
            ];
        })->all();

        $plans = Subscription::select('id', 'name', 'slug')->orderBy('name')->get();

        return $this->paginated($rows, $data, [
            'filters' => $request->only(['plan', 'status', 'user_id']),
            'plans' => $plans,
        ]);
    }

    public function revenue(Request $request): JsonResponse
    {
        $statusScope = Transaction::PAID_STATUSES;
        $days = $request->integer('days', 30);
        $days = min(max($days, 7), 90);

        $byDay = collect(range($days - 1, 0))->map(function ($daysAgo) use ($statusScope) {
            $date = now()->subDays($daysAgo);
            $total = (float) Transaction::whereIn('status', $statusScope)
                ->whereDate('created_at', $date)
                ->sum('total');

            return [
                'date' => $date->toDateString(),
                'total' => $total,
            ];
        })->values();

        return $this->success([
            'daily' => $byDay,
        ]);
    }

    public function invoices(Request $request): JsonResponse
    {
        // User invoices (B2C)
        $userQuery = \App\Models\Invoice::query()
            ->with(['user:id,name,email']);

        if ($request->filled('status')) {
            $userQuery->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $userQuery->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                  ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('from_date')) {
            $userQuery->whereDate('created_at', '>=', $request->input('from_date'));
        }
        if ($request->filled('to_date')) {
            $userQuery->whereDate('created_at', '<=', $request->input('to_date'));
        }

        $invoices = $userQuery->orderByDesc('created_at')
            ->paginate(ApiPagination::perPage($request));

        $userData = $invoices->getCollection()->map(function ($invoice) {
            return [
                'id'             => $invoice->id,
                'type'           => 'user',
                'invoice_number' => $invoice->invoice_number,
                'user'           => $invoice->user ? [
                    'id'    => $invoice->user->id,
                    'name'  => $invoice->user->name,
                    'email' => $invoice->user->email,
                ] : null,
                'amount_due'     => $invoice->amount_due,
                'status'         => $invoice->status,
                'due_date'       => $invoice->due_date,
                'pdf_url'        => $invoice->pdf_url,
                'created_at'     => $invoice->created_at?->toISOString(),
            ];
        })->all();

        // Organization invoices (B2B)
        $orgQuery = \App\Models\OrganizationInvoice::query()
            ->with('organization:id,name');

        if ($request->filled('status')) {
            $orgQuery->where('status', $request->input('status'));
        }
        if ($request->filled('organization_id')) {
            $orgQuery->where('organization_id', $request->integer('organization_id'));
        }
        if ($request->filled('search')) {
            $search = $request->input('search');
            $orgQuery->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                  ->orWhereHas('organization', fn ($oq) => $oq->where('name', 'like', "%{$search}%"));
            });
        }
        if ($request->filled('from_date')) {
            $orgQuery->whereDate('created_at', '>=', $request->input('from_date'));
        }
        if ($request->filled('to_date')) {
            $orgQuery->whereDate('created_at', '<=', $request->input('to_date'));
        }

        $orgInvoices = $orgQuery->orderByDesc('created_at')
            ->paginate(ApiPagination::perPage($request));

        $orgData = $orgInvoices->getCollection()->map(function ($inv) {
            return [
                'id'             => $inv->id,
                'type'           => 'organization',
                'invoice_number' => $inv->invoice_number,
                'organization'   => $inv->organization ? [
                    'id'   => $inv->organization->id,
                    'name' => $inv->organization->name,
                ] : null,
                'amount_due'     => $inv->total,
                'status'         => $inv->status,
                'due_date'       => null,
                'period_start'   => $inv->period_start?->toDateString(),
                'period_end'     => $inv->period_end?->toDateString(),
                'paid_at'        => $inv->paid_at?->toISOString(),
                'line_items'     => $inv->line_items,
                'subtotal'       => $inv->subtotal,
                'tax'            => $inv->tax,
                'total'          => $inv->total,
                'created_at'     => $inv->created_at?->toISOString(),
            ];
        })->all();

        return $this->success([
            'user_invoices' => $userData,
            'user_invoices_pagination' => [
                'total'        => $invoices->total(),
                'count'        => $invoices->count(),
                'per_page'     => $invoices->perPage(),
                'current_page' => $invoices->currentPage(),
                'total_pages'  => $invoices->lastPage(),
            ],
            'organization_invoices' => $orgData,
            'organization_invoices_pagination' => [
                'total'        => $orgInvoices->total(),
                'count'        => $orgInvoices->count(),
                'per_page'     => $orgInvoices->perPage(),
                'current_page' => $orgInvoices->currentPage(),
                'total_pages'  => $orgInvoices->lastPage(),
            ],
        ]);
    }

    public function refund(Request $request, Transaction $transaction): JsonResponse
    {
        $request->validate([
            'amount' => 'nullable|integer|min:1',
            'reason' => 'required|string|max:500',
        ]);

        // Verify transaction is in a refundable state
        if (! in_array($transaction->status, Transaction::PAID_STATUSES)) {
            return $this->error('Transaction is not in a paid status and cannot be refunded.', [], 422);
        }

        if (! $transaction->invoice_id) {
            return $this->error('Transaction has no associated billing invoice.', [], 422);
        }

        $billing = app(\App\Services\BillingService::class);
        $amountCents = $request->input('amount');

        $result = $billing->processRefund($transaction->invoice_id, $amountCents, $request->input('reason'));

        if (! $result) {
            return $this->error('Refund could not be processed via billing provider.', [], 500);
        }

        // Create local refund record
        $refundAmount = $amountCents ? ($amountCents / 100) : $transaction->total;
        $refund = \App\Models\Refund::create([
            'transaction_id'    => $transaction->id,
            'user_id'           => $transaction->user_id,
            'amount_refunded'   => $refundAmount,
            'refund_reason'     => $request->input('reason'),
            'status'            => 'completed',
            'billing_refund_id' => $result['id'] ?? null,
        ]);

        // Update transaction status if full refund
        $totalRefunded = $transaction->refunds()->sum('amount_refunded');
        if ($totalRefunded >= $transaction->total) {
            $transaction->update(['status' => Transaction::STATUS_REFUNDED]);
        }

        // Log
        \App\Models\TransactionLog::create([
            'transaction_id' => $transaction->id,
            'log_message'    => "Refund of {$refundAmount} processed. Reason: {$request->input('reason')}",
            'log_type'       => 'info',
        ]);

        return $this->success([
            'message'        => 'Refund processed successfully.',
            'refund'         => $refund,
            'transaction_id' => $transaction->id,
        ]);
    }

    public function export(Request $request): JsonResponse
    {
        $query = Transaction::query()->with('user:id,name,email');

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->input('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->input('to_date'));
        }
        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->integer('organization_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $transactions = $query->orderByDesc('created_at')->limit(5000)->get();

        $csv = "ID,User,Email,Amount,Status,Payment Method,Date\n";
        foreach ($transactions as $tx) {
            $csv .= implode(',', [
                $tx->id,
                '"' . str_replace('"', '""', $tx->user?->name ?? '') . '"',
                '"' . ($tx->user_email ?? $tx->user?->email ?? '') . '"',
                $tx->total,
                $tx->status,
                $tx->payment_method ?? '',
                $tx->created_at?->toDateTimeString() ?? '',
            ]) . "\n";
        }

        $filename = 'billing-export-' . now()->format('Y-m-d-His') . '.csv';
        $path = 'exports/' . $filename;
        \Illuminate\Support\Facades\Storage::put($path, $csv);

        return $this->success([
            'message'      => 'Export generated.',
            'download_url' => \Illuminate\Support\Facades\Storage::url($path),
            'filename'     => $filename,
            'row_count'    => $transactions->count(),
        ]);
    }
}
