<?php

namespace App\Http\Middleware;

use App\Services\AffiliateService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AffiliateAuth
{
    public function __construct(protected AffiliateService $affiliateService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $affiliate = $this->affiliateService->resolveSession($token);

        if (!$affiliate || $affiliate->status !== 'active') {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Check org context matches if set (prevents cross-org access)
        $requestOrgId = $request->attributes->get('organization_id');
        if ($requestOrgId && (int) $affiliate->organization_id !== (int) $requestOrgId) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $request->attributes->set('affiliate', $affiliate);
        $request->attributes->set('affiliate_session_token', $token);
        // Set org context from affiliate so downstream code works
        if (!$requestOrgId) {
            $request->attributes->set('organization_id', $affiliate->organization_id);
        }

        return $next($request);
    }
}
