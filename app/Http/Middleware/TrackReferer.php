<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackReferer
{
    public function handle(Request $request, Closure $next): Response
    {
        $refCode = $request->query('ref');

        if ($refCode) {
            $request->attributes->set('tracking_code', $refCode);
        } elseif ($request->cookie('tg_ref')) {
            $request->attributes->set('tracking_code', $request->cookie('tg_ref'));
        }

        return $next($request);
    }
}
