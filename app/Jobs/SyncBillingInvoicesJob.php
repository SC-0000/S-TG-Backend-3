<?php

namespace App\Jobs;

use App\Services\BillingService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncBillingInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected ?string $billingCustomerId;

    /**
     * Create a new job instance.
     *
     * If $billingCustomerId is provided the job will sync a single customer.
     * If null, it will sync all users with role == User::ROLE_GUEST_PARENT.
     */
    public function __construct(?string $billingCustomerId = null)
    {
        $this->billingCustomerId = $billingCustomerId;
    }

    /**
     * Execute the job.
     */
    public function handle(BillingService $billing)
    {
        Log::info('SyncBillingInvoicesJob: started', ['billingCustomerId' => $this->billingCustomerId]);
        try {
            // Helper closure to inspect a customer's invoices and promote open->draft if older than 2 days.
            $inspectAndUpdate = function (?array $customerData, ?int $userId = null) use ($billing) {
                if (empty($customerData) || ! is_array($customerData)) {
                    Log::info('SyncBillingInvoicesJob: empty customer data, skipping', ['user_id' => $userId]);
                    return;
                }

                $invoices = $customerData['data']['invoices'] ?? ($customerData['invoices'] ?? null);
                if (empty($invoices) || ! is_array($invoices)) {
                    Log::info('SyncBillingInvoicesJob: no invoices found for customer', ['user_id' => $userId]);
                    return;
                }

                foreach ($invoices as $invoice) {
                    $invoiceId = $invoice['id'] ?? null;
                    $status = strtolower($invoice['status'] ?? '');
                    if (! $invoiceId) {
                        continue;
                    }

                    // log invoice summary for troubleshooting
                    Log::debug('SyncBillingInvoicesJob: invoice summary', [
                        'user_id' => $userId,
                        'invoice_id' => $invoiceId,
                        'status' => $status,
                        'raw' => $invoice,
                    ]);

                    if ($status !== 'open') {
                        continue;
                    }

                    $dateStr = $invoice['created_at'] ?? $invoice['issued_at'] ?? $invoice['due_date'] ?? null;
                    if (! $dateStr) {
                        Log::warning('SyncBillingInvoicesJob: invoice missing date fields; skipping age check', [
                            'user_id' => $userId,
                            'invoice_id' => $invoiceId,
                        ]);
                        continue;
                    }

                    try {
                        $created = Carbon::parse($dateStr);
                    } catch (\Throwable $e) {
                        Log::warning('SyncBillingInvoicesJob: failed to parse invoice date', [
                            'user_id' => $userId,
                            'invoice_id' => $invoiceId,
                            'date' => $dateStr,
                            'error' => (string) $e,
                        ]);
                        continue;
                    }

                    if ($created->diffInDays(now()) >= 2) {
                        Log::info('SyncBillingInvoicesJob: open invoice older than 2 days — updating to draft', [
                            'user_id' => $userId,
                            'invoice_id' => $invoiceId,
                            'created_at' => $dateStr,
                        ]);

                        try {
                            $result = $billing->updateInvoiceStatus($invoiceId, 'draft');
                            Log::info('SyncBillingInvoicesJob: updateInvoiceStatus response', [
                                'user_id' => $userId,
                                'invoice_id' => $invoiceId,
                                'result' => $result,
                            ]);
                        } catch (\Throwable $e) {
                            Log::error('SyncBillingInvoicesJob: failed to call updateInvoiceStatus', [
                                'user_id' => $userId,
                                'invoice_id' => $invoiceId,
                                'error' => (string) $e,
                            ]);
                        }
                    } else {
                        Log::debug('SyncBillingInvoicesJob: open invoice not old enough', [
                            'user_id' => $userId,
                            'invoice_id' => $invoiceId,
                            'created_at' => $dateStr,
                            'age_days' => $created->diffInDays(now()),
                        ]);
                    }
                }
            };

            if ($this->billingCustomerId) {
                Log::info('SyncBillingInvoicesJob: starting sync for billingCustomerId', ['billingCustomerId' => $this->billingCustomerId]);

                // Keep existing behavior: update local transaction statuses first
                $billing->updateTransactionStatusesFromBilling($this->billingCustomerId);

                // Then fetch customer data and inspect invoices, calling updateInvoiceStatus as needed
                $customerData = $billing->getCustomerById($this->billingCustomerId);
                $inspectAndUpdate($customerData, null);

                Log::info('SyncBillingInvoicesJob: finished sync for billingCustomerId', ['billingCustomerId' => $this->billingCustomerId]);
                return;
            }

            Log::info('SyncBillingInvoicesJob: starting full sync for guest_parent users');

            // iterate users with role guest_parent and a billing_customer_id
            $users = User::where('role', User::ROLE_GUEST_PARENT)
                ->whereNotNull('billing_customer_id')
                ->cursor();

            foreach ($users as $user) {
                $customerId = $user->billing_customer_id;
                if (! $customerId) {
                    continue;
                }
                try {
                    Log::info('SyncBillingInvoicesJob: syncing user', ['user_id' => $user->id, 'billingCustomerId' => $customerId]);

                    // Keep existing behavior: update local transaction statuses
                    $billing->updateTransactionStatusesFromBilling($customerId);

                    // Fetch customer data and inspect invoices; pass user id for logs
                    $customerData = $billing->getCustomerById($customerId);
                    $inspectAndUpdate($customerData, $user->id);
                } catch (\Throwable $e) {
                    Log::error('SyncBillingInvoicesJob: error syncing user ' . $user->id . ': ' . $e->getMessage(), [
                        'user_id' => $user->id,
                        'exception' => (string) $e,
                    ]);
                }
            }

            Log::info('SyncBillingInvoicesJob: finished full sync for guest_parent users');

        } catch (\Throwable $e) {
            Log::error('SyncBillingInvoicesJob exception: ' . $e->getMessage(), [
                'billingCustomerId' => $this->billingCustomerId,
                'exception' => (string) $e,
            ]);
            // Let job fail and be retried as appropriate
            throw $e;
        }
    }
}
