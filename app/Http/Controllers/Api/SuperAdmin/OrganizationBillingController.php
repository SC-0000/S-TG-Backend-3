<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Services\BillingService;
use App\Services\PlanUsageService;
use App\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationBillingController extends ApiController
{
    protected PlanUsageService $planService;

    public function __construct(PlanUsageService $planService)
    {
        $this->planService = $planService;
    }

    /**
     * GET /org/{org}/billing — list org invoices.
     */
    public function index(Request $request, Organization $organization): JsonResponse
    {
        $query = OrganizationInvoice::where('organization_id', $organization->id);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $invoices = $query->orderByDesc('created_at')
            ->paginate(ApiPagination::perPage($request));

        return $this->paginated($invoices, $invoices->getCollection()->toArray());
    }

    /**
     * POST /org/{org}/billing/setup-customer — ensure org has billing_customer_id.
     */
    public function setupCustomer(Organization $organization, BillingService $billing): JsonResponse
    {
        if ($organization->billing_customer_id) {
            return $this->success([
                'billing_customer_id' => $organization->billing_customer_id,
                'message'             => 'Billing customer already exists.',
            ]);
        }

        $customerId = $billing->createOrganizationCustomer($organization);

        if (! $customerId) {
            return $this->error('Could not create billing customer for this organization.', [], 500);
        }

        $organization->update(['billing_customer_id' => $customerId]);

        return $this->success([
            'billing_customer_id' => $customerId,
            'message'             => 'Billing customer created.',
        ], [], 201);
    }

    /**
     * POST /org/{org}/billing/invoice — create invoice from active plans + usage.
     */
    public function createInvoice(Request $request, Organization $organization, BillingService $billing): JsonResponse
    {
        $request->validate([
            'period_start' => 'nullable|date',
            'period_end'   => 'nullable|date|after_or_equal:period_start',
        ]);

        // Ensure org has billing customer
        if (! $organization->billing_customer_id) {
            $customerId = $billing->createOrganizationCustomer($organization);
            if (! $customerId) {
                return $this->error('Could not create billing customer.', [], 500);
            }
            $organization->update(['billing_customer_id' => $customerId]);
        }

        // Calculate monthly cost from plans + usage
        $costData = $this->planService->calculateMonthlyCost($organization);

        if (empty($costData['line_items'])) {
            return $this->error('Organization has no billable items.', [], 422);
        }

        $periodStart = $request->input('period_start', now()->startOfMonth()->toDateString());
        $periodEnd   = $request->input('period_end', now()->endOfMonth()->toDateString());

        // Create invoice in I-BLS-2
        $billingItems = collect($costData['line_items'])
            ->filter(fn ($item) => ($item['unit_price'] ?? 0) > 0)
            ->map(fn ($item) => [
                'description' => $item['label'],
                'quantity'    => $item['quantity'],
                'unit_amount' => (int) round($item['unit_price'] * 100),
            ])->values()->all();

        $invoiceId = $billing->createInvoice([
            'customer_id' => $organization->billing_customer_id,
            'currency'    => 'gbp',
            'due_date'    => now()->addDays(14)->toDateString(),
            'items'       => $billingItems,
            'auto_bill'   => true,
        ]);

        if (! $invoiceId) {
            return $this->error('Could not create invoice in billing provider.', [], 500);
        }

        // Store locally
        $orgInvoice = OrganizationInvoice::create([
            'organization_id'    => $organization->id,
            'period_start'       => $periodStart,
            'period_end'         => $periodEnd,
            'line_items'         => $costData['line_items'],
            'subtotal'           => $costData['subtotal'],
            'tax'                => $costData['tax'] ?? 0,
            'total'              => $costData['total'] ?? $costData['subtotal'],
            'status'             => 'pending',
            'billing_invoice_id' => $invoiceId,
        ]);

        return $this->success($orgInvoice, [], 201);
    }

    /**
     * POST /org/{org}/billing/invoice/{invoice}/finalize — finalize (draft → open).
     */
    public function finalizeInvoice(Organization $organization, OrganizationInvoice $invoice, BillingService $billing): JsonResponse
    {
        if ($invoice->organization_id !== $organization->id) {
            return $this->error('Invoice does not belong to this organization.', [], 403);
        }

        if (! $invoice->billing_invoice_id) {
            return $this->error('Invoice has no billing provider reference.', [], 422);
        }

        $result = $billing->finalizeInvoice($invoice->billing_invoice_id);
        if (! $result) {
            return $this->error('Could not finalize invoice.', [], 500);
        }

        $invoice->update(['status' => 'pending']);

        return $this->success(['message' => 'Invoice finalized.', 'invoice' => $invoice->fresh()]);
    }

    /**
     * POST /org/{org}/billing/invoice/{invoice}/void — void invoice.
     */
    public function voidInvoice(Organization $organization, OrganizationInvoice $invoice, BillingService $billing): JsonResponse
    {
        if ($invoice->organization_id !== $organization->id) {
            return $this->error('Invoice does not belong to this organization.', [], 403);
        }

        if ($invoice->isPaid()) {
            return $this->error('Cannot void a paid invoice.', [], 422);
        }

        if ($invoice->billing_invoice_id) {
            $billing->voidInvoice($invoice->billing_invoice_id);
        }

        $invoice->update(['status' => 'void']);

        return $this->success(['message' => 'Invoice voided.']);
    }

    /**
     * POST /org/{org}/billing/invoice/{invoice}/enable-autopay.
     */
    public function enableAutopay(Organization $organization, OrganizationInvoice $invoice, BillingService $billing): JsonResponse
    {
        if ($invoice->organization_id !== $organization->id) {
            return $this->error('Invoice does not belong to this organization.', [], 403);
        }

        if (! $invoice->billing_invoice_id) {
            return $this->error('Invoice has no billing provider reference.', [], 422);
        }

        $result = $billing->enableAutopay($invoice->billing_invoice_id);

        if (! ($result['success'] ?? false)) {
            return $this->error('Could not enable autopay. Ensure the organization has a default payment method.', [], 422);
        }

        return $this->success(['message' => 'Autopay enabled.']);
    }
}
