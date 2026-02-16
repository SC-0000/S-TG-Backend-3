<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class RedirectToFrontend
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->is('api/*') || $request->expectsJson() || $request->wantsJson() || $request->ajax()) {
            return $response;
        }

        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $response;
        }

        if (!method_exists($response, 'getOriginalContent')) {
            return $response;
        }

        $original = $response->getOriginalContent();

        $isInertia = $response->headers->has('X-Inertia') || $original instanceof \Inertia\Response;
        $isView = $original instanceof View;

        if (!$isInertia && !$isView) {
            return $response;
        }

        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        if ($frontendUrl === '') {
            return $response;
        }

        $path = $request->path();
        $path = $path === '/' ? '' : $path;
        $target = $frontendUrl . '/' . ltrim($path, '/');
        $query = $request->getQueryString();
        if ($query) {
            $target .= '?' . $query;
        }

        return redirect()->away($target, 302);
    }
}
