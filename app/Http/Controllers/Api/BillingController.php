<?php

namespace App\Http\Controllers\Api;

use App\Services\BillingService;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends ApiController
{
    public function setup(Request $request, BillingService $billing): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        if (! $user->billing_customer_id) {
            $customerId = $billing->createCustomer($user);
            if (! $customerId) {
                return $this->error('Could not create billing customer.', [], 500);
            }
            $user->billing_customer_id = $customerId;
            $user->save();
        }

        $orgId = $request->attributes->get('organization_id') ?? $user->current_organization_id;
        $org = $orgId ? Organization::find($orgId) : null;
        $publishableKey = $org?->getApiKey('billing_publishable') ?? $org?->getApiKey('billing') ?? $org?->getApiKey('stripe') ?? config('services.billing.publishable_key');

        return $this->success([
            'customer_id' => $user->billing_customer_id,
            'api_key' => $publishableKey,
        ]);
    }

    public function invoices(Request $request, BillingService $billing): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        if (! $user->billing_customer_id) {
            return $this->error('No billing customer ID found.', [], 422);
        }

        $customer = $billing->getCustomerById($user->billing_customer_id);
        if (! $customer) {
            return $this->error('Could not fetch invoices.', [], 500);
        }

        $invoices = $customer['data']['invoices'] ?? ($customer['invoices'] ?? []);

        return $this->success([
            'invoices' => $invoices,
        ]);
    }

    public function paymentMethods(Request $request, BillingService $billing): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        if (! $user->billing_customer_id) {
            return $this->error('No billing customer ID found.', [], 422);
        }

        $methods = $billing->getPaymentMethods($user->billing_customer_id);
        if (! $methods || ! isset($methods['data'])) {
            return $this->error('Could not fetch payment methods.', [], 500);
        }

        return $this->success([
            'methods' => $methods['data'],
        ]);
    }

    public function portal(Request $request, BillingService $billing): JsonResponse
    {
        return $this->setup($request, $billing);
    }

    public function downloadInvoice(Request $request, int $transactionId)
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $transaction = \App\Models\Transaction::where('id', $transactionId)
            ->where('user_id', $user->id)
            ->first();

        if (! $transaction) {
            return $this->error('Transaction not found.', [], 404);
        }

        $pdfService = app(\App\Services\InvoicePdfService::class);

        // Check if PDF already exists
        $existingPath = "invoices/transactions/{$transaction->id}.pdf";
        if (! \Illuminate\Support\Facades\Storage::exists($existingPath)) {
            $pdfService->generateForTransaction($transaction);
        }

        return \Illuminate\Support\Facades\Storage::download($existingPath, "invoice-{$transaction->id}.pdf");
    }
}
