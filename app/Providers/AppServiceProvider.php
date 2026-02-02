<?php

namespace App\Providers;

use App\Models\Assessment;
use App\Models\AssessmentSubmission;
use App\Models\Lesson;
use App\Observers\AssessmentSubmissionObserver;
use App\Observers\UidObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
        Lesson::observe(UidObserver::class);
        Assessment::observe(UidObserver::class);
        AssessmentSubmission::observe(AssessmentSubmissionObserver::class);

        RateLimiter::for('api', function (Request $request) {
            $key = $request->user()?->id ?? $request->ip();
            return Limit::perMinute(120)->by($key);
        });
    }
}
