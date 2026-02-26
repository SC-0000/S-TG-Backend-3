<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthBroadcastToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization', '');
        if (preg_match('/^Bearer\s+(.*)$/i', $header, $matches)) {
            $token = trim($matches[1]);
            if ($token !== '') {
                $accessToken = PersonalAccessToken::findToken($token);
                if ($accessToken && $accessToken->tokenable) {
                    auth()->setUser($accessToken->tokenable);
                    return $next($request);
                }
            }
        }

        abort(403, 'Unauthorized.');
    }
}
