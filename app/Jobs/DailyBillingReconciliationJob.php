<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationPlan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Daily reconciliation job — runs once per day to catch any drift
 * between local records and the I-BLS-2 billing system.
 *
 * This is a safety net for missed webhooks, not the primary sync mechanism.
 */
class DailyBillingReconciliationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(BillingService $billing): void
    {
        Log::info('DailyBillingReconciliation: starting');

        $this->reconcileUserTransactions($billing);
        $this->reconcileOrganizationInvoices($billing);
        $this->expireOverduePlans();
        $this->expireOverdueSubscriptions();
        $this->syncStaleCustomerData($billing);

        Log::info('DailyBillingReconciliation: complete');
    }

    /**
     * 1. Check all pending/open user transactions against billing system.
     */
    private function reconcileUserTransactions(BillingService $billing): void
    {
        // Find transactions stuck in pending for more than 2 days
        $staleTransactions = Transaction::where('status', Transaction::STATUS_PENDING)
            ->where('created_at', '<', now()->subDays(2))
            ->whereNotNull('invoice_id')
            ->limit(100)
            ->get();

        foreach ($staleTransactions as $tx) {
            try {
                $user = $tx->user;
                if (! $user || ! $user->billing_customer_id) {
                    continue;
                }

                $billing->updateTransactionStatusesFromBilling($user->billing_customer_id);
            } catch (\Throwable $e) {
                Log::warning('DailyReconciliation: user tx sync failed', [
                    'transaction_id' => $tx->id,
                    'error'          => $e->getMessage(),
                ]);
            }
        }

        Log::info('DailyReconciliation: reconciled user transactions', ['count' => $staleTransactions->count()]);
    }

    /**
     * 2. Sync all unpaid organization invoices against billing system.
     */
    private function reconcileOrganizationInvoices(BillingService $billing): void
    {
        $billing->syncOrganizationInvoiceStatuses();
        Log::info('DailyReconciliation: synced org invoice statuses');
    }

    /**
     * 3. Auto-downgrade expired organization plans.
     */
    private function expireOverduePlans(): void
    {
        $expired = OrganizationPlan::where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update([
                'status'       => 'expired',
                'cancelled_at' => now(),
            ]);

        if ($expired > 0) {
            Log::info('DailyReconciliation: expired org plans', ['count' => $expired]);
        }

        // Also flag plans with failed payment for more than 14 days
        $failedPlans = OrganizationPlan::where('status', 'active')
            ->where('payment_status', 'failed')
            ->where('updated_at', '<', now()->subDays(14))
            ->get();

        foreach ($failedPlans as $plan) {
            $plan->update(['status' => 'suspended']);
            Log::info('DailyReconciliation: suspended plan due to prolonged payment failure', [
                'plan_id' => $plan->id,
                'org_id'  => $plan->organization_id,
            ]);
        }
    }

    /**
     * 4. Cancel expired user subscriptions.
     */
    private function expireOverdueSubscriptions(): void
    {
        $expired = DB::table('user_subscriptions')
            ->where('status', 'active')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now())
            ->update(['status' => 'canceled']);

        if ($expired > 0) {
            Log::info('DailyReconciliation: expired user subscriptions', ['count' => $expired]);
        }
    }

    /**
     * 5. Sync customer data for users/orgs whose details may have drifted.
     * Only processes a limited batch to avoid hammering the billing API.
     */
    private function syncStaleCustomerData(BillingService $billing): void
    {
        // Find users updated in last 24h who have billing customer IDs
        $recentlyUpdatedUsers = User::whereNotNull('billing_customer_id')
            ->where('updated_at', '>=', now()->subDay())
            ->limit(50)
            ->get();

        $synced = 0;
        foreach ($recentlyUpdatedUsers as $user) {
            try {
                $billing->updateCustomer($user);
                $synced++;
            } catch (\Throwable $e) {
                // Non-critical, skip silently
            }
        }

        // Sync recently updated orgs
        $recentlyUpdatedOrgs = Organization::whereNotNull('billing_customer_id')
            ->where('updated_at', '>=', now()->subDay())
            ->limit(20)
            ->get();

        foreach ($recentlyUpdatedOrgs as $org) {
            try {
                $billing->updateOrganizationCustomer($org);
                $synced++;
            } catch (\Throwable $e) {
                // Non-critical, skip silently
            }
        }

        if ($synced > 0) {
            Log::info('DailyReconciliation: synced customer data', ['count' => $synced]);
        }
    }
}
