<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SuperAdminAccess
{
    /**
     * Handle an incoming request.
     *
     * Verify that the authenticated user has platform super admin role.
     * This middleware ensures only users with ROLE_SUPER_ADMIN can access super admin routes.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if user is authenticated
        if (!auth()->check()) {
            abort(401, 'Unauthorized');
        }

        // Check if user has super admin role
        if (!auth()->user()->isSuperAdmin()) {
            abort(403, 'Access denied. Super Admin privileges required.');
        }

        return $next($request);
    }
}
