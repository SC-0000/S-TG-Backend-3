<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\OrganizationPlan;
use App\Services\PlanUsageService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPlanAccess
{
    protected PlanUsageService $planService;

    public function __construct(PlanUsageService $planService)
    {
        $this->planService = $planService;
    }

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $orgId = $request->attributes->get('organization_id');
        if (!$orgId) {
            return $next($request);
        }

        $org = Organization::find($orgId);
        if (!$org) {
            return $next($request);
        }

        // Check if org has access to the feature
        if (!$this->planService->hasAiFeatureAccess($org, $feature)) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'status' => 402,
                    'request_id' => $request->attributes->get('request_id'),
                ],
                'errors' => [
                    [
                        'error' => 'plan_required',
                        'feature' => $feature,
                        'message' => 'Upgrade your AI plan to access this feature',
                        'upgrade_url' => '/admin/settings/plans',
                    ],
                ],
            ], 402);
        }

        // Determine user's role in the org
        $user = $request->user();
        $role = 'admin';
        if ($user && $orgId) {
            $pivot = $user->organizations()
                ->where('organizations.id', $orgId)
                ->first();
            if ($pivot) {
                $role = $pivot->pivot->role === 'teacher' ? 'teacher' : 'admin';
            }
        }

        // Check role-specific AI action limit
        $suffix = $role === 'teacher' ? '_teacher' : '_admin';
        $plan = OrganizationPlan::where('organization_id', $org->id)
            ->where('category', 'ai_workspace')
            ->where('status', 'active')
            ->where('item_key', 'like', "%{$suffix}")
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();

        if ($plan) {
            $plan->resetAiActionsIfNeeded();

            if (!$plan->hasAiActionsRemaining()) {
                return response()->json([
                    'data' => null,
                    'meta' => [
                        'status' => 429,
                        'request_id' => $request->attributes->get('request_id'),
                    ],
                    'errors' => [
                        [
                            'error' => 'limit_exceeded',
                            'feature' => $feature,
                            'message' => 'Monthly AI action limit reached',
                            'usage' => [
                                'used' => $plan->ai_actions_used,
                                'limit' => $plan->ai_actions_limit,
                                'role' => $role,
                                'resets_at' => $plan->ai_actions_reset_at?->toISOString(),
                            ],
                        ],
                    ],
                ], 429);
            }
        }

        // Execute the request
        $response = $next($request);

        // Record AI action after successful response (2xx status)
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $this->planService->recordAiAction($org, $role);
        }

        return $response;
    }
}
