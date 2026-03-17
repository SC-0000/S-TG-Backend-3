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

        $request->attributes->set('affiliate', $affiliate);
        $request->attributes->set('affiliate_session_token', $token);

        return $next($request);
    }
}
