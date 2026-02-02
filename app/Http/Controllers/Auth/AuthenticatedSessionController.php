<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('@public/Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse|SymfonyResponse
    {
        Log::info('[LoginAttempt] Hit controller', [
    'email' => $request->input('email'),
    'has_csrf' => $request->has('_token'),
    'session_id' => $request->session()->getId(),
    'user_agent' => $request->userAgent(),
]);

        $request->authenticate();
Log::info('[LoginAttempt] Authenticated', [
    'user_id' => $request->user()->id,
    'role' => $request->user()->role,
    'session_id' => $request->session()->getId(),
]);

        $request->session()->regenerate();

        // Determine role-based fallback route after login.
        $user = $request->user();

        if ($user && $user->role === 'super_admin') {
            $fallback = route('superadmin.dashboard', [], false);
        } elseif ($user && $user->role === 'admin') {
            $fallback = route('admin.dashboard', [], false);
        } elseif ($user && $user->role === 'teacher') {
            $fallback = route('teacher.dashboard', [], false);
        } elseif ($user && $user->role === 'parent') {
            $fallback = route('parentportal.index', [], false);
        } elseif ($user && $user->role === 'guest_parent') {
            $fallback = route('portal.assessments.index', [], false);
        } else {
            $fallback = route('dashboard', [], false);
        }

        // For guest_parent accounts we always send them to the assessments portal after login
        // to avoid redirecting them back to parent-only pages which will forward them to the
        // guest onboarding flow.
        if ($user && $user->role === 'guest_parent') {
            // Remove any previously stored intended URL so we don't redirect into a blocked page.
            $request->session()->forget('url.intended');
        }

        // Determine final redirect URL
        $redirectUrl = ($user && $user->role === 'guest_parent') 
            ? $fallback 
            : redirect()->intended($fallback)->getTargetUrl();

        // Use Inertia::location() to force a full page reload after login.
        // This ensures the new session cookie (from session regeneration) is properly
        // applied by the browser before navigating to the authenticated page.
        // This fixes the "double login" issue especially in Safari.
        if ($request->header('X-Inertia')) {
            return Inertia::location($redirectUrl);
        }

        return redirect($redirectUrl);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse|SymfonyResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        if ($request->header('X-Inertia')) {
            // Force a full reload so the browser picks up the fresh CSRF token after logout.
            return Inertia::location('/');
        }

        return redirect('/');
    }
}
