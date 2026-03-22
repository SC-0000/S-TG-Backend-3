<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationPlan;
use App\Models\PlatformPricing;
use App\Models\Transaction;
use App\Services\BillingService;
use App\Services\PlanUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanController extends ApiController
{
    protected PlanUsageService $planService;

    public function __construct(PlanUsageService $planService)
    {
        $this->planService = $planService;
    }

    private function resolveOrgId(Request $request): ?int
    {
        $orgId = $request->attributes->get('organization_id');
        if ($orgId) return (int) $orgId;

        $user = $request->user();
        if (!$user) return null;

        if ($user->current_organization_id) return (int) $user->current_organization_id;

        $firstOrg = $user->organizations()->first();
        return $firstOrg?->id;
    }

    /**
     * GET /api/admin/plans — full dashboard data
     */
    public function index(Request $request): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if (!$orgId) {
            return $this->error('Organization context required', [], 400);
        }

        $org = Organization::findOrFail($orgId);
        $data = $this->planService->getDashboardData($org);

        return $this->success($data);
    }

    /**
     * GET /api/admin/plans/pricing — available pricing/tiers
     */
    public function pricing(Request $request): JsonResponse
    {
        $data = $this->planService->getAvailablePricing();

        return $this->success($data);
    }

    /**
     * POST /api/admin/plans/subscribe — subscribe to a plan
     */
    public function subscribe(Request $request): JsonResponse
    {
        $request->validate([
            'category' => 'required|string|in:user_seat,ai_workspace,platform,storage,feature_addon',
            'item_key' => 'required|string|max:100',
            'quantity' => 'nullable|integer|min:1',
        ]);

        $orgId = $this->resolveOrgId($request);
        if (!$orgId) {
            return $this->error('Organization context required', [], 400);
        }

        $org = Organization::findOrFail($orgId);
        $quantity = $request->integer('quantity', 1);
        $billingService = app(\App\Services\BillingService::class);

        // Look up pricing
        $pricing = \App\Models\PlatformPricing::where('category', $request->input('category'))
            ->where('item_key', $request->input('item_key'))
            ->first();

        $isPaid = $pricing && $pricing->price_monthly > 0;

        if ($isPaid) {
            // Ensure org has billing customer
            if (! $org->billing_customer_id) {
                $customerId = $billingService->createOrganizationCustomer($org);
                if ($customerId) {
                    $org->update(['billing_customer_id' => $customerId]);
                } else {
                    return $this->error('Could not set up billing. Please try again.', [], 500);
                }
            }

            // Check if org has a saved payment method
            $methods = $billingService->getPaymentMethods($org->billing_customer_id);
            if (empty($methods['data'] ?? [])) {
                return $this->error(
                    'A payment method is required before activating paid plans. Please add a card in the billing section.',
                    ['requires_payment_setup' => true, 'billing_customer_id' => $org->billing_customer_id],
                    422
                );
            }
        }

        // If already subscribed, update the quantity
        $existing = OrganizationPlan::where('organization_id', $org->id)
            ->where('category', $request->input('category'))
            ->where('item_key', $request->input('item_key'))
            ->where('status', 'active')
            ->first();

        if ($existing) {
            $existing->update(['quantity' => $quantity]);
            return $this->success($existing->fresh());
        }

        // Create the plan record
        $plan = $this->planService->subscribe(
            $org,
            $request->input('category'),
            $request->input('item_key'),
            $quantity
        );

        // For paid plans: create invoice and charge immediately
        if ($isPaid && $org->billing_customer_id) {
            $unitPrice = $plan->getEffectivePrice();
            $totalPence = (int) round($unitPrice * $quantity * 100);
            $label = $pricing->label ?? $request->input('item_key');

            // Create invoice in I-BLS-2
            $invoiceId = $billingService->createInvoice([
                'customer_id' => $org->billing_customer_id,
                'currency'    => 'gbp',
                'due_date'    => now()->toDateString(),
                'items'       => [[
                    'description' => "{$label} x{$quantity} (first month)",
                    'quantity'    => $quantity,
                    'unit_amount' => (int) round($unitPrice * 100),
                ]],
            ]);

            if ($invoiceId) {
                // Finalize and try autopay
                $billingService->finalizeInvoice($invoiceId);
                $autopayResult = $billingService->enableAutopay($invoiceId);

                $plan->update(['billing_invoice_id' => $invoiceId]);

                if ($autopayResult['success'] ?? false) {
                    // Payment succeeded
                    $plan->update(['payment_status' => 'paid']);

                    // Create org invoice record
                    OrganizationInvoice::create([
                        'organization_id'    => $org->id,
                        'period_start'       => now()->toDateString(),
                        'period_end'         => now()->endOfMonth()->toDateString(),
                        'line_items'         => [[
                            'label'      => $label,
                            'quantity'   => $quantity,
                            'unit_price' => $unitPrice,
                            'total'      => $unitPrice * $quantity,
                        ]],
                        'subtotal'           => $unitPrice * $quantity,
                        'tax'                => 0,
                        'total'              => $unitPrice * $quantity,
                        'status'             => 'paid',
                        'paid_at'            => now(),
                        'billing_invoice_id' => $invoiceId,
                    ]);

                    return $this->success([
                        'plan'           => $plan->fresh(),
                        'payment_status' => 'paid',
                        'message'        => "Subscribed to {$label}. Payment of £" . number_format($unitPrice * $quantity, 2) . " processed successfully.",
                    ], [], 201);
                } else {
                    // Payment failed — plan active but payment pending
                    $plan->update(['payment_status' => 'failed']);

                    // Create admin task for payment failure
                    if (class_exists(\App\Services\Tasks\TaskService::class)) {
                        \App\Services\Tasks\TaskService::createFromEvent('payment_followup', [
                            'organization_id' => $org->id,
                            'assigned_to'     => $request->user()?->id,
                            'title'           => "Payment failed for {$label} plan",
                            'description'     => "The initial payment of £" . number_format($unitPrice * $quantity, 2) . " for the {$label} plan could not be processed. Please update your payment method.",
                            'priority'        => 'high',
                            'source_model'    => $plan,
                            'source'          => 'system',
                        ]);
                    }

                    // Store org invoice as pending
                    OrganizationInvoice::create([
                        'organization_id'    => $org->id,
                        'period_start'       => now()->toDateString(),
                        'period_end'         => now()->endOfMonth()->toDateString(),
                        'line_items'         => [[
                            'label'      => $label,
                            'quantity'   => $quantity,
                            'unit_price' => $unitPrice,
                            'total'      => $unitPrice * $quantity,
                        ]],
                        'subtotal'           => $unitPrice * $quantity,
                        'tax'                => 0,
                        'total'              => $unitPrice * $quantity,
                        'status'             => 'pending',
                        'billing_invoice_id' => $invoiceId,
                    ]);

                    // Derive billing base URL for frontend widgets
                    $billingBase = config('services.billingsystems.base_uri');
                    $apiBase = str_contains($billingBase, '/api') ? $billingBase : rtrim($billingBase, '/') . '/api/v1';

                    return $this->success([
                        'plan'                => $plan->fresh(),
                        'payment_status'      => 'failed',
                        'requires_payment'    => true,
                        'invoice_id'          => $invoiceId,
                        'billing_customer_id' => $org->billing_customer_id,
                        'api_key'             => $billingService->getPublishableKey(),
                        'api_base_url'        => $apiBase,
                        'message'             => 'Plan activated but payment could not be processed. Please complete payment.',
                    ], [], 201);
                }
            } else {
                // Invoice creation failed — plan still active but no charge
                $plan->update(['payment_status' => 'pending']);

                return $this->success([
                    'plan'           => $plan->fresh(),
                    'payment_status' => 'pending',
                    'message'        => "Plan activated. Payment will be processed shortly.",
                ], [], 201);
            }
        }

        // Free plan
        return $this->success([
            'plan'           => $plan,
            'payment_status' => 'free',
            'message'        => 'Plan activated.',
        ], [], 201);
    }

    /**
     * POST /api/admin/plans/{plan}/cancel — cancel a plan
     */
    public function cancel(Request $request, OrganizationPlan $plan): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if (!$orgId) {
            return $this->error('Organization context required', [], 400);
        }

        if ($plan->organization_id !== (int) $orgId) {
            return $this->error('Plan does not belong to this organization', [], 403);
        }

        if ($plan->status !== 'active') {
            return $this->error('Plan is not active', [], 400);
        }

        $org = Organization::findOrFail($orgId);
        $cancelled = $this->planService->cancelPlan($org, $plan->id);

        return $this->success($cancelled);
    }

    /**
     * POST /api/admin/plans/enable-ai-all — enable AI for all admins and teachers
     */
    public function enableAiAll(Request $request): JsonResponse
    {
        $request->validate([
            'tier' => 'required|string|in:essentials,professional,enterprise',
        ]);

        $orgId = $this->resolveOrgId($request);
        if (!$orgId) {
            return $this->error('Organization context required', [], 400);
        }

        $org = Organization::findOrFail($orgId);
        $usage = $this->planService->getCurrentUsage($org);
        $tier = $request->input('tier');

        $adminCount = max(1, $usage['admins']);
        $teacherCount = max(0, $usage['teachers']);

        // Subscribe or update admin seats
        $adminKey = "ai_{$tier}_admin";
        $existingAdmin = OrganizationPlan::where('organization_id', $org->id)
            ->where('category', 'ai_workspace')
            ->where('item_key', $adminKey)
            ->where('status', 'active')
            ->first();

        if ($existingAdmin) {
            $existingAdmin->update(['quantity' => $adminCount]);
        } else {
            // Cancel any other tier admin plans first
            OrganizationPlan::where('organization_id', $org->id)
                ->where('category', 'ai_workspace')
                ->where('item_key', 'like', '%_admin')
                ->where('status', 'active')
                ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            $this->planService->subscribe($org, 'ai_workspace', $adminKey, $adminCount);
        }

        // Subscribe or update teacher seats
        if ($teacherCount > 0) {
            $teacherKey = "ai_{$tier}_teacher";
            $existingTeacher = OrganizationPlan::where('organization_id', $org->id)
                ->where('category', 'ai_workspace')
                ->where('item_key', $teacherKey)
                ->where('status', 'active')
                ->first();

            if ($existingTeacher) {
                $existingTeacher->update(['quantity' => $teacherCount]);
            } else {
                OrganizationPlan::where('organization_id', $org->id)
                    ->where('category', 'ai_workspace')
                    ->where('item_key', 'like', '%_teacher')
                    ->where('status', 'active')
                    ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

                $this->planService->subscribe($org, 'ai_workspace', $teacherKey, $teacherCount);
            }
        }

        return $this->success([
            'message' => 'AI enabled for entire team',
            'admin_seats' => $adminCount,
            'teacher_seats' => $teacherCount,
        ]);
    }

    /**
     * GET /api/admin/plans/invoices — invoice history with billing cycle info
     */
    public function invoices(Request $request): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if (!$orgId) {
            return $this->error('Organization context required', [], 400);
        }

        $invoices = OrganizationInvoice::where('organization_id', $orgId)
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        $invoiceData = collect($invoices->items())->map(function ($inv) {
            return [
                'id'             => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'period'         => $inv->period_start && $inv->period_end
                    ? $inv->period_start->format('d M') . ' — ' . $inv->period_end->format('d M Y')
                    : null,
                'period_start'   => $inv->period_start?->toDateString(),
                'period_end'     => $inv->period_end?->toDateString(),
                'line_items'     => $inv->line_items,
                'subtotal'       => (float) $inv->subtotal,
                'tax'            => (float) $inv->tax,
                'amount'         => (float) $inv->total,
                'status'         => $inv->status,
                'paid_at'        => $inv->paid_at?->format('d M Y'),
                'created_at'     => $inv->created_at?->format('d M Y'),
                'billing_invoice_id' => $inv->billing_invoice_id,
            ];
        })->all();

        // Current billing cycle info
        $billingCycle = [
            'current_period_start' => now()->startOfMonth()->format('d M Y'),
            'current_period_end'   => now()->endOfMonth()->format('d M Y'),
            'next_invoice_date'    => now()->addMonth()->startOfMonth()->format('d M Y'),
            'days_remaining'       => (int) round(abs(now()->floatDiffInDays(now()->endOfMonth()))),
        ];

        return $this->success([
            'invoices'      => $invoiceData,
            'billing_cycle' => $billingCycle,
            'pagination'    => [
                'total'        => $invoices->total(),
                'current_page' => $invoices->currentPage(),
                'last_page'    => $invoices->lastPage(),
                'per_page'     => $invoices->perPage(),
            ],
        ]);
    }

    /**
     * GET /api/admin/plans/ai-usage — AI usage stats
     */
    public function aiUsage(Request $request): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if (!$orgId) {
            return $this->error('Organization context required', [], 400);
        }

        $org = Organization::findOrFail($orgId);
        $stats = $this->planService->getAiUsageStats($org);

        return $this->success($stats);
    }

    /**
     * GET /api/admin/plans/billing-setup — returns org billing customer + payment methods
     */
    public function billingSetup(Request $request): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if (!$orgId) {
            return $this->error('Organization context required', [], 400);
        }

        $org = Organization::findOrFail($orgId);
        $billingService = app(\App\Services\BillingService::class);

        // Ensure org has billing customer
        if (! $org->billing_customer_id) {
            $customerId = $billingService->createOrganizationCustomer($org);
            if ($customerId) {
                $org->update(['billing_customer_id' => $customerId]);
            }
        }

        $paymentMethods = [];
        if ($org->billing_customer_id) {
            $methods = $billingService->getPaymentMethods($org->billing_customer_id);
            $paymentMethods = $methods['data'] ?? [];
        }

        // Derive widget base URL from billing API URL (strip /api/v1 suffix)
        $billingBaseUrl = config('services.billingsystems.base_uri');
        $apiBaseUrl = $billingBaseUrl;
        // Ensure it has /api/v1 for widget apiBaseUrl
        if (! str_contains($billingBaseUrl, '/api')) {
            $apiBaseUrl = rtrim($billingBaseUrl, '/') . '/api/v1';
        }

        // Resolve tokens: prefer org-specific, fall back to platform config
        $publishableKey = $org->getApiKey('billing_publishable')
            ?: $billingService->getPublishableKey();
        $adminApiKey = $org->getApiKey('billing_admin')
            ?: $billingService->getAdminToken();

        return $this->success([
            'billing_customer_id' => $org->billing_customer_id,
            'api_key'             => $publishableKey,
            'admin_api_key'       => $adminApiKey,
            'api_base_url'        => $apiBaseUrl,
            'payment_methods'     => $paymentMethods,
            'has_payment_method'  => ! empty($paymentMethods),
        ]);
    }

    /**
     * GET /api/admin/plans/ai-members — list org admins/teachers with AI seat status
     */
    public function aiMembers(Request $request): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if (!$orgId) {
            return $this->error('Organization context required', [], 400);
        }

        $org = Organization::findOrFail($orgId);

        // Get org-scoped admin and teacher users only (exclude platform super_admin)
        $memberMap = collect();

        // 1. Users in org pivot with org_admin or teacher roles
        $pivotUsers = $org->users()
            ->wherePivotIn('role', ['org_admin', 'teacher'])
            ->wherePivot('status', 'active')
            ->get(['users.id', 'users.name', 'users.email', 'users.avatar_path']);

        foreach ($pivotUsers as $user) {
            $pivotRole = $user->pivot->role;
            $memberMap[$user->id] = [
                'id'       => $user->id,
                'name'     => $user->name,
                'email'    => $user->email,
                'avatar'   => $user->avatar_path,
                'org_role' => $pivotRole,
                'ai_role'  => $pivotRole === 'org_admin' ? 'admin' : 'teacher',
            ];
        }

        // 2. Org-level admins (role=admin, not super_admin) linked via current_organization_id
        $orgAdmins = \App\Models\User::where('current_organization_id', $orgId)
            ->where('role', 'admin')
            ->get(['id', 'name', 'email', 'avatar_path', 'role']);

        foreach ($orgAdmins as $admin) {
            if (! $memberMap->has($admin->id)) {
                $memberMap[$admin->id] = [
                    'id'       => $admin->id,
                    'name'     => $admin->name,
                    'email'    => $admin->email,
                    'avatar'   => $admin->avatar_path,
                    'org_role' => 'admin',
                    'ai_role'  => 'admin',
                ];
            }
        }

        $members = $memberMap->values();

        // Get current AI plans to know seats purchased
        $aiPlans = OrganizationPlan::where('organization_id', $orgId)
            ->where('category', 'ai_workspace')
            ->where('status', 'active')
            ->get();

        $adminPlan = $aiPlans->first(fn ($p) => str_contains($p->item_key, '_admin'));
        $teacherPlan = $aiPlans->first(fn ($p) => str_contains($p->item_key, '_teacher'));

        $admins = $members->where('ai_role', 'admin')->values();
        $teachers = $members->where('ai_role', 'teacher')->values();

        return $this->success([
            'admins' => [
                'members'         => $admins,
                'total'           => $admins->count(),
                'seats_purchased' => $adminPlan->quantity ?? 0,
                'tier'            => $adminPlan ? (PlatformPricing::where('category', 'ai_workspace')->where('item_key', $adminPlan->item_key)->value('tier')) : null,
            ],
            'teachers' => [
                'members'         => $teachers,
                'total'           => $teachers->count(),
                'seats_purchased' => $teacherPlan->quantity ?? 0,
                'tier'            => $teacherPlan ? (PlatformPricing::where('category', 'ai_workspace')->where('item_key', $teacherPlan->item_key)->value('tier')) : null,
            ],
        ]);
    }

    /**
     * GET /api/admin/plans/billing-reconciliation
     *
     * Fetches local Transactions (B2C parent/student payments within the org)
     * and compares them against the billing provider (I-BLS-2) to surface
     * discrepancies. Uses both the client-scoped API (invoices endpoint)
     * and the admin-scoped API for cross-client visibility.
     */
    public function billingReconciliation(Request $request): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if (! $orgId) {
            return $this->error('Organization context required', [], 400);
        }

        $org = Organization::findOrFail($orgId);
        $billingService = app(BillingService::class);

        // 1. Get local transactions (B2C: parents/students paying for org services)
        $localTransactions = Transaction::with('user:id,name,email')
            ->where(function ($q) use ($orgId) {
                $q->where('organization_id', $orgId)
                  ->orWhereHas('user', fn ($u) => $u->where('current_organization_id', $orgId));
            })
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        // 2. Fetch billing provider invoices
        //    Try client-scoped API first (uses the org's own billing token),
        //    then fall back to admin API if admin token is available.
        $billingInvoices = [];
        $billingError = null;
        $billingConnected = false;

        // 2a. Client-scoped: GET /api/v1/invoices (uses org's own token)
        $clientResult = $billingService->getClientInvoices(['per_page' => 200]);
        if ($clientResult !== null) {
            $billingConnected = true;
            $raw = $clientResult['data'] ?? $clientResult;
            // Handle paginated vs flat
            $billingInvoices = is_array($raw) ? (isset($raw['data']) && is_array($raw['data']) ? $raw['data'] : $raw) : [];
        }

        // 2b. If client API returned nothing, try admin API
        if (empty($billingInvoices)) {
            $hasAdminToken = (bool) $billingService->getAdminToken();
            if ($hasAdminToken) {
                $adminResult = $billingService->getAdminInvoices(['per_page' => 200]);
                if ($adminResult !== null) {
                    $billingConnected = true;
                    $raw = $adminResult['data'] ?? $adminResult;
                    $billingInvoices = is_array($raw) ? (isset($raw['data']) && is_array($raw['data']) ? $raw['data'] : $raw) : [];
                }
            }
        }

        if (! $billingConnected) {
            $billingError = 'Could not connect to billing provider. Verify that the organisation\'s billing tokens are correctly configured in Settings > Integrations.';
        }

        // 3. Index billing invoices by ID for O(1) lookup
        $billingById = collect($billingInvoices)->keyBy('id');

        // 4. Build reconciled list
        $reconciled = [];
        $discrepancies = [];
        $matchedBillingIds = [];

        foreach ($localTransactions as $tx) {
            $entry = [
                'id'                  => $tx->id,
                'customer_name'       => $tx->user?->name ?? 'Guest',
                'customer_email'      => $tx->user_email ?? $tx->user?->email,
                'type'                => $tx->type,
                'payment_method'      => $tx->payment_method,
                'local_amount'        => round((float) $tx->total, 2),
                'local_subtotal'      => round((float) $tx->subtotal, 2),
                'local_tax'           => round((float) ($tx->tax ?? 0), 2),
                'local_status'        => $tx->status,
                'invoice_id'          => $tx->invoice_id,
                'paid_at'             => $tx->paid_at?->format('d M Y H:i'),
                'created_at'          => $tx->created_at?->format('d M Y'),
                'created_at_iso'      => $tx->created_at?->toISOString(),
                'billing_status'      => null,
                'billing_amount'      => null,
                'billing_due_date'    => null,
                'billing_auto_bill'   => null,
                'billing_number'      => null,
                'billing_currency'    => null,
                'has_discrepancy'     => false,
                'discrepancy_details' => [],
                'source'              => 'local_no_invoice',
            ];

            if (! $tx->invoice_id) {
                // Transaction has no invoice_id at all — never synced to billing
                $entry['has_discrepancy'] = true;
                $entry['discrepancy_details'] = [[
                    'field'   => 'no_invoice',
                    'message' => 'No billing invoice created for this transaction',
                ]];
                $discrepancies[] = $entry;
                $reconciled[] = $entry;
                continue;
            }

            // Has invoice_id — try to match
            $entry['source'] = 'local';

            if ($billingById->has($tx->invoice_id)) {
                $billing = $billingById->get($tx->invoice_id);
                $matchedBillingIds[] = $tx->invoice_id;

                $entry['billing_status']    = $billing['status'] ?? null;
                $entry['billing_amount']    = isset($billing['amount_due']) ? round($billing['amount_due'] / 100, 2) : null;
                $entry['billing_due_date']  = $billing['due_date'] ?? null;
                $entry['billing_auto_bill'] = $billing['auto_bill'] ?? null;
                $entry['billing_number']    = $billing['number'] ?? null;
                $entry['billing_currency']  = $billing['currency'] ?? null;
                $entry['source']            = 'matched';

                // Check for discrepancies
                $details = [];

                $billingStatus = strtolower($billing['status'] ?? '');
                $localStatus = strtolower($tx->status);
                if ($billingStatus) {
                    $statusMap = ['open' => 'pending', 'uncollectible' => 'failed'];
                    $normalizedBilling = $statusMap[$billingStatus] ?? $billingStatus;
                    $normalizedLocal = $localStatus === 'completed' ? 'paid' : $localStatus;

                    if ($normalizedLocal !== $normalizedBilling) {
                        $details[] = [
                            'field'         => 'status',
                            'local_value'   => $localStatus,
                            'billing_value' => $billingStatus,
                            'message'       => "Status: local '{$localStatus}' vs billing '{$billingStatus}'",
                        ];
                    }
                }

                if ($entry['billing_amount'] !== null) {
                    $localAmount = round((float) $tx->total, 2);
                    $billingAmount = $entry['billing_amount'];
                    if (abs($localAmount - $billingAmount) > 0.01) {
                        $details[] = [
                            'field'         => 'amount',
                            'local_value'   => $localAmount,
                            'billing_value' => $billingAmount,
                            'message'       => "Amount: local £" . number_format($localAmount, 2) . " vs billing £" . number_format($billingAmount, 2),
                        ];
                    }
                }

                if (! empty($details)) {
                    $entry['has_discrepancy'] = true;
                    $entry['discrepancy_details'] = $details;
                    $discrepancies[] = $entry;
                }
            } else {
                // Has invoice_id but not found in billing provider
                $entry['has_discrepancy'] = true;
                $entry['source'] = 'local_only';

                if ($billingConnected) {
                    $entry['discrepancy_details'] = [[
                        'field'   => 'missing_billing',
                        'message' => 'Invoice ID exists locally but not found in billing provider',
                    ]];
                } else {
                    $entry['discrepancy_details'] = [[
                        'field'   => 'unverified',
                        'message' => 'Cannot verify — billing provider not connected',
                    ]];
                }
                $discrepancies[] = $entry;
            }

            $reconciled[] = $entry;
        }

        // 5. Find billing-only invoices
        $billingOnly = [];
        foreach ($billingInvoices as $billing) {
            $bId = $billing['id'] ?? null;
            if ($bId && ! in_array($bId, $matchedBillingIds)) {
                $billingEntry = [
                    'id'                  => null,
                    'customer_name'       => $billing['customer']['name'] ?? null,
                    'customer_email'      => $billing['customer']['email'] ?? null,
                    'type'                => null,
                    'payment_method'      => null,
                    'local_amount'        => null,
                    'local_subtotal'      => null,
                    'local_tax'           => null,
                    'local_status'        => null,
                    'invoice_id'          => $bId,
                    'paid_at'             => null,
                    'created_at'          => isset($billing['created_at']) ? \Carbon\Carbon::parse($billing['created_at'])->format('d M Y') : null,
                    'created_at_iso'      => $billing['created_at'] ?? null,
                    'billing_status'      => $billing['status'] ?? null,
                    'billing_amount'      => isset($billing['amount_due']) ? round($billing['amount_due'] / 100, 2) : null,
                    'billing_due_date'    => $billing['due_date'] ?? null,
                    'billing_auto_bill'   => $billing['auto_bill'] ?? null,
                    'billing_number'      => $billing['number'] ?? null,
                    'billing_currency'    => $billing['currency'] ?? null,
                    'has_discrepancy'     => true,
                    'discrepancy_details' => [[
                        'field'   => 'missing_local',
                        'message' => 'Invoice in billing provider with no local transaction',
                    ]],
                    'source'              => 'billing_only',
                ];
                $billingOnly[] = $billingEntry;
                $discrepancies[] = $billingEntry;
            }
        }

        // 6. Summary stats
        $totalLocalRevenue = $localTransactions->whereIn('status', ['paid', 'completed'])->sum('total');
        $totalLocalPending = $localTransactions->where('status', 'pending')->sum('total');
        $totalBilling = collect($billingInvoices)->sum(fn ($inv) => ($inv['amount_due'] ?? 0) / 100);
        $paidBilling = collect($billingInvoices)->where('status', 'paid')->sum(fn ($inv) => ($inv['amount_due'] ?? 0) / 100);
        $noInvoiceCount = $localTransactions->filter(fn ($tx) => ! $tx->invoice_id)->count();

        return $this->success([
            'transactions'      => array_merge($reconciled, $billingOnly),
            'discrepancies'     => $discrepancies,
            'has_discrepancies' => ! empty($discrepancies),
            'summary'           => [
                'total_transactions'     => $localTransactions->count(),
                'total_billing_invoices' => count($billingInvoices),
                'local_revenue'          => round((float) $totalLocalRevenue, 2),
                'local_pending'          => round((float) $totalLocalPending, 2),
                'billing_total'          => round($totalBilling, 2),
                'billing_paid'           => round($paidBilling, 2),
                'discrepancy_count'      => count($discrepancies),
                'matched_count'          => count($matchedBillingIds),
                'unmatched_count'        => $localTransactions->whereNotNull('invoice_id')->count() - count($matchedBillingIds),
                'no_invoice_count'       => $noInvoiceCount,
                'billing_only_count'     => count($billingOnly),
                'paid_count'             => $localTransactions->whereIn('status', ['paid', 'completed'])->count(),
                'pending_count'          => $localTransactions->where('status', 'pending')->count(),
                'failed_count'           => $localTransactions->where('status', 'failed')->count(),
            ],
            'billing_connected'   => $billingConnected,
            'billing_error'       => $billingError,
        ]);
    }

    /**
     * GET /api/admin/plans/billing-invoice/{invoiceId}
     *
     * Get detailed invoice info from the billing provider alongside the local transaction.
     * Tries client-scoped API first, then admin API.
     */
    public function billingInvoiceDetail(Request $request, string $invoiceId): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if (! $orgId) {
            return $this->error('Organization context required', [], 400);
        }

        $billingService = app(BillingService::class);

        // Try client-scoped first, then admin
        $billingData = $billingService->getClientInvoice($invoiceId);
        if (! $billingData) {
            $billingData = $billingService->getAdminInvoice($invoiceId);
        }

        // Find the local transaction linked to this invoice
        $localTx = Transaction::with('user:id,name,email', 'items.item')
            ->where('invoice_id', $invoiceId)
            ->where(function ($q) use ($orgId) {
                $q->where('organization_id', $orgId)
                  ->orWhereHas('user', fn ($u) => $u->where('current_organization_id', $orgId));
            })
            ->first();

        if (! $billingData && ! $localTx) {
            return $this->error('Invoice not found', [], 404);
        }

        return $this->success([
            'billing' => $billingData ? [
                'id'          => $billingData['id'] ?? $invoiceId,
                'number'      => $billingData['number'] ?? null,
                'status'      => $billingData['status'] ?? null,
                'currency'    => $billingData['currency'] ?? null,
                'amount_due'  => isset($billingData['amount_due']) ? round($billingData['amount_due'] / 100, 2) : null,
                'due_date'    => $billingData['due_date'] ?? null,
                'auto_bill'   => $billingData['auto_bill'] ?? null,
                'items'       => $billingData['items'] ?? [],
                'customer'    => $billingData['customer'] ?? null,
                'created_at'  => $billingData['created_at'] ?? null,
                'meta'        => $billingData['meta'] ?? null,
            ] : null,
            'local' => $localTx ? [
                'id'             => $localTx->id,
                'customer_name'  => $localTx->user?->name ?? 'Guest',
                'customer_email' => $localTx->user_email ?? $localTx->user?->email,
                'status'         => $localTx->status,
                'type'           => $localTx->type,
                'total'          => round((float) $localTx->total, 2),
                'subtotal'       => round((float) $localTx->subtotal, 2),
                'tax'            => round((float) ($localTx->tax ?? 0), 2),
                'payment_method' => $localTx->payment_method,
                'items'          => $localTx->items->map(fn ($i) => [
                    'name'     => $i->item?->name ?? $i->description ?? 'Item',
                    'quantity' => $i->quantity ?? 1,
                    'price'    => round((float) ($i->price ?? $i->amount ?? 0), 2),
                ])->all(),
                'paid_at'        => $localTx->paid_at?->format('d M Y H:i'),
                'created_at'     => $localTx->created_at?->format('d M Y'),
            ] : null,
        ]);
    }
}
