<?php

namespace App\Services;

use App\Models\AgentTokenBalance;
use App\Models\AgentTokenTransaction;
use App\Models\Child;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationPlan;
use App\Models\OrganizationUsageSnapshot;
use App\Models\PlatformPricing;
use Illuminate\Support\Collection;

class PlanUsageService
{
    /**
     * Get current real-time usage counts for an org.
     */
    public function getCurrentUsage(Organization $org): array
    {
        $admins = $org->users()
            ->wherePivotIn('role', ['org_admin', 'super_admin'])
            ->wherePivot('status', 'active')
            ->count();

        // Include the org owner if not already counted via pivot
        if ($org->owner_id && !$org->users()
            ->where('users.id', $org->owner_id)
            ->wherePivotIn('role', ['org_admin', 'super_admin'])
            ->exists()) {
            $admins++;
        }

        $teachers = $org->users()
            ->wherePivot('role', 'teacher')
            ->wherePivot('status', 'active')
            ->count();

        $children = Child::where('organization_id', $org->id)->count();

        $storageBytes = (int) \App\Models\MediaAsset::where('organization_id', $org->id)->sum('size_bytes');
        $storageMb = round($storageBytes / 1048576, 1);

        $aiAdminSeats = OrganizationPlan::where('organization_id', $org->id)
            ->where('category', 'ai_workspace')
            ->where('status', 'active')
            ->where('item_key', 'like', '%_admin')
            ->sum('quantity') ?: 0;

        $aiTeacherSeats = OrganizationPlan::where('organization_id', $org->id)
            ->where('category', 'ai_workspace')
            ->where('status', 'active')
            ->where('item_key', 'like', '%_teacher')
            ->sum('quantity') ?: 0;

        return [
            'admins' => $admins,
            'teachers' => $teachers,
            'children' => $children,
            'storage_mb' => $storageMb,
            'ai_admin_seats' => (int) $aiAdminSeats,
            'ai_teacher_seats' => (int) $aiTeacherSeats,
        ];
    }

    /**
     * Get all active plans for an org.
     */
    public function getActivePlans(Organization $org): Collection
    {
        return OrganizationPlan::where('organization_id', $org->id)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->get()
            ->each(function (OrganizationPlan $plan) {
                // Eager-load pricing manually via the composite key
                $plan->setRelation('pricing', PlatformPricing::where('category', $plan->category)
                    ->where('item_key', $plan->item_key)
                    ->first());
            });
    }

    /**
     * Calculate the current monthly cost breakdown.
     */
    public function calculateMonthlyCost(Organization $org): array
    {
        $this->ensureUserSeatPlans($org);

        $usage = $this->getCurrentUsage($org);
        $plans = $this->getActivePlans($org);
        $lineItems = [];
        $subtotal = 0.00;

        // User seat costs: cost = actual user count x unit price
        $seatTypes = [
            'child' => ['count' => $usage['children'], 'label' => 'Student Seats'],
            'teacher' => ['count' => $usage['teachers'], 'label' => 'Teacher Seats'],
            'admin' => ['count' => $usage['admins'], 'label' => 'Admin Seats'],
        ];

        foreach ($seatTypes as $itemKey => $info) {
            $plan = $plans->first(fn ($p) => $p->category === 'user_seat' && $p->item_key === $itemKey);
            if ($plan) {
                $unitPrice = $plan->getEffectivePrice();
                $total = $info['count'] * $unitPrice;
                $lineItems[] = [
                    'item_key' => $itemKey,
                    'label' => $info['label'],
                    'quantity' => $info['count'],
                    'unit_price' => $unitPrice,
                    'total' => $total,
                ];
                $subtotal += $total;
            }
        }

        // AI workspace costs: cost = quantity purchased x unit price
        $aiPlans = $plans->filter(fn ($p) => $p->category === 'ai_workspace');
        foreach ($aiPlans as $plan) {
            $unitPrice = $plan->getEffectivePrice();
            $qty = $plan->quantity ?? 1;
            $total = $qty * $unitPrice;
            $pricing = $plan->getRelation('pricing');
            $lineItems[] = [
                'item_key' => $plan->item_key,
                'label' => $pricing ? $pricing->label : $plan->item_key,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'total' => $total,
            ];
            $subtotal += $total;
        }

        // Platform base cost
        $platformPlan = $plans->first(fn ($p) => $p->category === 'platform' && $p->item_key === 'platform_base');
        if ($platformPlan) {
            $unitPrice = $platformPlan->getEffectivePrice();
            $lineItems[] = [
                'item_key' => 'platform_base',
                'label' => 'Platform Access',
                'quantity' => 1,
                'unit_price' => $unitPrice,
                'total' => $unitPrice,
            ];
            $subtotal += $unitPrice;
        }

        return [
            'line_items' => $lineItems,
            'subtotal' => round($subtotal, 2),
            'total' => round($subtotal, 2),
        ];
    }

