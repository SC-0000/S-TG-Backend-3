<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\User;

class BillingService
{
    protected string $base;
    protected string $token;

    public function __construct()
    {
        $this->base  = config('services.billingsystems.base_uri');
        $this->token = config('services.billingsystems.token');
    }

    /**
     * Get customer details by ID from billings.systems.
     */
    public function getCustomerById(string $customerId): ?array
    {
        $response = Http::withToken($this->token)
            ->acceptJson()
            ->get("{$this->base}/customers/{$customerId}");

        Log::info('Billing API getCustomerById response', [
            'status' => $response->status(),
            'body' => $response->body(),
            'json' => $response->json(),
        ]);

        if ($response->successful()) {
            return $response->json();
        }

        Log::warning("Billing getCustomerById failed: " . $response->body());
        return null;
    }


    /**
     * Create a customer in billings.systems and return its ID.
     */
    public function createCustomer(User $user): ?string
    {
        $payload = [
            'name'    => $user->name,
            'email'   => $user->email,
            'phone'   => $user->mobile_number,
            'address' => [
                'line1'       => $user->address_line1,
                'line2'       => $user->address_line2,
                'city'        => $user->city,
                'state'       => $user->state,
                'postal_code' => $user->address_line2,
                'country'     => $user->country,
            ],
            'metadata' => [],
        ];

        // generate a unique idempotency key
        $idemKey = (string) Str::uuid();


        try {
            $response = Http::withToken($this->token)
                ->withHeaders([
                    'Idempotency-Key' => $idemKey,
                ])
                ->acceptJson()
                ->post("{$this->base}/customers", $payload);
        } catch (\Throwable $e) {
            Log::error('Billing createCustomer exception', [
                'message' => $e->getMessage(),
            ]);
            return null;
        }

        Log::info('Billing API createCustomer response', [
            'status' => $response->status(),
            'body' => $response->body(),
            'json' => $response->json(),
        ]);

        if ($response->successful() && isset($response['data']['id'])) {
            return $response['data']['id'];
        }

        Log::warning("Billing create failed (Idempotency-Key: $idemKey): " . $response->body());
        return null;
    }

    /**
     * Enable autopay for a given invoice.
     */
    public function enableAutopay(string $invoiceId): array
    {
        $idemKey = (string) Str::uuid();

        $response = Http::withToken($this->token)
            ->withHeaders([
                'Idempotency-Key' => $idemKey,
            ])
            ->acceptJson()
            ->post("{$this->base}/invoices/{$invoiceId}/pay-with-default");

        Log::info('Billing API enableAutopay response', [
            'status' => $response->status(),
            'body' => $response->body(),
            'json' => $response->json(),
        ]);

        return [
            'success' => $response->successful(),
            'status' => $response->status(),
            'body' => $response->body(),
            'json' => $response->json(),
        ];
    }

    /**
     * Update an invoice in the billing provider.
     *
     * Returns the decoded response data on success, or null on failure.
     */
    public function updateInvoice(string $invoiceId, array $payload): ?array
    {
        try {
            $idem = (string) Str::uuid();
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'Idempotency-Key' => $idem,
                ])
                ->patch("{$this->base}/invoices/{$invoiceId}", $payload);

            Log::info('BillingService updateInvoice response', [
                'invoiceId' => $invoiceId,
                'status'    => $response->status(),
                'body'      => $response->body(),
                'json'      => $response->json(),
            ]);

            if ($response->successful()) {
                return $response->json('data') ?? $response->json();
            }

