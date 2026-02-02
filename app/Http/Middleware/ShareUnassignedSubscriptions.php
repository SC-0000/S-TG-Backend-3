<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ShareUnassignedSubscriptions
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && Auth::user()->role === 'parent') {
            $user = Auth::user();
            
            // DEBUG: Log the query
            \Illuminate\Support\Facades\Log::info('ShareUnassignedSubscriptions: Checking for user', [
                'user_id' => $user->id,
                'user_email' => $user->email,
            ]);
            
            // Find unassigned active subscriptions - use DB query to bypass relationship filter
            $unassigned = \Illuminate\Support\Facades\DB::table('user_subscriptions')
                ->join('subscriptions', 'user_subscriptions.subscription_id', '=', 'subscriptions.id')
                ->where('user_subscriptions.user_id', $user->id)
                ->where('user_subscriptions.status', 'active')
                ->whereNull('user_subscriptions.child_id')
                ->select('subscriptions.id', 'subscriptions.name', 'subscriptions.content_filters')
                ->get()
                ->map(function($sub) {
                    return [
                        'id' => $sub->id,
                        'name' => $sub->name,
                        'content_filters' => json_decode($sub->content_filters, true),
                    ];
                });
            
            // DEBUG: Log what we found
            \Illuminate\Support\Facades\Log::info('ShareUnassignedSubscriptions: Found unassigned', [
                'count' => $unassigned->count(),
                'subscriptions' => $unassigned->toArray(),
            ]);
            
            $childrenData = $user->children->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->child_name,
                'year_group' => $c->year_group,
            ]);
            
            // DEBUG: Log children
            \Illuminate\Support\Facades\Log::info('ShareUnassignedSubscriptions: Children', [
                'count' => $childrenData->count(),
                'children' => $childrenData->toArray(),
            ]);
            
            // Share with ALL Inertia pages
            Inertia::share([
                'unassignedSubscriptions' => $unassigned->isEmpty() ? null : $unassigned,
                'allChildren' => $childrenData,
            ]);
            
            // DEBUG: Log what we're sharing
            \Illuminate\Support\Facades\Log::info('ShareUnassignedSubscriptions: Sharing via Inertia', [
                'unassigned_is_null' => $unassigned->isEmpty(),
                'shared_data' => [
                    'unassignedSubscriptions' => $unassigned->isEmpty() ? null : $unassigned->toArray(),
                    'allChildren' => $childrenData->toArray(),
                ],
            ]);
        }
        
        return $next($request);
    }
}
