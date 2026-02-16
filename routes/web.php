<?php

use Illuminate\Support\Facades\Route;

Route::get('/{path?}', function () {
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
