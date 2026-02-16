<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        if (!auth()->check()) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'data' => null,
                    'meta' => [
                        'status' => 401,
                        'request_id' => $request->attributes->get('request_id'),
                    ],
                    'errors' => [
                        ['message' => 'Unauthenticated.'],
                    ],
                ], 401);
            }

            $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
            if ($frontendUrl !== '') {
                return redirect()->away($frontendUrl . '/login');
            }
            return redirect()->route('login');
        }
        
        // Check if user has any of the allowed roles
        foreach ($roles as $role) {
            if (auth()->user()->role === $role) {
                return $next($request);
            }
        }
        
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'status' => 403,
                    'request_id' => $request->attributes->get('request_id'),
                ],
                'errors' => [
                    ['message' => 'Unauthorized access.'],
                ],
            ], 403);
        }

        abort(403, 'Unauthorized access');
    }
}
