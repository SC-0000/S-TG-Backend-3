<?php

namespace App\Providers;

use App\Models\Assessment;
use App\Models\AssessmentSubmission;
use App\Models\AdminTask;
use App\Models\Lesson;
use App\Observers\AdminTaskObserver;
use App\Observers\AssessmentSubmissionObserver;
use App\Observers\UidObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        Lesson::observe(UidObserver::class);
        Assessment::observe(UidObserver::class);
        AssessmentSubmission::observe(AssessmentSubmissionObserver::class);
        AdminTask::observe(AdminTaskObserver::class);

        RateLimiter::for('api', function (Request $request) {
            $key = $request->user()?->id ?? $request->ip();
            return Limit::perMinute(120)->by($key);
        });
    }
}
