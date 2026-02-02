<?php

namespace App\Http\Controllers\Api;

use App\Services\BillingService;
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

        return $this->success([
            'customer_id' => $user->billing_customer_id,
            'api_key' => config('services.billing.publishable_key'),
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
}
