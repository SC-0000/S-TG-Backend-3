<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Organization;
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
                if (! $orgId) {
                    $orgId = $user->children()
                        ->whereNotNull('organization_id')
                        ->value('organization_id');
                }

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

        if ($orgId) {
            $org = Organization::find($orgId);
            if ($org) {
                $openaiKey = $org->getApiKey('openai');
                if ($openaiKey) {
                    config(['openai.api_key' => $openaiKey]);
                    config(['openai.connections.main.key' => $openaiKey]);
                }
            }
        }

        return $next($request);
    }
}