    /**
     * Ensure user seat plans exist for an org. These are auto-provisioned
     * (retroactive billing like Google Workspace — not purchased upfront).
     */
    public function ensureUserSeatPlans(Organization $org): void
    {
        $seatTypes = ['child', 'teacher', 'admin'];

        foreach ($seatTypes as $itemKey) {
            $exists = OrganizationPlan::where('organization_id', $org->id)
                ->where('category', 'user_seat')
                ->where('item_key', $itemKey)
                ->where('status', 'active')
                ->exists();

            if (!$exists) {
                OrganizationPlan::create([
                    'organization_id' => $org->id,
                    'category' => 'user_seat',
                    'item_key' => $itemKey,
                    'status' => 'active',
                    'started_at' => now(),
                ]);
            }
        }

        // Also ensure platform_base plan exists
        $platformExists = OrganizationPlan::where('organization_id', $org->id)
            ->where('category', 'platform')
            ->where('item_key', 'platform_base')
            ->where('status', 'active')
            ->exists();

        if (!$platformExists) {
            OrganizationPlan::create([
                'organization_id' => $org->id,
                'category' => 'platform',
                'item_key' => 'platform_base',
                'status' => 'active',
                'started_at' => now(),
            ]);
        }
    }

    /**
     * Get the full dashboard data for the Plans & Usage page.
     */
    public function getDashboardData(Organization $org): array
    {
        $this->ensureUserSeatPlans($org);

        $usage = $this->getCurrentUsage($org);
        $plans = $this->getActivePlans($org);
        $costBreakdown = $this->calculateMonthlyCost($org);
        $aiUsage = $this->getAiUsageStats($org);
        $trends = $this->getUsageTrends($org, 6);

        $tokenBalance = AgentTokenBalance::where('organization_id', $org->id)->first();
        $tokenBillingService = app(\App\Services\AI\TokenBillingService::class);
        $tokenUsageSummary = $tokenBillingService->getUsageSummary($org);

        return [
            'usage' => $usage,
            'plans' => $plans->map(function (OrganizationPlan $plan) {
                $pricing = $plan->getRelation('pricing');
                return [
                    'id' => $plan->id,
                    'category' => $plan->category,
                    'item_key' => $plan->item_key,
                    'status' => $plan->status,
                    'quantity' => $plan->quantity,
                    'effective_price' => $plan->getEffectivePrice(),
                    'price_override' => $plan->price_override,
                    'started_at' => $plan->started_at?->toISOString(),
                    'expires_at' => $plan->expires_at?->toISOString(),
                    'label' => $pricing?->label ?? $plan->item_key,
                    'tier' => $pricing?->tier,
                    'ai_actions_limit' => $plan->ai_actions_limit,
                    'ai_actions_used' => $plan->ai_actions_used,
                    'payment_status' => $plan->payment_status,
                    'billing_invoice_id' => $plan->billing_invoice_id,
                ];
            })->values(),
            'cost_breakdown' => $costBreakdown,
            'ai_usage' => $aiUsage,
            'token_balance' => $tokenBalance ? [
                'balance' => $tokenBalance->balance,
                'lifetime_purchased' => $tokenBalance->lifetime_purchased,
                'lifetime_consumed' => $tokenBalance->lifetime_consumed,
                'low_balance_threshold' => $tokenBalance->low_balance_threshold ?? 100,
            ] : null,
            'token_usage' => $tokenUsageSummary,
            'trends' => $trends,
            'available_ai_tiers' => $this->getAvailableAiTiers(),
        ];
    }

