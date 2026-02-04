<?php

use Illuminate\Support\Facades\Route;

Route::view('/app/{path?}', 'app-api')
    ->where('path', '.*')
    ->name('app.api');

// Generic /dashboard route that redirects based on user role
Route::get('/dashboard', function () {
    $user = auth()->user();
    
    if (!$user) {
        return redirect('/login');
    }
    
    return match($user->role) {
        'admin' => redirect()->route('admin.dashboard'),
        'teacher' => redirect()->route('teacher.dashboard'),
        'parent', 'guest_parent' => redirect()->route('portal.assessments.index'),
        'super_admin' => redirect()->route('superadmin.dashboard'),
        default => redirect('/'),
    };
})->middleware('auth')->name('dashboard');

require __DIR__.'/public.php';
require __DIR__.'/admin.php';
require __DIR__.'/teacher.php';
require __DIR__.'/parent.php';

Route::fallback(function () {
    return view('app-api');
});
