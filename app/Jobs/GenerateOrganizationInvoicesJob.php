<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationPlan;
use App\Services\BillingService;
use App\Services\PlanUsageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateOrganizationInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(BillingService $billing, PlanUsageService $planService): void
    {
        // Find all organizations with active plans
        $orgIds = OrganizationPlan::where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->distinct()
            ->pluck('organization_id');

        $periodStart = now()->startOfMonth()->toDateString();
        $periodEnd   = now()->endOfMonth()->toDateString();

        foreach ($orgIds as $orgId) {
            try {
                $org = Organization::find($orgId);
                if (! $org || $org->status !== 'active') {
                    continue;
                }

                // Check if invoice already exists for this period
                $existingInvoice = OrganizationInvoice::where('organization_id', $orgId)
                    ->where('period_start', $periodStart)
                    ->where('period_end', $periodEnd)
                    ->first();

                if ($existingInvoice) {
                    Log::info('GenerateOrganizationInvoicesJob: invoice already exists for period', [
                        'organization_id' => $orgId,
                        'period'          => "{$periodStart} to {$periodEnd}",
                    ]);
                    continue;
                }

                // Ensure org has billing customer
                if (! $org->billing_customer_id) {
                    $customerId = $billing->createOrganizationCustomer($org);
                    if (! $customerId) {
                        Log::warning('GenerateOrganizationInvoicesJob: could not create billing customer', [
                            'organization_id' => $orgId,
                        ]);
                        continue;
                    }
                    $org->update(['billing_customer_id' => $customerId]);
                }

                // Calculate costs
                $costData = $planService->calculateMonthlyCost($org);
                if (empty($costData['line_items']) || ($costData['subtotal'] ?? 0) <= 0) {
                    continue;
                }

                // Create invoice in I-BLS-2 (filter out zero-cost items)
                $billingItems = collect($costData['line_items'])
                    ->filter(fn ($item) => ($item['unit_price'] ?? 0) > 0)
                    ->map(fn ($item) => [
                        'description' => $item['label'],
                        'quantity'    => $item['quantity'],
                        'unit_amount' => (int) round($item['unit_price'] * 100),
                    ])->values()->all();

                $invoiceId = $billing->createInvoice([
                    'customer_id' => $org->billing_customer_id,
                    'currency'    => 'gbp',
                    'due_date'    => now()->addDays(14)->toDateString(),
                    'items'       => $billingItems,
                    'auto_bill'   => true,
                ]);

                if (! $invoiceId) {
                    Log::warning('GenerateOrganizationInvoicesJob: could not create invoice', [
                        'organization_id' => $orgId,
                    ]);
                    continue;
                }

                // Finalize the invoice
                $billing->finalizeInvoice($invoiceId);

                // Attempt immediate payment via autopay
                $autopayResult = $billing->enableAutopay($invoiceId);
                $paymentSucceeded = $autopayResult['success'] ?? false;

                // Store locally
                OrganizationInvoice::create([
                    'organization_id'    => $orgId,
                    'period_start'       => $periodStart,
                    'period_end'         => $periodEnd,
                    'line_items'         => $costData['line_items'],
                    'subtotal'           => $costData['subtotal'],
                    'tax'                => $costData['tax'] ?? 0,
                    'total'              => $costData['total'] ?? $costData['subtotal'],
                    'status'             => $paymentSucceeded ? 'paid' : 'pending',
                    'paid_at'            => $paymentSucceeded ? now() : null,
                    'billing_invoice_id' => $invoiceId,
                ]);

                if (! $paymentSucceeded) {
                    Log::warning('GenerateOrganizationInvoicesJob: autopay failed, invoice pending', [
                        'organization_id' => $orgId,
                        'invoice_id'      => $invoiceId,
                    ]);
                }

                Log::info('GenerateOrganizationInvoicesJob: invoice created', [
                    'organization_id' => $orgId,
                    'invoice_id'      => $invoiceId,
                    'total'           => $costData['subtotal'],
                ]);
            } catch (\Throwable $e) {
                Log::error('GenerateOrganizationInvoicesJob: error for org', [
                    'organization_id' => $orgId,
                    'error'           => $e->getMessage(),
                ]);
            }
        }
    }
}