    /**
     * Subscribe an org to a plan item.
     */
    public function subscribe(Organization $org, string $category, string $itemKey, int $quantity = 1): OrganizationPlan
    {
        $pricing = PlatformPricing::where('category', $category)
            ->where('item_key', $itemKey)
            ->where('is_active', true)
            ->firstOrFail();

        // User seats are auto-provisioned — if already exists, return existing
        if ($category === 'user_seat') {
            $existing = OrganizationPlan::where('organization_id', $org->id)
                ->where('category', 'user_seat')
                ->where('item_key', $itemKey)
                ->where('status', 'active')
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        // For AI workspace: cancel old plans for the same role when upgrading tier
        if ($category === 'ai_workspace') {
            // Determine role suffix: _admin or _teacher
            $role = str_ends_with($itemKey, '_admin') ? '_admin' : (str_ends_with($itemKey, '_teacher') ? '_teacher' : null);

            if ($role) {
                // Cancel all other active AI workspace plans for this role
                OrganizationPlan::where('organization_id', $org->id)
                    ->where('category', 'ai_workspace')
                    ->where('item_key', '!=', $itemKey)
                    ->where('item_key', 'like', "%{$role}")
                    ->where('status', 'active')
                    ->update([
                        'status'       => 'cancelled',
                        'cancelled_at' => now(),
                    ]);
            }
        }

        $planData = [
            'organization_id' => $org->id,
            'category' => $category,
            'item_key' => $itemKey,
            'status' => 'active',
            'quantity' => $quantity,
            'started_at' => now(),
        ];

        // Set AI action limits from pricing metadata
        if ($category === 'ai_workspace' && $pricing->metadata) {
            $limit = $pricing->metadata['monthly_action_limit'] ?? null;
            $planData['ai_actions_limit'] = $limit;
            $planData['ai_actions_used'] = 0;
            $planData['ai_actions_reset_at'] = now()->addMonth()->startOfMonth();

            // Enable AI features on the org
            $features = $pricing->metadata['features'] ?? [];
            foreach ($features as $feature) {
                $org->setFeature("ai.{$feature}", true);
            }
        }

        return OrganizationPlan::create($planData);
    }

    /**
     * Cancel a plan.
     */
    public function cancelPlan(Organization $org, int $planId): OrganizationPlan
    {
        $plan = OrganizationPlan::where('organization_id', $org->id)
            ->where('id', $planId)
            ->firstOrFail();

        $plan->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        // If AI workspace, disable the features
        if ($plan->category === 'ai_workspace') {
            $pricing = PlatformPricing::where('category', $plan->category)
                ->where('item_key', $plan->item_key)
                ->first();

            if ($pricing && $pricing->metadata) {
                $features = $pricing->metadata['features'] ?? [];

                // Only disable features if no other active plan provides them
                $otherAiPlans = OrganizationPlan::where('organization_id', $org->id)
                    ->where('category', 'ai_workspace')
                    ->where('status', 'active')
                    ->where('id', '!=', $plan->id)
                    ->get();

                $otherFeatures = [];
                foreach ($otherAiPlans as $otherPlan) {
                    $otherPricing = PlatformPricing::where('category', $otherPlan->category)
                        ->where('item_key', $otherPlan->item_key)
                        ->first();
                    if ($otherPricing && $otherPricing->metadata) {
                        $otherFeatures = array_merge($otherFeatures, $otherPricing->metadata['features'] ?? []);
                    }
                }

                foreach ($features as $feature) {
                    if (!in_array($feature, $otherFeatures)) {
                        $org->setFeature("ai.{$feature}", false);
                    }
                }
            }
        }

        return $plan->fresh();
    }

    /**
     * Check if org has access to a specific AI feature.
     */
    public function hasAiFeatureAccess(Organization $org, string $feature): bool
    {
        $aiPlans = OrganizationPlan::where('organization_id', $org->id)
            ->where('category', 'ai_workspace')
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->get();

        if ($aiPlans->isEmpty()) {
            return false;
        }

        foreach ($aiPlans as $plan) {
            $pricing = PlatformPricing::where('category', $plan->category)
                ->where('item_key', $plan->item_key)
                ->first();

            if (!$pricing || !$pricing->metadata) {
                continue;
            }

            $features = $pricing->metadata['features'] ?? [];
            if (in_array($feature, $features)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get AI usage stats for the org (actions used vs limit).
     */
    public function getAiUsageStats(Organization $org): array
    {
        $aiPlans = OrganizationPlan::where('organization_id', $org->id)
            ->where('category', 'ai_workspace')
            ->where('status', 'active')
            ->get();

        if ($aiPlans->isEmpty()) {
            return [
                'has_ai_plan' => false,
                'plans' => [],
                'total_actions_used' => 0,
                'total_actions_limit' => 0,
            ];
        }

        $plans = [];
        $totalUsed = 0;
        $totalLimit = 0;
        $hasUnlimited = false;

        foreach ($aiPlans as $plan) {
            $plan->resetAiActionsIfNeeded();

            $pricing = PlatformPricing::where('category', $plan->category)
                ->where('item_key', $plan->item_key)
                ->first();

            $isUnlimited = $plan->ai_actions_limit === -1;
            if ($isUnlimited) {
                $hasUnlimited = true;
            }

            $plans[] = [
                'id' => $plan->id,
                'item_key' => $plan->item_key,
                'label' => $pricing?->label ?? $plan->item_key,
                'tier' => $pricing?->tier,
                'actions_used' => $plan->ai_actions_used,
                'actions_limit' => $plan->ai_actions_limit,
                'is_unlimited' => $isUnlimited,
                'has_remaining' => $plan->hasAiActionsRemaining(),
                'resets_at' => $plan->ai_actions_reset_at?->toISOString(),
            ];

            $totalUsed += $plan->ai_actions_used;
            if (!$isUnlimited) {
                $totalLimit += $plan->ai_actions_limit ?? 0;
            }
        }

        // Build role-grouped stats for frontend (aiUsage.admin / aiUsage.teacher)
        $usage = $this->getCurrentUsage($org);
        $adminPlan = $aiPlans->first(fn ($p) => str_contains($p->item_key, '_admin'));
        $teacherPlan = $aiPlans->first(fn ($p) => str_contains($p->item_key, '_teacher'));

        $roleStats = [];
        if ($adminPlan) {
            $roleStats['admin'] = [
                'actions_used' => $adminPlan->ai_actions_used,
                'actions_limit' => $adminPlan->ai_actions_limit ?? 0,
                'seats_active' => $adminPlan->quantity ?? 0,
                'seats_total' => $usage['admins'],
            ];
        }
        if ($teacherPlan) {
            $roleStats['teacher'] = [
                'actions_used' => $teacherPlan->ai_actions_used,
                'actions_limit' => $teacherPlan->ai_actions_limit ?? 0,
                'seats_active' => $teacherPlan->quantity ?? 0,
                'seats_total' => $usage['teachers'],
            ];
        }

        return array_merge($roleStats, [
            'has_ai_plan' => true,
            'plans' => $plans,
            'total_actions_used' => $totalUsed,
            'total_actions_limit' => $hasUnlimited ? -1 : $totalLimit,
            'is_unlimited' => $hasUnlimited,
        ]);
    }

    /**
     * Increment AI action counter. Called whenever an AI feature is used.
     * Returns false if limit exceeded.
     */
    public function recordAiAction(Organization $org, string $role = 'admin'): bool
    {
        $suffix = $role === 'teacher' ? '_teacher' : '_admin';

        // Find the best matching active AI plan for this role
        $plan = OrganizationPlan::where('organization_id', $org->id)
            ->where('category', 'ai_workspace')
            ->where('status', 'active')
            ->where('item_key', 'like', "%{$suffix}")
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();

        if (!$plan) {
            return false;
        }

        $plan->resetAiActionsIfNeeded();

        if (!$plan->hasAiActionsRemaining()) {
            return false;
        }

        $plan->incrementAiActions();

        return true;
    }

    /**
     * Get available AI tiers structured for the frontend tier comparison UI.
     */
    public function getAvailableAiTiers(): array
    {
        $aiPricing = PlatformPricing::active()
            ->forCategory('ai_workspace')
            ->orderBy('sort_order')
            ->get();

        // Group by tier
        $tiers = [];
        foreach ($aiPricing as $item) {
            $tier = $item->tier;
            if (!$tier) continue;

            if (!isset($tiers[$tier])) {
                $tiers[$tier] = [
                    'tier' => $tier,
                    'label' => 'AI ' . ucfirst($tier),
                    'description' => $item->description,
                    'admin_price' => 0,
                    'teacher_price' => 0,
                    'admin_action_limit' => 0,
                    'teacher_action_limit' => 0,
                    'features' => [],
                    'feature_labels' => [],
                    'recommended' => $tier === 'professional',
                ];
            }

            $metadata = $item->metadata ?? [];
            $features = $metadata['features'] ?? [];
            $limit = $metadata['monthly_action_limit'] ?? 0;

            if (str_contains($item->item_key, '_admin')) {
                $tiers[$tier]['admin_price'] = (float) $item->price_monthly;
                $tiers[$tier]['admin_action_limit'] = $limit;
            } elseif (str_contains($item->item_key, '_teacher')) {
                $tiers[$tier]['teacher_price'] = (float) $item->price_monthly;
                $tiers[$tier]['teacher_action_limit'] = $limit;
            }

            // Merge features (use the superset)
            foreach ($features as $feature) {
                if (!in_array($feature, $tiers[$tier]['features'])) {
                    $tiers[$tier]['features'][] = $feature;
                }
            }
        }

        // Build feature labels — prefer labels from pricing metadata, fallback to defaults
        $defaultLabels = [
            'ai_chat' => 'AI Chat Assistant',
            'ai_basic_grading' => 'Basic Grading Assist',
            'ai_studio' => 'AI Content Studio',
            'ai_grading' => 'Auto-Grade Subjective Answers',
            'ai_reports' => 'AI Reports & Parent Updates',
            'ai_feedback' => 'Assessment Feedback PDFs',
            'ai_content_gen' => 'Course & Lesson Generator',
            'ai_custom_agents' => 'Custom Background Agents',
            'ai_priority' => 'Priority AI Processing',
        ];

        foreach ($tiers as &$tier) {
            foreach ($tier['features'] as $feature) {
                $tier['feature_labels'][$feature] = $tier['feature_labels'][$feature]
                    ?? $defaultLabels[$feature]
                    ?? ucwords(str_replace('_', ' ', $feature));
            }
        }
        unset($tier); // Break the reference before reusing $tier

        // Also pull feature_labels from pricing metadata if available
        foreach ($aiPricing as $item) {
            $tier = $item->tier;
            if (!$tier || !isset($tiers[$tier])) continue;
            $metaLabels = $item->metadata['feature_labels'] ?? [];
            foreach ($metaLabels as $feature => $label) {
                $tiers[$tier]['feature_labels'][$feature] = $label;
            }
        }

        return array_values($tiers);
    }

    /**
     * Get available pricing for display (grouped by category, with tiers for AI).
     */
    public function getAvailablePricing(): array
    {
        $pricing = PlatformPricing::active()
            ->orderBy('sort_order')
            ->get();

        $grouped = [];

        foreach ($pricing as $item) {
            $category = $item->category;
            if (!isset($grouped[$category])) {
                $grouped[$category] = [
                    'category' => $category,
                    'items' => [],
                ];
            }

            $grouped[$category]['items'][] = [
                'id' => $item->id,
                'item_key' => $item->item_key,
                'label' => $item->label,
                'description' => $item->description,
                'price_monthly' => (float) $item->price_monthly,
                'price_yearly' => $item->price_yearly ? (float) $item->price_yearly : null,
                'tier' => $item->tier,
                'metadata' => $item->metadata,
                'sort_order' => $item->sort_order,
            ];
        }

        return array_values($grouped);
    }

    /**
     * Get usage trend data for charts (last N months of snapshots).
     * Returns structured format: { months, users, cost, ai_actions }
     */
    public function getUsageTrends(Organization $org, int $months = 6): array
    {
        $snapshots = OrganizationUsageSnapshot::where('organization_id', $org->id)
            ->where('period_type', 'daily')
            ->where('period_date', '>=', now()->subMonths($months))
            ->orderBy('period_date')
            ->get();

        if ($snapshots->isEmpty()) {
            return [];
        }

        // Group by month and take the latest snapshot per month
        $byMonth = $snapshots->groupBy(fn ($s) => $s->period_date->format('Y-m'));
        $monthLabels = [];
        $users = [];
        $cost = [];
        $aiActions = [];

        foreach ($byMonth as $yearMonth => $monthSnapshots) {
            $latest = $monthSnapshots->last();
            $metrics = $latest->metrics ?? [];

            $monthLabels[] = $latest->period_date->format('M');
            $users[] = ($metrics['admins'] ?? 0) + ($metrics['teachers'] ?? 0) + ($metrics['children'] ?? 0);
            $cost[] = (float) $latest->calculated_cost;
            $aiActions[] = $metrics['agent_tokens_consumed'] ?? 0;
        }

        return [
            'months' => $monthLabels,
            'users' => $users,
            'cost' => $cost,
            'ai_actions' => $aiActions,
        ];
    }

    /**
     * Capture a daily usage snapshot. Called by scheduled command.
     */
    public function captureSnapshot(Organization $org): OrganizationUsageSnapshot
    {
        $this->ensureUserSeatPlans($org);

        $usage = $this->getCurrentUsage($org);
        $costBreakdown = $this->calculateMonthlyCost($org);

        // Get agent token consumption for today
        $tokensConsumed = AgentTokenTransaction::where('organization_id', $org->id)
            ->whereDate('created_at', today())
            ->where('type', 'consumption')
            ->sum('amount');

        $metrics = array_merge($usage, [
            'agent_tokens_consumed' => abs((int) $tokensConsumed),
            'lessons_delivered' => 0, // Can be enhanced later with actual lesson tracking
            'assessments_completed' => 0, // Can be enhanced later
        ]);

        return OrganizationUsageSnapshot::updateOrCreate(
            [
                'organization_id' => $org->id,
                'period_type' => 'daily',
                'period_date' => today(),
            ],
            [
                'metrics' => $metrics,
                'calculated_cost' => $costBreakdown['total'],
                'created_at' => now(),
            ]
        );
    }
}
