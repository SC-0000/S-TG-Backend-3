<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckSubscriptionFeature
{
    public function handle(Request $request, Closure $next, string $feature)
    {
        if ($request->user()?->hasFeature($feature)) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'status' => 403,
                    'request_id' => $request->attributes->get('request_id'),
                ],
                'errors' => [
                    ['message' => 'Feature not available for this subscription.'],
                ],
            ], 403);
        }

        return redirect()
            ->route('parentportal.index')
            ->with('error', 'This area requires Tutor AI Plus.');
    }
}
