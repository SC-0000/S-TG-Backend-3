<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\Course;
use App\Models\Assessment;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log;

class SubscriptionCatalogController extends Controller
{
    public function index()
    {
        $subscriptions = Subscription::all()->map(function ($subscription) {
            // Get year groups from content_filters
            $filters = $subscription->content_filters ?? [];
            $yearGroups = $filters['year_groups'] ?? [];
            $features = $subscription->features ?? [];
            
            // Keep year groups as-is (no conversion) to match database format
            // Database stores year_group as strings like 'Grade 5', not integers
            
            // Only fetch courses if subscription includes "courses" OR "year_group_courses" feature
            $courses = collect([]);
            $hasCourses = (isset($features['courses']) && $features['courses']) 
                       || (isset($features['year_group_courses']) && $features['year_group_courses']);
            
            if ($hasCourses) {
                $courses = Course::query()
                    ->when(!empty($yearGroups), function ($query) use ($yearGroups) {
                        $query->whereIn('year_group', $yearGroups);
                    })
                    // ->where('status', 'published')
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
            
            // Only fetch assessments if subscription includes "assessments" OR "year_group_assessments" feature
            $assessments = collect([]);
            $hasAssessments = (isset($features['assessments']) && $features['assessments'])
                           || (isset($features['year_group_assessments']) && $features['year_group_assessments']);
            
            if ($hasAssessments) {
                $assessments = Assessment::query()
                    ->when(!empty($yearGroups), function ($query) use ($yearGroups) {
                        $query->whereIn('year_group', $yearGroups);
                    })
                    ->where('status', "published")
                    ->where(function($q) {
                        $q->whereNull('lesson_id')
                          ->orWhereHas('lesson', fn($l) => $l->whereNull('course_id'));
                    })
                    ->get(['id', 'title', 'description', 'year_group']);
            }
            
            // Check for individual AI features
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
        Log::info('Fetched Subscriptions:', $subscriptions->toArray());
        return Inertia::render('@public/Subscriptions/Catalog', [
            'subscriptions' => $subscriptions,
        ]);
    }
}
