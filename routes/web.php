<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

Route::match(['get', 'post'], '/broadcasting/auth', [BroadcastController::class, 'authenticate'])
    ->middleware(\App\Http\Middleware\AuthBroadcastToken::class)
    ->withoutMiddleware([VerifyCsrfToken::class]);

require base_path('routes/channels.php');

Route::get('/{path?}', function () {
    if (request()->is('api/*')) {
        abort(404);
    }

    $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
    if ($frontendUrl === '') {
        abort(404);
    }

    $path = request()->path();
    $path = $path === '/' ? '' : $path;
    $target = $frontendUrl . '/' . ltrim($path, '/');
    $query = request()->getQueryString();
    if ($query) {
        $target .= '?' . $query;
    }

    return redirect()->away($target, 302);
})->where('path', '.*');
