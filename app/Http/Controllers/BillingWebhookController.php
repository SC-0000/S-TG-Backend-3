<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Support\MailContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Models\Transaction;
use App\Models\Service;
use Carbon\Carbon;

class BillingWebhookController extends Controller
{
    /**
     * Handle billing provider webhooks.
     *
     * Expected payload (example):
     * {
     *    "event": "invoice.paid",
     *    "data": {
     *       "id": "inv_123",
     *       "status": "paid",
     *       ...
     *    }
     * }
     *
     * This endpoint is intentionally permissive about incoming fields —
     * it looks up the matching Transaction by invoice_id and then marks it
     * complete and creates Access rows (re-using the same access rules
     * as CheckoutController).
     */
    public function handleInvoice(Request $request)
    {
        $payload = $request->all();

        Log::info('📨 Billing webhook received', [
            'payload' => $payload,
            'headers' => $request->headers->all(),
            'ip' => $request->ip(),
        ]);

        $event = $payload['event'] ?? $payload['type'] ?? null;
        $data = $payload['data'] ?? ($payload['object'] ?? []);

        $invoiceId = $data['id'] ?? $data['invoice_id'] ?? $request->input('invoice_id');
        
        Log::info('🔍 Webhook: Parsed invoice details', [
            'event' => $event,
            'invoice_id' => $invoiceId,
            'data_keys' => array_keys($data),
        ]);

        // If billing provider included a customer id, prefer to dispatch a background sync job
        $billingCustomerId = $data['customer_id'] ?? $data['customer'] ?? $payload['customer_id'] ?? null;

        if ($billingCustomerId) {
            // Dispatch a job to reconcile invoices for this billing customer.
            if (class_exists(\App\Jobs\SyncBillingInvoicesJob::class)) {
                \App\Jobs\SyncBillingInvoicesJob::dispatch($billingCustomerId);
                Log::info('Billing webhook: dispatched SyncBillingInvoicesJob for customer', [
                    'billing_customer_id' => $billingCustomerId,
                ]);
                // Respond immediately - the job will perform reconciliation and grant access as needed.
                return response()->json(['ok' => true, 'dispatched' => true], 200);
            } else {
                Log::warning('Billing webhook: SyncBillingInvoicesJob not found; falling back to inline handling', [
                    'billing_customer_id' => $billingCustomerId,
                ]);
            }
        }

        if (! $invoiceId) {
            Log::warning('Billing webhook: missing invoice id', ['payload' => $payload]);
            return response()->json(['ok' => false, 'message' => 'missing invoice id'], 400);
        }

        // find transaction for this invoice
        $transaction = Transaction::where('invoice_id', $invoiceId)->first();

        if (! $transaction) {
            Log::warning('⚠️ Webhook: No matching transaction found', [
                'invoice_id' => $invoiceId,
                'event' => $event,
            ]);
            // Not found: respond 200 to avoid repeated attempts (or 404 if you prefer)
            return response()->json(['ok' => true, 'message' => 'no matching transaction'], 200);
        }

        Log::info('✅ Webhook: Transaction found', [
            'transaction_id' => $transaction->id,
            'invoice_id' => $invoiceId,
            'current_status' => $transaction->status,
            'user_id' => $transaction->user_id,
            'user_email' => $transaction->user_email,
        ]);

        $status = strtolower($data['status'] ?? $data['state'] ?? ($payload['status'] ?? 'unknown'));
        
        Log::info('💰 Webhook: Invoice status', [
            'transaction_id' => $transaction->id,
            'invoice_id' => $invoiceId,
            'status' => $status,
            'actionable' => in_array($status, ['paid', 'completed'], true),
        ]);

        // Only act when invoice is paid/completed
        if (in_array($status, ['paid', 'completed'], true)) {
            DB::transaction(function () use ($transaction, $invoiceId) {
                $transaction->status = 'completed';
                $transaction->invoice_id = $invoiceId;
                $transaction->save();
                
                Log::info('✅ Webhook: Transaction status updated to completed', [
                    'transaction_id' => $transaction->id,
                    'invoice_id' => $invoiceId,
                    'previous_status' => 'pending',
                    'new_status' => 'completed',
                ]);
            });

            // Log meta information before dispatching job
            Log::info('🔍 Webhook: Transaction meta before job dispatch', [
                'transaction_id' => $transaction->id,
                'meta' => $transaction->meta,
                'has_serviceChildren' => isset($transaction->meta['serviceChildren']),
                'serviceChildren' => $transaction->meta['serviceChildren'] ?? null,
            ]);

            // Dispatch job to grant access using transaction.meta mappings
            \App\Jobs\GrantAccessForTransactionJob::dispatch($transaction->id);
            
            Log::info('🚀 Webhook: GrantAccessForTransactionJob dispatched', [
                'transaction_id' => $transaction->id,
                'job_class' => \App\Jobs\GrantAccessForTransactionJob::class,
                'next_step' => 'Job will process service items and grant access',
            ]);

            // Dispatch receipt + access-granted emails (queued) with idempotency guards
            try {
                // send receipt if not already sent
                if (empty($transaction->email_sent_receipt)) {
                    $organization = MailContext::resolveOrganization($transaction->organization_id ?? null, $transaction->user ?? null, $transaction);
                    Mail::to($transaction->user_email ?? $transaction->user->email)
                        ->queue(new \App\Mail\ReceiptAccessMail($transaction, 'receipt', $organization));
                    $updated = DB::table('transactions')
                        ->where('id', $transaction->id)
                        ->where('email_sent_receipt', false)
                        ->update(['email_sent_receipt' => true, 'updated_at' => now()]);
                    if ($updated) {
                        $transaction->email_sent_receipt = true;
                    }
                }

                // send access notification if not already sent
                if (empty($transaction->email_sent_access)) {
                    $organization = MailContext::resolveOrganization($transaction->organization_id ?? null, $transaction->user ?? null, $transaction);
                    Mail::to($transaction->user_email ?? $transaction->user->email)
                        ->queue(new \App\Mail\ReceiptAccessMail($transaction, 'access_granted', $organization));
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
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            Log::warning('⚠️ Webhook: Invoice status not actionable', [
                'transaction_id' => $transaction->id,
                'invoice_id' => $invoiceId,
                'status' => $status,
                'expected_statuses' => ['paid', 'completed'],
                'action' => 'No access will be granted yet',
            ]);
        }

        Log::info('✅ Webhook: Processing complete', [
            'transaction_id' => $transaction->id ?? null,
            'invoice_id' => $invoiceId,
            'status' => $status,
        ]);

        return response()->json(['ok' => true]);
    }
}
