<?php

namespace App\Http\Controllers\Api;

use App\Models\Assessment;
use App\Models\Course;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class SubscriptionCatalogController extends ApiController
{
    public function index(): JsonResponse
    {
        $ttl = (int) config('api.public_cache_ttl', 60);
        $cacheKey = 'public:subscription-plans';

        $payload = $ttl > 0
            ? Cache::remember($cacheKey, $ttl, function () {
                return $this->buildCatalog();
            })
            : $this->buildCatalog();

        return $this->success($payload);
    }

    private function buildCatalog(): array
    {
        $subscriptions = Subscription::all()->map(function ($subscription) {
            $filters = $subscription->content_filters ?? [];
            $yearGroups = $filters['year_groups'] ?? [];
            $features = $subscription->features ?? [];

            $courses = collect([]);
            $hasCourses = (isset($features['courses']) && $features['courses'])
                || (isset($features['year_group_courses']) && $features['year_group_courses']);

            if ($hasCourses) {
                $courses = Course::query()
                    ->when(!empty($yearGroups), function ($query) use ($yearGroups) {
                        $query->whereIn('year_group', $yearGroups);
                    })
                    ->with(['modules', 'journeyCategory'])
                    ->get()
                    ->map(function ($course) {
                        return [
                            'id' => $course->id,
                            'uid' => $course->uid,
                            'title' => $course->title,
                            'description' => $course->description,
                            'thumbnail' => $course->thumbnail,
                            'cover_image' => $course->cover_image,
                            'year_group' => $course->year_group,
                            'category' => $course->category,
                            'journey_category' => $course->journeyCategory ? [
                                'name' => $course->journeyCategory->name,
                                'color' => $course->journeyCategory->color,
                            ] : null,
                            'modules_count' => $course->modules->count(),
                            'total_lessons' => $course->total_lessons,
                            'estimated_duration_minutes' => $course->estimated_duration_minutes,
                        ];
                    });
            }

            $assessments = collect([]);
            $hasAssessments = (isset($features['assessments']) && $features['assessments'])
                || (isset($features['year_group_assessments']) && $features['year_group_assessments']);

            if ($hasAssessments) {
                $assessments = Assessment::query()
                    ->when(!empty($yearGroups), function ($query) use ($yearGroups) {
                        $query->whereIn('year_group', $yearGroups);
                    })
                    ->where('status', 'published')
                    ->where(function ($q) {
                        $q->whereNull('lesson_id')
                            ->orWhereHas('lesson', fn ($l) => $l->whereNull('course_id'));
                    })
                    ->get(['id', 'title', 'description', 'year_group']);
            }

            $hasAI = (isset($features['ai_tutoring']) && $features['ai_tutoring'])
                || (isset($features['ai']) && $features['ai'])
                || (isset($features['ai_analysis']) && $features['ai_analysis']);

            $hasAIAnalysis = isset($features['ai_analysis']) && $features['ai_analysis'];
            $hasEnhancedReports = isset($features['enhanced_reports']) && $features['enhanced_reports'];

            return [
                'id' => $subscription->id,
                'name' => $subscription->name,
                'slug' => $subscription->slug,
                'description' => $subscription->description ?? 'Access all courses and assessments for selected year groups',
                'features' => $subscription->features ?? [],
                'content_filters' => $subscription->content_filters,
                'year_groups' => $yearGroups,
                'has_ai' => $hasAI,
                'has_ai_analysis' => $hasAIAnalysis,
                'has_enhanced_reports' => $hasEnhancedReports,
                'courses' => $courses,
                'assessments' => $assessments,
                'stats' => [
                    'courses_count' => $courses->count(),
                    'assessments_count' => $assessments->count(),
                    'total_lessons' => $courses->sum('total_lessons'),
                ],
            ];
        });

        return ['subscriptions' => $subscriptions];
    }
}
