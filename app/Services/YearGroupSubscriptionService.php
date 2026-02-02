<?php

namespace App\Services;

use App\Models\Access;
use App\Models\Assessment;
use App\Models\Child;
use App\Models\Course;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class YearGroupSubscriptionService
{
    /**
     * Grant year-group based access to a child for a subscription
     * 
     * @param User $user Parent user
     * @param Subscription $subscription The subscription plan
     * @param Child $child The child to grant access to
     * @return Access|null The created access record or null if no match
     */
    public function grantAccess(User $user, Subscription $subscription, Child $child): ?Access
    {
        // Get the content filters from subscription
        $contentFilters = $subscription->content_filters ?? [];
        
        // Validate this is a year_group subscription
        if (($contentFilters['type'] ?? null) !== 'year_group') {
            Log::warning('Subscription is not a year_group subscription', [
                'subscription_id' => $subscription->id,
                'subscription_name' => $subscription->name,
            ]);
            return null;
        }
        
        // Get allowed year groups from subscription (NOT from child)
        $subscriptionYearGroups = $contentFilters['year_groups'] ?? [];
        
        if (empty($subscriptionYearGroups)) {
            Log::warning('Subscription has no year_groups configured', [
                'subscription_id' => $subscription->id,
                'subscription_name' => $subscription->name,
            ]);
            return null;
        }
        
        Log::info('Granting subscription access based on child year_group', [
            'child_id' => $child->id,
            'child_year_group' => $child->year_group,
            'subscription_name' => $subscription->name,
        ]);
        
        // Check if access already exists (using metadata)
        $existingAccess = Access::where('child_id', $child->id)
            ->whereJsonContains('metadata->granted_via', 'year_group_subscription')
            ->first();
            
        if ($existingAccess) {
            Log::info('Access already exists for child, updating', [
                'child_id' => $child->id,
                'access_id' => $existingAccess->id,
            ]);
            
            // Update existing access
            return $this->updateAccess($existingAccess, $child, $subscription);
        }
        
        // Create new access
        return $this->createAccess($child, $subscription);
    }
    
    /**
     * Create a new access record for a child
     * 
     * @param Child $child
     * @param Subscription $subscription
     * @return Access
     */
    protected function createAccess(Child $child, Subscription $subscription): Access
    {
        // Use CHILD's year group (not subscription's year groups)
        $childYearGroup = $child->year_group;
        
        // Get all courses for child's year group
        $courses = Course::where('year_group', $childYearGroup)->get();
        
        $courseIds = $courses->pluck('id')->toArray();
        $allContentLessonIds = collect();
        $allLiveSessionIds = collect();
        $allAssessmentIds = collect();
        $allModuleIds = collect();
        
        // Collect all content from courses
        foreach ($courses as $course) {
            // Use Course model helper methods
            $allContentLessonIds = $allContentLessonIds->merge($course->getAllLessonIds());
            $allLiveSessionIds = $allLiveSessionIds->merge($course->getAllLiveSessionIds());
            $allAssessmentIds = $allAssessmentIds->merge($course->getAllAssessmentIds());
            $allModuleIds = $allModuleIds->merge($course->getAllModuleIds());
        }
        
        // Get all assessments for child's year group
        // (assessments table doesn't have course_id column, so we get all assessments)
        $standaloneAssessments = Assessment::where('year_group', $childYearGroup)
            ->pluck('id');
        
        $allAssessmentIds = $allAssessmentIds->merge($standaloneAssessments);
        
        // Remove duplicates
        $contentLessonIds = $allContentLessonIds->unique()->values()->toArray();
        $liveSessionIds = $allLiveSessionIds->unique()->values()->toArray();
        $assessmentIds = $allAssessmentIds->unique()->values()->toArray();
        $moduleIds = $allModuleIds->unique()->values()->toArray();
        
        // Create access record following CourseAccessService pattern
        $access = Access::create([
            'child_id' => $child->id,
            
            // Top-level fields
            'course_ids' => $courseIds,
            'lesson_id' => null,
            'lesson_ids' => [],
            'content_lesson_id' => null,
            'assessment_id' => null,
            'assessment_ids' => $assessmentIds,
            'module_ids' => $moduleIds,
            
            // Subscription-based access doesn't have transaction/invoice, use empty strings
            'transaction_id' => '',
            'invoice_id' => '',
            'access' => true,
            'purchase_date' => now(),
            'payment_status' => 'paid',
            
            // Metadata
            'metadata' => [
                'granted_via' => 'year_group_subscription',
                'subscription_id' => $subscription->id,
                'subscription_name' => $subscription->name,
                'year_group' => $child->year_group,
                'content_lesson_ids' => $contentLessonIds,
                'live_lesson_session_ids' => $liveSessionIds,
                'granted_at' => now()->toIso8601String(),
            ],
        ]);
        
        Log::info('Year group subscription access granted', [
            'child_id' => $child->id,
            'child_year_group' => $child->year_group,
            'subscription_name' => $subscription->name,
            'courses_count' => count($courseIds),
            'content_lessons_count' => count($contentLessonIds),
            'assessments_count' => count($assessmentIds),
        ]);
        
        return $access;
    }
    
    /**
     * Update an existing access record
     * 
     * @param Access $access
     * @param Child $child
     * @param Subscription $subscription
     * @return Access
     */
    protected function updateAccess(Access $access, Child $child, Subscription $subscription): Access
    {
        // Use CHILD's year group (not subscription's year groups)
        $childYearGroup = $child->year_group;
        
        // Get all courses for child's year group
        $courses = Course::where('year_group', $childYearGroup)->get();
        
        $courseIds = $courses->pluck('id')->toArray();
        $allContentLessonIds = collect();
        $allLiveSessionIds = collect();
        $allAssessmentIds = collect();
        $allModuleIds = collect();
        
        // Collect all content from courses
        foreach ($courses as $course) {
            $allContentLessonIds = $allContentLessonIds->merge($course->getAllLessonIds());
            $allLiveSessionIds = $allLiveSessionIds->merge($course->getAllLiveSessionIds());
            $allAssessmentIds = $allAssessmentIds->merge($course->getAllAssessmentIds());
            $allModuleIds = $allModuleIds->merge($course->getAllModuleIds());
        }
        
        // Get all assessments for child's year group
        // (assessments table doesn't have course_id column, so we get all assessments)
        $standaloneAssessments = Assessment::where('year_group', $childYearGroup)
            ->pluck('id');
        
        $allAssessmentIds = $allAssessmentIds->merge($standaloneAssessments);
        
        // Remove duplicates
        $contentLessonIds = $allContentLessonIds->unique()->values()->toArray();
        $liveSessionIds = $allLiveSessionIds->unique()->values()->toArray();
        $assessmentIds = $allAssessmentIds->unique()->values()->toArray();
        $moduleIds = $allModuleIds->unique()->values()->toArray();
        
        // Update access record
        $access->update([
            'course_ids' => $courseIds,
            'assessment_ids' => $assessmentIds,
            'module_ids' => $moduleIds,
            'access' => true,
            'payment_status' => 'paid',
            'metadata' => [
                'granted_via' => 'year_group_subscription',
                'subscription_id' => $subscription->id,
                'subscription_name' => $subscription->name,
                'year_group' => $child->year_group,
                'content_lesson_ids' => $contentLessonIds,
                'live_lesson_session_ids' => $liveSessionIds,
                'granted_at' => now()->toIso8601String(),
            ],
        ]);
        
        Log::info('Year group subscription access updated', [
            'access_id' => $access->id,
            'child_id' => $child->id,
            'courses_count' => count($courseIds),
        ]);
        
        return $access;
    }
    
    /**
     * Revoke year-group subscription access for a child
     * 
     * @param Child $child
     * @return bool
     */
    public function revokeAccess(Child $child): bool
    {
        $deleted = Access::where('child_id', $child->id)
            ->whereJsonContains('metadata->granted_via', 'year_group_subscription')
            ->delete();
        
        if ($deleted > 0) {
            Log::info('Year group subscription access revoked', [
                'child_id' => $child->id,
                'records_deleted' => $deleted,
            ]);
        }
        
        return $deleted > 0;
    }
    
    /**
     * Check if a child has access to specific content via year group subscription
     * 
     * @param Child $child
     * @param string $contentType ('course', 'content_lesson', 'assessment', 'live_session')
     * @param int $contentId
     * @return bool
     */
    public function hasAccessTo(Child $child, string $contentType, int $contentId): bool
    {
        $access = Access::where('child_id', $child->id)
            ->where('access', true)
            ->whereJsonContains('metadata->granted_via', 'year_group_subscription')
            ->first();
        
        if (!$access) {
            return false;
        }
        
        // Check based on content type
        switch ($contentType) {
            case 'course':
                return in_array($contentId, $access->course_ids ?? []);
                
            case 'content_lesson':
                $contentLessonIds = $access->metadata['content_lesson_ids'] ?? [];
                return in_array($contentId, $contentLessonIds);
                
            case 'live_session':
                $liveSessionIds = $access->metadata['live_lesson_session_ids'] ?? [];
                return in_array($contentId, $liveSessionIds);
                
            case 'assessment':
                return in_array($contentId, $access->assessment_ids ?? []);
                
            case 'module':
                return in_array($contentId, $access->module_ids ?? []);
                
            default:
                return false;
        }
    }
    
    /**
     * Get all children that should have access based on user's active subscriptions
     * 
     * @param User $user
     * @return \Illuminate\Support\Collection
     */
    public function getEligibleChildren(User $user)
    {
        // Get user's active year-group subscriptions
        $activeYearGroupSubs = $user->subscriptions()
            ->wherePivot('status', 'active')
            ->get()
            ->filter(function ($sub) {
                $filters = $sub->content_filters ?? [];
                return ($filters['type'] ?? null) === 'year_group';
            });
        
        if ($activeYearGroupSubs->isEmpty()) {
            return collect();
        }
        
        // Collect all allowed year groups
        $allowedYearGroups = $activeYearGroupSubs->flatMap(function ($sub) {
            return $sub->content_filters['year_groups'] ?? [];
        })->unique();
        
        // Return children whose year group matches
        return $user->children->filter(function ($child) use ($allowedYearGroups) {
            return $allowedYearGroups->contains($child->year_group);
        });
    }
}
