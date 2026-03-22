<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\Organization;
use App\Models\Transaction;

class BillingService
{
    protected string $base;
    protected string $token;
    protected ?Organization $resolvedOrg = null;

    public function __construct()
    {
        $base = rtrim(config('services.billingsystems.base_uri'), '/');
        // Ensure base URL includes /api/v1 path
        if (! str_contains($base, '/api')) {
            $base .= '/api/v1';
        }
        $this->base  = $base;
        $this->token = config('services.billingsystems.token');

        $orgId = request()?->attributes?->get('organization_id');
        if ($orgId) {
            $org = Organization::find($orgId);
            if ($org) {
                $this->resolvedOrg = $org;
                $billingKey = $org->getApiKey('billing') ?? $org->getApiKey('stripe');
                if ($billingKey) {
                    $this->token = $billingKey;
                }
            }
        }
    }

    /**
     * Get the org-specific webhook secret, falling back to the platform .env value.
     */
    public function getWebhookSecret(): string
    {
        if ($this->resolvedOrg) {
            $orgSecret = $this->resolvedOrg->getApiKey('billing_webhook');
            if ($orgSecret) {
                return $orgSecret;
            }
        }

        return config('services.billingsystems.webhook_secret', '');
    }

    /**
     * Get the publishable key for frontend widgets.
     * Uses org-specific key if available, otherwise falls back to .env.
     */
    public function getPublishableKey(): string
    {
        if ($this->resolvedOrg) {
            $orgKey = $this->resolvedOrg->getApiKey('billing_publishable');
            if ($orgKey) {
                return $orgKey;
            }
        }

        return config('services.billing.publishable_key', '');
    }

