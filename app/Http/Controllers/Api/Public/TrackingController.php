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

    public function redirect(Request $request, string $code): RedirectResponse|JsonResponse
    {
        $link = TrackingLink::where('code', $code)->first();

        if (!$link || !$link->isActive()) {
            // Gracefully redirect to home if link not found/expired
            return redirect('/');
        }

        // Record the click
        $this->affiliateService->recordClick($link, $request);

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

        // Build redirect URL
        $destination = $link->destination_path ?? '/applications/create';
        $separator = str_contains($destination, '?') ? '&' : '?';
        $redirectUrl = $destination . $separator . 'ref=' . $code;

        return redirect($redirectUrl)->withCookie($cookie);
    }
}
