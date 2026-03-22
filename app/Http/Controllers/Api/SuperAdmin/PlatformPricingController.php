<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Organization;
use App\Models\OrganizationPlan;
use App\Models\PlatformPricing;
use App\Services\PlanUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformPricingController extends ApiController
{
    protected PlanUsageService $planService;

    public function __construct(PlanUsageService $planService)
    {
        $this->planService = $planService;
    }

    /**
     * GET — list all pricing items grouped by category
     */
    public function index(): JsonResponse
    {
        $pricing = PlatformPricing::orderBy('sort_order')->get();

        $grouped = $pricing->groupBy('category')->map(function ($items, $category) {
            return [
                'category' => $category,
                'items' => $items->values(),
            ];
        })->values();

        return $this->success($grouped);
    }

    /**
     * POST — create new pricing item
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'category' => 'required|string|max:50',
            'item_key' => 'required|string|max:100',
            'label' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price_monthly' => 'required|numeric|min:0',
            'price_yearly' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
            'tier' => 'nullable|string|max:50',
            'metadata' => 'nullable|array',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $existing = PlatformPricing::where('category', $request->input('category'))
            ->where('item_key', $request->input('item_key'))
            ->exists();

        if ($existing) {
            return $this->error('A pricing item with this category and item_key already exists', [], 409);
        }

        $pricing = PlatformPricing::create($request->only([
            'category', 'item_key', 'label', 'description',
            'price_monthly', 'price_yearly', 'is_active', 'tier',
            'metadata', 'sort_order',
        ]));

        return $this->success($pricing, [], 201);
    }

    /**
     * PUT /{id} — update pricing
     */
    public function update(Request $request, PlatformPricing $pricing): JsonResponse
    {
        $request->validate([
            'label' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'price_monthly' => 'nullable|numeric|min:0',
            'price_yearly' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
            'tier' => 'nullable|string|max:50',
            'metadata' => 'nullable|array',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $pricing->update($request->only([
            'label', 'description', 'price_monthly', 'price_yearly',
            'is_active', 'tier', 'metadata', 'sort_order',
        ]));

        return $this->success($pricing);
    }

    /**
     * DELETE /{id} — soft deactivate (set is_active = false)
     */
    public function destroy(PlatformPricing $pricing): JsonResponse
    {
        $pricing->update(['is_active' => false]);

        return $this->success(['message' => 'Pricing item deactivated']);
    }

    /**
     * GET /org-plans/{organization} — view org's plans + usage
     */
    public function orgPlans(Organization $organization): JsonResponse
    {
        $data = $this->planService->getDashboardData($organization);

        return $this->success($data);
    }

    /**
     * PUT /org-plans/{organization}/{plan} — override org plan
     */
    public function updateOrgPlan(Request $request, Organization $organization, OrganizationPlan $plan): JsonResponse
    {
        if ($plan->organization_id !== $organization->id) {
            return $this->error('Plan does not belong to this organization', [], 403);
        }

        $request->validate([
            'price_override' => 'nullable|numeric|min:0',
            'ai_actions_limit' => 'nullable|integer|min:-1',
            'status' => 'nullable|string|in:active,cancelled,paused',
            'quantity' => 'nullable|integer|min:1',
        ]);

        $updateData = [];

        if ($request->has('price_override')) {
            $updateData['price_override'] = $request->input('price_override');
        }
        if ($request->has('ai_actions_limit')) {
            $updateData['ai_actions_limit'] = $request->integer('ai_actions_limit');
        }
        if ($request->has('status')) {
            $updateData['status'] = $request->input('status');
            if ($request->input('status') === 'cancelled') {
                $updateData['cancelled_at'] = now();
            }
        }
        if ($request->has('quantity')) {
            $updateData['quantity'] = $request->integer('quantity');
        }

        $plan->update($updateData);

        return $this->success($plan->fresh());
    }

    /**
     * POST /org-plans/{organization}/grant — manually grant a plan to an org
     */
    public function grantPlan(Request $request, Organization $organization): JsonResponse
    {
        $request->validate([
            'category' => 'required|string|max:50',
            'item_key' => 'required|string|max:100',
            'quantity' => 'nullable|integer|min:1',
            'price_override' => 'nullable|numeric|min:0',
            'ai_actions_limit' => 'nullable|integer|min:-1',
            'expires_at' => 'nullable|date|after:now',
        ]);

        $plan = $this->planService->subscribe(
            $organization,
            $request->input('category'),
            $request->input('item_key'),
            $request->integer('quantity', 1)
        );

        // Apply super admin overrides
        $overrides = [];
        if ($request->has('price_override')) {
            $overrides['price_override'] = $request->input('price_override');
        }
        if ($request->has('ai_actions_limit')) {
            $overrides['ai_actions_limit'] = $request->integer('ai_actions_limit');
        }
        if ($request->has('expires_at')) {
            $overrides['expires_at'] = $request->input('expires_at');
        }

        if (!empty($overrides)) {
            $plan->update($overrides);
            $plan->refresh();
        }

        return $this->success($plan, [], 201);
    }

    /**
     * GET /revenue-overview — cross-org revenue summary
     */
    public function revenueOverview(): JsonResponse
    {
        $activePlans = OrganizationPlan::where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->get();

        // Group by category
        $byCategory = $activePlans->groupBy('category')->map(function ($plans, $category) {
            $totalRevenue = 0;
            foreach ($plans as $plan) {
                $qty = $plan->quantity ?? 1;
                $totalRevenue += $qty * $plan->getEffectivePrice();
            }

            return [
                'category' => $category,
                'active_plans' => $plans->count(),
                'total_quantity' => $plans->sum('quantity') ?: $plans->count(),
                'monthly_revenue' => round($totalRevenue, 2),
            ];
        })->values();

        // Per-org summary
        $byOrg = $activePlans->groupBy('organization_id')->map(function ($plans, $orgId) {
            $org = Organization::find($orgId);
            $totalRevenue = 0;
            foreach ($plans as $plan) {
                $qty = $plan->quantity ?? 1;
                $totalRevenue += $qty * $plan->getEffectivePrice();
            }

            return [
                'organization_id' => $orgId,
                'organization_name' => $org?->name ?? 'Unknown',
                'active_plans' => $plans->count(),
                'monthly_revenue' => round($totalRevenue, 2),
            ];
        })->sortByDesc('monthly_revenue')->values();

        $totalMonthlyRevenue = $byCategory->sum('monthly_revenue');

        return $this->success([
            'total_monthly_revenue' => round($totalMonthlyRevenue, 2),
            'total_annual_projected' => round($totalMonthlyRevenue * 12, 2),
            'total_active_plans' => $activePlans->count(),
            'total_organizations' => $activePlans->pluck('organization_id')->unique()->count(),
            'by_category' => $byCategory,
            'by_organization' => $byOrg,
        ]);
    }
}
