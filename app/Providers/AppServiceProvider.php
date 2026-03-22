<?php

namespace App\Providers;

use App\Models\Assessment;
use App\Models\AssessmentSubmission;
use App\Models\Alert;
use App\Models\AdminTask;
use App\Models\Article;
use App\Models\ContentLesson;
use App\Models\Course;
use App\Models\Faq;
use App\Models\JourneyCategory;
use App\Models\Lesson;
use App\Models\LessonMaterial;
use App\Models\LessonSlide;
use App\Models\MediaAsset;
use App\Models\Milestone;
use App\Models\Module;
use App\Models\Question;
use App\Models\ScheduleAllocation;
use App\Models\Service;
use App\Models\Slide;
use App\Models\Testimonial;
use App\Listeners\BackgroundAgentEventSubscriber;
use App\Models\Application;
use App\Models\Attendance;
use App\Observers\AdminTaskObserver;
use App\Observers\ApplicationObserver;
use App\Observers\AssessmentSubmissionObserver;
use App\Observers\AttendanceObserver;
use App\Observers\AuditLogObserver;
use App\Observers\ContentObserver;
use App\Observers\UidObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
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
        // In local/dev, OpenSSL 3.6 may fail to verify OpenAI's cert chain.
        // Extend the OpenAI client to use a Guzzle client with verify=false.
        if ($this->app->environment('local', 'development', 'testing')) {
            $this->app->extend(\OpenAI\Contracts\ClientContract::class, function ($client) {
                $apiKey = config('openai.api_key');
                $organization = config('openai.organization');

                return \OpenAI::factory()
                    ->withApiKey($apiKey)
                    ->withOrganization($organization)
                    ->withHttpHeader('OpenAI-Beta', 'assistants=v2')
                    ->withHttpClient(new \GuzzleHttp\Client([
                        'timeout' => config('openai.request_timeout', 90),
                        'verify'  => false,
                    ]))
                    ->make();
            });
        }

        Lesson::observe(UidObserver::class);
        Assessment::observe(UidObserver::class);
        AssessmentSubmission::observe(AssessmentSubmissionObserver::class);
        AdminTask::observe(AdminTaskObserver::class);
        Attendance::observe(AttendanceObserver::class);
        Application::observe(ApplicationObserver::class);

        // Background Agent System: content quality observers
        Question::observe(ContentObserver::class);
        Assessment::observe(ContentObserver::class);
        ContentLesson::observe(ContentObserver::class);
        Course::observe(ContentObserver::class);
        Module::observe(ContentObserver::class);

        // ── Audit logging ────────────────────────────────────────────────────
        // Records created / updated / deleted events for all key content models.
        $auditModels = [
            Question::class,
            ContentLesson::class,
            LessonSlide::class,
            Assessment::class,
            AssessmentSubmission::class,
            Course::class,
            Module::class,
            Lesson::class,
            LessonMaterial::class,
            JourneyCategory::class,
            Service::class,
            MediaAsset::class,
            Article::class,
            Faq::class,
            Alert::class,
            Slide::class,
            Testimonial::class,
            Milestone::class,
            ScheduleAllocation::class,
            AdminTask::class,
        ];
        foreach ($auditModels as $model) {
            $model::observe(AuditLogObserver::class);
        }

        // Background Agent event subscriber
        Event::subscribe(BackgroundAgentEventSubscriber::class);

        RateLimiter::for('api', function (Request $request) {
            $key = $request->user()?->id ?? $request->ip();

            // Authenticated users get a higher limit to avoid 429s during normal navigation
            if ($request->user()) {
                $limit = app()->environment(['local', 'development']) ? 600 : 240;
            } else {
                $limit = app()->environment(['local', 'development']) ? 300 : 120;
            }

            return Limit::perMinute($limit)->by($key);
        });
    }
}
