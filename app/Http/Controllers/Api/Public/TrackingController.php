<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Api\ApiController;
use App\Models\TrackingLink;
use App\Services\AffiliateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class TrackingController extends ApiController
{
    public function __construct(protected AffiliateService $affiliateService)
    {
    }

    /**
     * Record a funnel event from the frontend (fire-and-forget, lightweight).
     */
    public function event(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ref' => 'required|string|max:32',
            'event' => 'required|string|in:page_view,form_start,form_submit,verified,approved',
            'page' => 'nullable|string|max:500',
        ]);

        try {
            $this->affiliateService->recordEvent(
                $validated['ref'],
                $validated['event'],
                $request,
                $validated['page'] ?? null,
            );
        } catch (\Throwable $e) {
            // Never fail the client — this is analytics, not critical
        }

        return $this->success(null, [], 202);
    }

    public function redirect(Request $request, string $code): RedirectResponse|JsonResponse
    {
        $link = TrackingLink::with('organization')->where('code', $code)->first();

        if (!$link || !$link->isActive()) {
            // Gracefully redirect to frontend home if link not found/expired
            $fallback = rtrim((string) config('app.frontend_url'), '/') ?: '/';
            return redirect()->away($fallback);
        }

        // Record the click + funnel event (wrapped in try-catch to never block the redirect)
        try {
            $this->affiliateService->recordClick($link, $request);
            $this->affiliateService->recordEvent($code, 'click', $request);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to record click', [
                'code' => $code,
                'error' => $e->getMessage(),
            ]);
        }

        // Set tracking cookie
        $cookieDays = 30;
        if ($link->organization) {
            $cookieDays = (int) $link->organization->getSetting('affiliates.cookie_duration_days', 30);
        }

        $cookie = Cookie::make(
            'tg_ref',
            $code,
            $cookieDays * 24 * 60, // minutes
            '/',
            null,
            true,  // secure
            true,  // httpOnly
            false,
            'Lax'  // sameSite
        );

        // Build redirect URL pointing to the frontend domain
        $frontendBase = rtrim((string) config('app.frontend_url'), '/');
        if (!$frontendBase) {
            // Fallback: use org public domain
            $frontendBase = $link->organization?->public_domain ?? '';
            if ($frontendBase && !str_starts_with($frontendBase, 'http')) {
                $frontendBase = 'https://' . $frontendBase;
            }
            $frontendBase = rtrim($frontendBase, '/');
        }

        $destination = $link->destination_path ?? '/applications/create';
        $separator = str_contains($destination, '?') ? '&' : '?';
        $redirectUrl = $frontendBase . $destination . $separator . 'ref=' . $code;

        return redirect()->away($redirectUrl)->withCookie($cookie);
    }
}
