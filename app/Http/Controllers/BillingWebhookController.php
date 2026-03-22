<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Models\Transaction;
use App\Models\TransactionLog;
use App\Models\Refund;
use App\Models\PaymentFollowup;
use App\Models\OrganizationInvoice;
use App\Support\MailContext;
use Carbon\Carbon;

class BillingWebhookController extends Controller
{
    /**
     * Handle all billing provider webhook events.
     *
     * Always returns 200 to prevent retries (per I-BLS-2 spec).
     * Signature verification is handled by VerifyBillingWebhookSignature middleware.
     */
    public function handleInvoice(Request $request): JsonResponse
    {
        $payload = $request->all();

        Log::info('Billing webhook received', [
            'event'   => $payload['type'] ?? $payload['event'] ?? 'unknown',
            'headers' => [
                'delivery' => $request->header('X-Billings-Delivery'),
                'event'    => $request->header('X-Billings-Event'),
            ],
        ]);

        // Deduplication: check delivery ID
        $deliveryId = $request->header('X-Billings-Delivery');
        if ($deliveryId && $this->isAlreadyProcessed($deliveryId)) {
            Log::info('Billing webhook: duplicate delivery skipped', ['delivery_id' => $deliveryId]);
            return response()->json(['ok' => true, 'message' => 'already_processed']);
        }

        $this->sourceIp = $request->ip();
        $event = $payload['type'] ?? $payload['event'] ?? null;
        $data  = $payload['data'] ?? $payload['object'] ?? [];

        // Route to appropriate handler
        $result = match ($event) {
            'payment.succeeded', 'invoice.paid'   => $this->handlePaymentSucceeded($data, $deliveryId),
            'payment.failed'                       => $this->handlePaymentFailed($data, $deliveryId),
            'invoice.voided'                       => $this->handleInvoiceVoided($data, $deliveryId),
            'invoice.created'                      => $this->handleInvoiceCreated($data, $deliveryId),
            'refund.processed'                     => $this->handleRefundProcessed($data, $deliveryId),
            'dispute.created'                      => $this->handleDisputeCreated($data, $deliveryId),
            'dispute.closed'                       => $this->handleDisputeClosed($data, $deliveryId),
            'subscription.created'                 => $this->handleSubscriptionCreated($data, $deliveryId),
            'subscription.cancelled'               => $this->handleSubscriptionCancelled($data, $deliveryId),
            'payment_method.attached'              => $this->logEvent('payment_method.attached', $data, $deliveryId),
            'payout.completed'                     => $this->logEvent('payout.completed', $data, $deliveryId),
            'customer.created'                     => $this->logEvent('customer.created', $data, $deliveryId),
            default                                => $this->logEvent($event ?? 'unknown', $data, $deliveryId, null, null, 'warning'),
        };

        return response()->json(['ok' => true, 'result' => $result]);
    }

    private ?string $sourceIp = null;

    /* ─────────────────────────────────────────────────────────────────────────
     * Event Handlers
     * ───────────────────────────────────────────────────────────────────────── */

    /**
     * Payment succeeded / Invoice paid — mark transaction paid, grant access, send emails.
     */
    private function handlePaymentSucceeded(array $data, ?string $deliveryId): string
    {
        // If billing provider included a customer id, prefer background sync
        $billingCustomerId = $data['customer_id'] ?? $data['customer'] ?? null;
        if ($billingCustomerId && class_exists(\App\Jobs\SyncBillingInvoicesJob::class)) {
            \App\Jobs\SyncBillingInvoicesJob::dispatch($billingCustomerId);
            $this->logEvent('payment.succeeded', $data, $deliveryId, null, 'Dispatched SyncBillingInvoicesJob');
        }

        $transaction = $this->findTransaction($data);
        if (! $transaction) {
            // Check if this is an organization invoice
            $this->handleOrgInvoicePaid($data, $deliveryId);
            return 'no_matching_transaction';
        }

        DB::transaction(function () use ($transaction) {
            $transaction->status  = Transaction::STATUS_PAID;
            $transaction->paid_at = now();
            $transaction->save();
        });

        // Resolve any active payment followup
        $followup = $transaction->paymentFollowup;
        if ($followup && $followup->status === PaymentFollowup::STATUS_ACTIVE) {
            $followup->resolve();
        }

        // Dispatch access granting job
        \App\Jobs\GrantAccessForTransactionJob::dispatch($transaction->id);

        // Send receipt + access emails (idempotent)
        $this->sendTransactionEmails($transaction);

        $this->logEvent('payment.succeeded', $data, $deliveryId, $transaction->id, 'Transaction marked paid, access job dispatched');
        return 'processed';
    }