    /**
     * Quick connectivity check against the billing API.
     * Returns true if reachable, false otherwise.
     */
    public function ping(): bool
    {
        try {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->timeout(5)
                ->get("{$this->base}/customers", ['limit' => 1]);

            return $response->successful();
        } catch (\Throwable $e) {
            return false;
        }
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
            'name'         => $user->name,
            'email'        => $user->email,
            'phone'        => $user->mobile_number,
            'external_ref' => 'user_' . $user->id,
            'address'      => [
                'line1'       => $user->address_line1,
                'line2'       => $user->address_line2,
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
     * Update a user's billing customer in I-BLS-2 (name, email, phone, address).
     */
    public function updateCustomer(User $user): bool
    {
        if (! $user->billing_customer_id) {
            return false;
        }

        $payload = [
            'name'  => $user->name,
            'email' => $user->email,
            'phone' => $user->mobile_number,
        ];

        try {
            $response = Http::withToken($this->token)
                ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
                ->acceptJson()
                ->put("{$this->base}/customers/{$user->billing_customer_id}", $payload);

            if ($response->successful()) {
                return true;
            }

            Log::warning('BillingService updateCustomer failed', [
                'customer_id' => $user->billing_customer_id,
                'status'      => $response->status(),
            ]);
        } catch (\Throwable $e) {
            Log::error('BillingService updateCustomer exception: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Update an organization's billing customer in I-BLS-2.
     */
    public function updateOrganizationCustomer(Organization $org): bool
    {
        if (! $org->billing_customer_id) {
            return false;
        }

        $platformToken = config('services.billingsystems.token');
        $payload = [
            'name'  => $org->getSetting('branding.organization_name', $org->name),
            'email' => $org->getSetting('contact.email'),
        ];

        try {
            $response = Http::withToken($platformToken)
                ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
                ->acceptJson()
                ->put("{$this->base}/customers/{$org->billing_customer_id}", $payload);

            if ($response->successful()) {
                return true;
            }

            Log::warning('BillingService updateOrganizationCustomer failed', [
                'org_id'      => $org->id,
                'customer_id' => $org->billing_customer_id,
                'status'      => $response->status(),
            ]);
        } catch (\Throwable $e) {
            Log::error('BillingService updateOrganizationCustomer exception: ' . $e->getMessage());
        }

        return false;
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
     * Get invoices using the client-scoped token (the org's own token).
     * This fetches invoices that belong to this client account in I-BLS-2.
     */
    public function getClientInvoices(array $filters = []): ?array
    {
        try {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->get("{$this->base}/invoices", $filters);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('BillingService getClientInvoices failed', [
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 500),
            ]);
        } catch (\Throwable $e) {
            Log::error('BillingService getClientInvoices exception: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Get a single invoice using the client-scoped token.
     */
    public function getClientInvoice(string $invoiceId): ?array
    {
        try {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->get("{$this->base}/invoices/{$invoiceId}");

            if ($response->successful()) {
                return $response->json('data') ?? $response->json();
            }
        } catch (\Throwable $e) {
            Log::error('BillingService getClientInvoice exception: ' . $e->getMessage());
        }

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
     * Process a refund via I-BLS-2.
     *
     * @param string   $transactionId  The I-BLS-2 transaction UUID (from invoice payment)
     * @param int|null $amountCents    Amount in cents (null = full refund)
     * @param string   $reason         Reason for refund
     */
    public function processRefund(string $transactionId, ?int $amountCents = null, string $reason = ''): ?array
    {
        $idem = (string) Str::uuid();

        $payload = ['transaction_id' => $transactionId];
        if ($amountCents !== null) {
            $payload['amount'] = $amountCents;
        }

        try {
            $response = Http::withToken($this->token)
                ->withHeaders(['Idempotency-Key' => $idem])
                ->acceptJson()
                ->post("{$this->base}/transactions/refund", $payload);
        } catch (\Throwable $e) {
            Log::error('BillingService processRefund exception', [
                'message' => $e->getMessage(),
            ]);
            return null;
        }

        Log::info('BillingService processRefund response', [
            'transactionId' => $transactionId,
            'status'        => $response->status(),
            'body'          => $response->body(),
        ]);

        if ($response->successful()) {
            return $response->json('data') ?? $response->json();
        }

        Log::warning("BillingService processRefund failed (Idempotency-Key: {$idem}): " . $response->body());
        return null;
    }

    /**
     * Create a billing customer for an Organization (admin→org billing).
     * Uses the platform token, not the org's own key.
     * The customer represents the organization — uses org name and org contact email.
     * Falls back to the currently authenticated user's email (the admin performing setup).
     */
    public function createOrganizationCustomer(Organization $org): ?string
    {
        $idem = (string) Str::uuid();

        // Use platform token explicitly (not org-specific override)
        $platformToken = config('services.billingsystems.token');

        // Resolve email: org contact email > logged-in user's email > org owner email
        $email = $org->getSetting('contact.email');
        if (! $email && auth()->check()) {
            $email = auth()->user()->email;
        }
        if (! $email) {
            $email = $org->owner?->email;
        }

        $payload = [
            'name'         => $org->getSetting('branding.organization_name', $org->name),
            'email'        => $email,
            'external_ref' => 'org_' . $org->id,
        ];

        try {
            $response = Http::withToken($platformToken)
                ->withHeaders(['Idempotency-Key' => $idem])
                ->acceptJson()
                ->post("{$this->base}/customers", $payload);
        } catch (\Throwable $e) {
            Log::error('BillingService createOrganizationCustomer exception', [
                'message' => $e->getMessage(),
            ]);
            return null;
        }

        if ($response->successful() && isset($response['data']['id'])) {
            return $response['data']['id'];
        }

        Log::warning("BillingService createOrganizationCustomer failed: " . $response->body());
        return null;
    }

    /**
     * Generate a shareable payment link for an invoice.
     */
    public function getPaymentLink(string $invoiceId): string
    {
        return rtrim($this->base, '/api/v1') . "/pay/{$invoiceId}";
    }

    /**
     * Finalize an invoice (draft → open) in I-BLS-2.
     */
    public function finalizeInvoice(string $invoiceId): ?array
    {
        $idem = (string) Str::uuid();

        try {
            $response = Http::withToken($this->token)
                ->withHeaders(['Idempotency-Key' => $idem])
                ->acceptJson()
                ->post("{$this->base}/invoices/{$invoiceId}/finalize");
        } catch (\Throwable $e) {
            Log::error('BillingService finalizeInvoice exception', ['message' => $e->getMessage()]);
            return null;
        }

        if ($response->successful()) {
            return $response->json('data') ?? $response->json();
        }

        Log::warning("BillingService finalizeInvoice failed: " . $response->body());
        return null;
    }

    /**
     * Void an invoice in I-BLS-2.
     */
    public function voidInvoice(string $invoiceId): ?array
    {
        $idem = (string) Str::uuid();

        try {
            $response = Http::withToken($this->token)
                ->withHeaders(['Idempotency-Key' => $idem])
                ->acceptJson()
                ->post("{$this->base}/invoices/{$invoiceId}/void");
        } catch (\Throwable $e) {
            Log::error('BillingService voidInvoice exception', ['message' => $e->getMessage()]);
            return null;
        }

        if ($response->successful()) {
            return $response->json('data') ?? $response->json();
        }

        Log::warning("BillingService voidInvoice failed: " . $response->body());
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

                $wasPaid = in_array($tx->status, Transaction::PAID_STATUSES, true);
                $isPaid = ($status === 'paid');
                if ($isPaid && ! $wasPaid) {
                    // Mark transaction as paid and queue access granting
                    $tx->status = Transaction::STATUS_PAID;
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
                    $tx->status = $status === 'refunded' ? Transaction::STATUS_REFUNDED : Transaction::STATUS_PENDING;
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

    /**
     * Get the admin token for I-BLS-2 admin API endpoints.
     * Uses org-specific admin token if available, otherwise platform .env token.
     */
    public function getAdminToken(): ?string
    {
        if ($this->resolvedOrg) {
            $orgAdminToken = $this->resolvedOrg->getApiKey('billing_admin');
            if ($orgAdminToken) {
                return $orgAdminToken;
            }
        }

        return config('services.billingsystems.admin_token');
    }

    /**
     * Fetch invoices from I-BLS-2 admin API (cross-client visibility).
     * Uses admin-scoped token with admin:invoices:read scope.
     */
    public function getAdminInvoices(array $filters = []): ?array
    {
        $adminToken = $this->getAdminToken();
        if (! $adminToken) {
            Log::warning('BillingService: admin token not configured');
            return null;
        }

        try {
            $response = Http::withToken($adminToken)
                ->acceptJson()
                ->get("{$this->base}/admin/invoices", $filters);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('BillingService getAdminInvoices failed', [
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 500),
            ]);
        } catch (\Throwable $e) {
            Log::error('BillingService getAdminInvoices exception: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Fetch a single invoice from I-BLS-2 admin API.
     */
    public function getAdminInvoice(string $invoiceId): ?array
    {
        $adminToken = $this->getAdminToken();
        if (! $adminToken) {
            return null;
        }

        try {
            $response = Http::withToken($adminToken)
                ->acceptJson()
                ->get("{$this->base}/admin/invoices/{$invoiceId}");

            if ($response->successful()) {
                return $response->json('data') ?? $response->json();
            }

            Log::warning('BillingService getAdminInvoice failed', [
                'invoiceId' => $invoiceId,
                'status'    => $response->status(),
            ]);
        } catch (\Throwable $e) {
            Log::error('BillingService getAdminInvoice exception: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Fetch clients from I-BLS-2 admin API.
     */
    public function getAdminClients(): ?array
    {
        $adminToken = $this->getAdminToken();
        if (! $adminToken) {
            return null;
        }

        try {
            $response = Http::withToken($adminToken)
                ->acceptJson()
                ->get("{$this->base}/admin/clients");

            if ($response->successful()) {
                return $response->json();
            }
        } catch (\Throwable $e) {
            Log::error('BillingService getAdminClients exception: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Sync OrganizationInvoice statuses from I-BLS-2.
     *
     * For each org with a billing_customer_id and unpaid org invoices,
     * fetch invoice status from billing provider and update local records.
     */
    public function syncOrganizationInvoiceStatuses(): void
    {
        $orgInvoices = \App\Models\OrganizationInvoice::whereNotNull('billing_invoice_id')
            ->whereIn('status', ['draft', 'pending', 'overdue'])
            ->with('organization')
            ->get();

        foreach ($orgInvoices as $orgInvoice) {
            try {
                $org = $orgInvoice->organization;
                if (! $org || ! $org->billing_customer_id) {
                    continue;
                }

                // Use platform token for org billing check
                $platformToken = config('services.billingsystems.token');
                $response = \Illuminate\Support\Facades\Http::withToken($platformToken)
                    ->acceptJson()
                    ->get("{$this->base}/customers/{$org->billing_customer_id}");

                if (! $response->successful()) {
                    continue;
                }

                $invoices = $response->json('data.invoices') ?? $response->json('invoices') ?? [];
                $billingInvoice = collect($invoices)->firstWhere('id', $orgInvoice->billing_invoice_id);

                if (! $billingInvoice) {
                    continue;
                }

                $billingStatus = strtolower($billingInvoice['status'] ?? '');

                if ($billingStatus === 'paid' && ! $orgInvoice->isPaid()) {
                    $orgInvoice->markAsPaid();
                    Log::info('BillingService: org invoice synced to paid', [
                        'org_invoice_id' => $orgInvoice->id,
                        'billing_invoice_id' => $orgInvoice->billing_invoice_id,
                    ]);
                } elseif ($billingStatus === 'void' && $orgInvoice->status !== 'void') {
                    $orgInvoice->update(['status' => 'void']);
                }
            } catch (\Throwable $e) {
                Log::error('BillingService: org invoice sync error', [
                    'org_invoice_id' => $orgInvoice->id,
                    'error'          => $e->getMessage(),
                ]);
            }
        }
    }
}
