<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Symfony\Component\HttpFoundation\Response;

class RedirectIncompleteGuests
{
    /**
     * Handle an incoming request.
     *
     * Redirect guest parents with incomplete onboarding to the complete-profile page.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        
        // Check if user is guest_parent with incomplete onboarding
        if ($user && 
            $user->role === User::ROLE_GUEST_PARENT && 
            !$user->onboarding_complete) {
            
            // Don't redirect if already on complete-profile page (prevent redirect loop)
            if (!$request->is('guest/complete-profile')) {
                return redirect()->route('guest.complete_profile');
            }
        }
        
        return $next($request);
    }
}