    /**
     * Payment failed — mark transaction failed, create followup, notify user.
     */
    private function handlePaymentFailed(array $data, ?string $deliveryId): string
    {
        $transaction = $this->findTransaction($data);
        if (! $transaction) {
            $this->logEvent('payment.failed', $data, $deliveryId, null, 'No matching transaction', 'error');
            return 'no_matching_transaction';
        }

        $transaction->update(['status' => Transaction::STATUS_FAILED]);

        // Create payment followup if none exists
        if (! $transaction->paymentFollowup) {
            PaymentFollowup::create([
                'organization_id'  => $transaction->organization_id,
                'transaction_id'   => $transaction->id,
                'user_id'          => $transaction->user_id,
                'followup_stage'   => PaymentFollowup::STAGE_GENTLE,
                'last_followup_at' => now(),
                'next_followup_at' => now()->addDays(PaymentFollowup::STAGE_SCHEDULE[PaymentFollowup::STAGE_GENTLE]),
                'status'           => PaymentFollowup::STATUS_ACTIVE,
                'notes'            => [[
                    'message'   => 'Payment failed via webhook',
                    'stage'     => PaymentFollowup::STAGE_GENTLE,
                    'timestamp' => now()->toISOString(),
                    'failure_code'    => $data['failure_code'] ?? null,
                    'failure_message' => $data['failure_message'] ?? null,
                ]],
            ]);
        }

        // Send failure notification
        try {
            $email = $transaction->user_email ?? $transaction->user?->email;
            if ($email) {
                $organization = MailContext::resolveOrganization(
                    $transaction->organization_id,
                    $transaction->user,
                    $transaction
                );
                Mail::to($email)->queue(
                    new \App\Mail\PaymentFailedNotification($transaction, $data, $organization)
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Billing webhook: failed to queue payment failed email', [
                'transaction_id' => $transaction->id,
                'error'          => $e->getMessage(),
            ]);
        }

        $this->logEvent('payment.failed', $data, $deliveryId, $transaction->id, 'Transaction marked failed, followup created', 'error');
        return 'processed';
    }

    /**
     * Invoice voided — set transaction to void.
     */
    private function handleInvoiceVoided(array $data, ?string $deliveryId): string
    {
        $transaction = $this->findTransaction($data);
        if (! $transaction) {
            // Check org invoices
            $invoiceId = $data['id'] ?? $data['invoice_id'] ?? null;
            if ($invoiceId) {
                $orgInvoice = OrganizationInvoice::where('billing_invoice_id', $invoiceId)->first();
                if ($orgInvoice) {
                    $orgInvoice->update(['status' => 'void']);
                    $this->logEvent('invoice.voided', $data, $deliveryId, null, "Org invoice {$orgInvoice->id} voided");
                    return 'org_invoice_voided';
                }
            }
            $this->logEvent('invoice.voided', $data, $deliveryId, null, 'No matching transaction or org invoice', 'warning');
            return 'no_matching_transaction';
        }

        $transaction->update(['status' => Transaction::STATUS_VOID]);
        $this->logEvent('invoice.voided', $data, $deliveryId, $transaction->id, 'Transaction voided');
        return 'processed';
    }

    /**
     * Invoice created — informational log only.
     */
    private function handleInvoiceCreated(array $data, ?string $deliveryId): string
    {
        $this->logEvent('invoice.created', $data, $deliveryId);
        return 'logged';
    }

    /**
     * Refund processed — create local Refund record, update transaction status.
     */
    private function handleRefundProcessed(array $data, ?string $deliveryId): string
    {
        $transaction = $this->findTransaction($data);
        if (! $transaction) {
            $this->logEvent('refund.processed', $data, $deliveryId, null, 'No matching transaction');
            return 'no_matching_transaction';
        }

        $refundAmount = $data['amount'] ?? $transaction->total;

        Refund::create([
            'transaction_id'   => $transaction->id,
            'user_id'          => $transaction->user_id,
            'amount_refunded'  => $refundAmount / 100, // cents to decimal
            'refund_reason'    => $data['reason'] ?? 'Refund processed via billing provider',
            'status'           => 'completed',
            'billing_refund_id' => $data['id'] ?? null,
        ]);

        // Determine if full or partial refund
        $totalRefunded = $transaction->refunds()->sum('amount_refunded');
        if ($totalRefunded >= $transaction->total) {
            $transaction->update(['status' => Transaction::STATUS_REFUNDED]);
        }

        $this->logEvent('refund.processed', $data, $deliveryId, $transaction->id, "Refund of {$refundAmount} cents recorded");
        return 'processed';
    }

    /**
     * Dispute created — log and flag transaction.
     */
    private function handleDisputeCreated(array $data, ?string $deliveryId): string
    {
        $this->logEvent('dispute.created', $data, $deliveryId, null, 'Dispute opened: ' . ($data['reason'] ?? 'unknown'), 'warning');
        return 'logged';
    }

