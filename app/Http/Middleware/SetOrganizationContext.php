<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SetOrganizationContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $orgId = null;

        if ($user) {
            $requestedOrg = $request->header('X-Organization-Id') ?? $request->query('organization_id');

            if ($user->isSuperAdmin() && $requestedOrg) {
                $orgId = (int) $requestedOrg;
            } else {
                $orgId = $user->current_organization_id;

                if ($requestedOrg && (int) $requestedOrg !== (int) $orgId) {
                    abort(403, 'Organization scope mismatch.');
                }
            }
        }

        $request->attributes->set('organization_id', $orgId);
        Log::withContext([
            'organization_id' => $orgId,
            'user_id' => $user?->id,
        ]);

        return $next($request);
    }
}