            Log::warning('BillingService updateInvoice failed', [
                'invoiceId' => $invoiceId,
                'status'    => $response->status(),
                'body'      => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Exception in BillingService updateInvoice: '.$e->getMessage(), [
                'invoiceId' => $invoiceId,
            ]);
        }

        return null;
    }

    /** Convenience wrapper to update only status */
    public function updateInvoiceStatus(string $invoiceId, string $status): ?array
    {
        return $this->updateInvoice($invoiceId, ['status' => $status]);
    }

    public function createInvoice(array $data): ?string
    {
        // generate idempotency key so you don’t accidentally double-create the same invoice
        $idem = (string) Str::uuid();

        try {
            $resp = Http::withToken($this->token)
                ->withHeaders(['Idempotency-Key' => $idem])
                ->acceptJson()
                ->post("{$this->base}/invoices", $data);
        } catch (\Throwable $e) {
            Log::error('Billing createInvoice exception', [
                'message' => $e->getMessage(),
            ]);
            return null;
        }

        if ($resp->successful() && isset($resp['data']['id'])) {
            return $resp['data']['id'];
        }

        Log::warning("Billing createInvoice failed (Idempotency-Key: $idem): " . $resp->body());
        return null;
    }

    /**
     * Get payment methods for a customer from billings.systems.
     */
    public function getPaymentMethods(string $customerId): ?array
    {
        $response = Http::withToken($this->token)
            ->acceptJson()
            ->get("{$this->base}/customers/{$customerId}/payment-methods");

        Log::info('Billing API getPaymentMethods response', [
            'status' => $response->status(),
            'body' => $response->body(),
            'json' => $response->json(),
        ]);

        if ($response->successful()) {
            return $response->json();
        }

        Log::warning("Billing getPaymentMethods failed: " . $response->body());
        return null;
    }

    /**
     * Get all subscriptions from billings.systems.
     */
    public function getSubscriptions(): ?array
    {
        $response = Http::withToken($this->token)
            ->acceptJson()
            ->get("{$this->base}/subscriptions");

        Log::info('Billing API getSubscriptions response', [
            'status' => $response->status(),
            'body' => $response->body(),
            'json' => $response->json(),
        ]);

        if ($response->successful()) {
            return $response->json();
        }

        Log::warning("Billing getSubscriptions failed: " . $response->body());
        return null;
    }

    /**
     * Poll billing service for invoices belonging to a billing customer and update
     * local Transaction records to reflect paid/unpaid state.
     *
     * For each invoice returned by the billing provider:
     *  - find a local Transaction with invoice_id == invoice.id
     *  - if invoice.status === 'paid' and transaction isn't marked paid, mark paid, set paid_at and dispatch access job
     *  - if invoice.status !== 'paid' and transaction was marked paid, move back to pending/refunded as appropriate
     *
     * This method is safe to call from a scheduled job or webhook handler.
     */
    public function updateTransactionStatusesFromBilling(string $billingCustomerId): void
    {
        try {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->get("{$this->base}/customers/{$billingCustomerId}");

            Log::info('BillingService.updateTransactionStatusesFromBilling response', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 2000), // limit size
            ]);

            if (! $response->successful()) {
                Log::warning("BillingService: failed to fetch customer data for {$billingCustomerId}");
                return;
            }

            $decoded = $response->json();

            // Guard: ensure we have invoices array at expected location
            $invoices = $decoded['data']['invoices'] ?? ($decoded['invoices'] ?? null);
            if (empty($invoices) || ! is_array($invoices)) {
                Log::info("BillingService: no invoices found for billingCustomerId: {$billingCustomerId}");
                return;
            }

            // Defer importing heavy models to runtime to avoid circular deps
            $transactionModel = app(\App\Models\Transaction::class);

            foreach ($invoices as $invoice) {
                $invoiceId = $invoice['id'] ?? null;
                $status = strtolower($invoice['status'] ?? '');
                if (! $invoiceId) {
                    continue;
                }

                // Find local transaction by invoice_id
                $tx = $transactionModel->where('invoice_id', $invoiceId)->first();
                if (! $tx) {
                    // No local transaction to sync
                    continue;
                }

                $wasPaid = in_array($tx->status, ['paid', 'completed'], true);
                $isPaid = ($status === 'paid');
                // Log::info('ispaid', ['isPaid' => $isPaid]);
                if ($isPaid && ! $wasPaid) {
                    // Mark transaction as paid and queue access granting
                    $tx->status = 'paid';
                    $tx->paid_at = now();
                    $tx->save();

                    Log::info('BillingService: marking transaction paid and dispatching access job', [
                        'transaction_id' => $tx->id,
                        'invoice_id' => $invoiceId,
                    ]);

                    // Dispatch background job to grant access (idempotent job)
                    if (class_exists(\App\Jobs\GrantAccessForTransactionJob::class)) {
                        \App\Jobs\GrantAccessForTransactionJob::dispatch($tx->id);
                    } else {
                        Log::warning('GrantAccessForTransactionJob class not found; skipping dispatch');
                    }
                } elseif (! $isPaid && $wasPaid) {
                    // Invoice appears unpaid or refunded — move transaction back to pending or refunded
                    $prev = $tx->status;
                    $tx->status = $status === 'refunded' ? 'refunded' : 'pending';
                    $tx->save();
                    Log::info('BillingService: updated transaction status from billing (downgrade)', [
                        'transaction_id' => $tx->id,
                        'invoice_id' => $invoiceId,
                        'from' => $prev,
                        'to' => $tx->status,
                    ]);
                } else {
                    Log::debug('BillingService: no status change for transaction', [
                        'transaction_id' => $tx->id,
                        'invoice_id' => $invoiceId,
                        'status' => $tx->status,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('BillingService.updateTransactionStatusesFromBilling exception: ' . $e->getMessage(), [
                'billingCustomerId' => $billingCustomerId,
                'exception' => (string) $e,
            ]);
        }
    }
}
