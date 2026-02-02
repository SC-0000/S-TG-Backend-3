<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\SystemSetting;

class EnsureFeatureEnabled
{
    /**
     * Handle an incoming request.
     *
     * Usage: ->middleware('feature:parent.ai.chatbot')
     */
    public function handle(Request $request, Closure $next, string $featurePath): Response
    {
        $user = $request->user();
        $orgId = $user?->current_organization_id;
        $org = $orgId ? \App\Models\Organization::find($orgId) : null;

        // If no org context, fall back to defaults/overrides
        $enabled = $org
            ? $org->featureEnabled($featurePath, false)
            : (bool) data_get(
                array_replace_recursive(
                    config('features.defaults', []),
                    SystemSetting::getValue('feature_overrides', []),
                    config('features.overrides', [])
                ),
                $featurePath,
                false
            );

        if (!$enabled) {
            abort(404);
        }

        return $next($request);
    }
}