    /**
     * Dispute closed — update dispute status in logs.
     */
    private function handleDisputeClosed(array $data, ?string $deliveryId): string
    {
        $this->logEvent('dispute.closed', $data, $deliveryId, null, 'Dispute closed: ' . ($data['status'] ?? 'unknown'));
        return 'logged';
    }

    /**
     * Subscription created — log the event.
     */
    private function handleSubscriptionCreated(array $data, ?string $deliveryId): string
    {
        $this->logEvent('subscription.created', $data, $deliveryId, null, 'Subscription created: ' . ($data['name'] ?? $data['id'] ?? 'unknown'));
        return 'logged';
    }

    /**
     * Subscription cancelled — log the event.
     */
    private function handleSubscriptionCancelled(array $data, ?string $deliveryId): string
    {
        $this->logEvent('subscription.cancelled', $data, $deliveryId, null, 'Subscription cancelled: ' . ($data['name'] ?? $data['id'] ?? 'unknown'));
        return 'logged';
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Organization Invoice Handling
     * ───────────────────────────────────────────────────────────────────────── */

    private function handleOrgInvoicePaid(array $data, ?string $deliveryId): void
    {
        $invoiceId = $data['id'] ?? $data['invoice_id'] ?? null;
        if (! $invoiceId) {
            return;
        }

        $orgInvoice = OrganizationInvoice::where('billing_invoice_id', $invoiceId)->first();
        if (! $orgInvoice) {
            return;
        }

        $orgInvoice->update([
            'status'  => 'paid',
            'paid_at' => now(),
        ]);

        $this->logEvent('invoice.paid', $data, $deliveryId, null, "Org invoice {$orgInvoice->id} marked paid");
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Helpers
     * ───────────────────────────────────────────────────────────────────────── */

    /**
     * Find a Transaction by invoice_id from the webhook data.
     */
    private function findTransaction(array $data): ?Transaction
    {
        $invoiceId = $data['invoice_id'] ?? $data['id'] ?? null;
        if (! $invoiceId) {
            return null;
        }

        return Transaction::where('invoice_id', $invoiceId)->first();
    }

    /**
     * Check if a webhook delivery has already been processed.
     */
    private function isAlreadyProcessed(string $deliveryId): bool
    {
        return TransactionLog::where('webhook_delivery_id', $deliveryId)->exists();
    }

    /**
     * Log a webhook event to transaction_logs for audit trail.
     */
    private function logEvent(
        string $event,
        array $data,
        ?string $deliveryId,
        ?int $transactionId = null,
        ?string $message = null,
        string $logType = 'info'
    ): string {
        try {
            TransactionLog::create([
                'transaction_id'      => $transactionId,
                'log_message'         => $message ?? "Webhook event: {$event}",
                'log_type'            => $logType,
                'webhook_delivery_id' => $deliveryId,
                'event_type'          => $event,
                'payload'             => $data,
                'source_ip'           => $this->sourceIp,
            ]);
        } catch (\Throwable $e) {
            // If logging fails (e.g. duplicate delivery_id), don't break the response
            Log::warning('Billing webhook: failed to create transaction log', [
                'error'       => $e->getMessage(),
                'delivery_id' => $deliveryId,
            ]);
        }

        return 'logged';
    }

    /**
     * Send receipt and access-granted emails with idempotency guards.
     */
    private function sendTransactionEmails(Transaction $transaction): void
    {
        try {
            $email = $transaction->user_email ?? $transaction->user?->email;
            if (! $email) {
                return;
            }

            $organization = MailContext::resolveOrganization(
                $transaction->organization_id,
                $transaction->user,
                $transaction
            );

            // Send receipt if not already sent
            if (empty($transaction->email_sent_receipt)) {
                Mail::to($email)->queue(
                    new \App\Mail\ReceiptAccessMail($transaction, 'receipt', $organization)
                );
                $updated = DB::table('transactions')
                    ->where('id', $transaction->id)
                    ->where('email_sent_receipt', false)
                    ->update(['email_sent_receipt' => true, 'updated_at' => now()]);
                if ($updated) {
                    $transaction->email_sent_receipt = true;
                }
            }

            // Send access notification if not already sent
            if (empty($transaction->email_sent_access)) {
                Mail::to($email)->queue(
                    new \App\Mail\ReceiptAccessMail($transaction, 'access_granted', $organization)
                );
                $updated = DB::table('transactions')
                    ->where('id', $transaction->id)
                    ->where('email_sent_access', false)
                    ->update(['email_sent_access' => true, 'updated_at' => now()]);
                if ($updated) {
                    $transaction->email_sent_access = true;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Billing webhook: failed to queue receipt/access emails', [
                'transaction_id' => $transaction->id,
                'error'          => $e->getMessage(),
            ]);
        }
    }
}
