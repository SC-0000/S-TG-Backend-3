<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

use App\Services\BillingService;

class BillingController extends Controller
{
    protected string $apiKey;
    protected BillingService $billingService;
    
    public function __construct(BillingService $billingService)
    {  
        // pull your publishable key from config/services.php
        $this->apiKey = config('services.billing.publishable_key');
        $this->billingService = $billingService;
    }

    /**
     * Show the Setup Payment Method page.
     */
    public function setup()
    {
        $user = Auth::user();
        Log::info('BillingController setup method called', [
            'user_id' => $user->id,
            'billing_customer_id' => $user->billing_customer_id,
        ]);
        return Inertia::render('@public/Billing/Setup', [
            'customerId' => $user->billing_customer_id,
            'apiKey'     => $this->apiKey,
        ]);
    }

    /**
     * Show the one-time Payment page for a given invoice.
     */
    public function pay(string $invoiceId)
    {
        $user = Auth::user();

        return Inertia::render('@public/Billing/PaymentPage', [
            'customerId' => $user->billing_customer_id,
            // 'apiKey'     => $this->apiKey,
            // 'invoiceId'  => $invoiceId,
        ]);
    }

    /**
     * Show the Create Invoice widget (admin only).
     */
    public function createInvoice()
    {
        // you can add an admin middleware on the route if desired
        return Inertia::render('@public/Billing/CreateInvoicePage', [
            'apiKey' => $this->apiKey,
        ]);
    }

    /**
     * Show the Subscription Plans page.
     */
    public function subscriptions()
    {
        $user = Auth::user();

        return Inertia::render('@public/Billing/SubscriptionPlansPage', [
            'customerId' => $user->billing_customer_id,
            'apiKey'     => $this->apiKey,
        ]);
    }

    /**
     * Show a receipt for a given invoice.
     */
    public function receipt(string $invoiceId)
    {
        $user = Auth::user();

        return Inertia::render('@public/Billing/ReceiptPage', [
            'customerId' => $user->billing_customer_id,
            // 'apiKey'     => $this->apiKey,
            'invoiceId'  => $invoiceId,
        ]);
    }

    /**
     * Show the Customer Portal (cards, invoices, subscriptions).
     */
    public function portal()
    {
        $user = Auth::user();

        return Inertia::render('@public/Billing/CustomerPortalPage', [
            'customerId' => $user->billing_customer_id,
            'apiKey'     => $this->apiKey,
        ]);
    }

    /**
     * API: Check if the user has an active payment method set up.
     */
    public function checkPaymentMethodSetup(Request $request)
    {
        $user = Auth::user();
        $customerId = $user->billing_customer_id;

        if (!$customerId) {
            return response()->json([
                'success' => false,
                'active' => false,
                'message' => 'No billing customer ID found.'
            ], 400);
        }

        $result = $this->billingService->getPaymentMethods($customerId);

        if (!$result || !isset($result['data'])) {
            return response()->json([
                'success' => false,
                'active' => false,
                'message' => 'Could not fetch payment methods.'
            ], 500);
        }

        $active = false;
        foreach ($result['data'] as $method) {
            if (isset($method['status']) && $method['status'] === 'active') {
                $active = true;
                break;
            }
        }

        return response()->json([
            'success' => true,
            'active' => $active,
            'methods' => $result['data'],
        ]);
    }
}
