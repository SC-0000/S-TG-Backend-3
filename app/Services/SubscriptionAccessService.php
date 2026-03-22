<?php

namespace App\Services;

use App\Models\Access;
use App\Models\Assessment;
use App\Models\Child;
use App\Models\Course;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class SubscriptionAccessService
{
    /**
     * Grant access to a child for a subscription, handling all content filter types.
     */
    public function grantAccess(User $user, Subscription $subscription, Child $child): ?Access
    {
        $contentFilters = $subscription->content_filters ?? [];
        $filterType = $contentFilters['type'] ?? 'year_group';

        return match ($filterType) {
            'year_group' => $this->grantYearGroupAccess($user, $subscription, $child),
            'custom'     => $this->grantCustomAccess($subscription, $child),
            'all'        => $this->grantAllOrgAccess($subscription, $child),
            default      => $this->grantYearGroupAccess($user, $subscription, $child),
        };
    }

    /**
     * Grant year-group based access (existing logic from YearGroupSubscriptionService).
     */
    protected function grantYearGroupAccess(User $user, Subscription $subscription, Child $child): ?Access
    {
        $contentFilters = $subscription->content_filters ?? [];
        $subscriptionYearGroups = $contentFilters['year_groups'] ?? [];

        if (empty($subscriptionYearGroups)) {
            Log::warning('Subscription has no year_groups configured', [
                'subscription_id' => $subscription->id,
            ]);
            return null;
        }

        $existingAccess = $this->findExistingAccess($child, $subscription);

        if ($existingAccess) {
            return $this->updateYearGroupAccess($existingAccess, $child, $subscription);
        }

        return $this->createYearGroupAccess($child, $subscription);
    }

    /**
     * Grant access based on explicit content IDs in content_filters.
     */
    protected function grantCustomAccess(Subscription $subscription, Child $child): ?Access
    {
        $filters = $subscription->content_filters ?? [];
        $courseIds = $filters['course_ids'] ?? [];
        $lessonIds = $filters['lesson_ids'] ?? [];
        $assessmentIds = $filters['assessment_ids'] ?? [];

        // If course IDs are specified, also resolve their content
        $allContentLessonIds = collect($lessonIds);
        $allLiveSessionIds = collect();
        $allAssessmentIds = collect($assessmentIds);
        $allModuleIds = collect();

        if (!empty($courseIds)) {
            $courses = Course::whereIn('id', $courseIds)->get();
            foreach ($courses as $course) {
                $allContentLessonIds = $allContentLessonIds->merge($course->getAllLessonIds());
                $allLiveSessionIds = $allLiveSessionIds->merge($course->getAllLiveSessionIds());
                $allAssessmentIds = $allAssessmentIds->merge($course->getAllAssessmentIds());
                $allModuleIds = $allModuleIds->merge($course->getAllModuleIds());
            }
        }

        $existingAccess = $this->findExistingAccess($child, $subscription);

        $accessData = [
            'course_ids' => $courseIds,
            'lesson_ids' => [],
            'assessment_ids' => $allAssessmentIds->unique()->values()->toArray(),
            'module_ids' => $allModuleIds->unique()->values()->toArray(),
            'access' => true,
            'payment_status' => 'paid',
            'metadata' => [
                'granted_via' => 'subscription',
                'subscription_id' => $subscription->id,
                'subscription_name' => $subscription->name,
                'owner_type' => $subscription->owner_type,
                'content_lesson_ids' => $allContentLessonIds->unique()->values()->toArray(),
                'live_lesson_session_ids' => $allLiveSessionIds->unique()->values()->toArray(),
                'granted_at' => now()->toIso8601String(),
            ],
        ];

        if ($existingAccess) {
            $existingAccess->update($accessData);
            Log::info('Custom subscription access updated', [
                'access_id' => $existingAccess->id,
                'child_id' => $child->id,
                'subscription_id' => $subscription->id,
            ]);
            return $existingAccess;
        }

        $access = Access::create(array_merge($accessData, [
            'child_id' => $child->id,
            'lesson_id' => null,
            'content_lesson_id' => null,
            'assessment_id' => null,
            'transaction_id' => '',
            'invoice_id' => '',
            'purchase_date' => now(),
        ]));

        Log::info('Custom subscription access granted', [
            'child_id' => $child->id,
            'subscription_id' => $subscription->id,
            'courses_count' => count($courseIds),
        ]);

        return $access;
    }

    /**
     * Grant access to all content for the subscription's organization.
     */
    protected function grantAllOrgAccess(Subscription $subscription, Child $child): ?Access
    {
        $orgId = $subscription->organization_id;

        if (!$orgId) {
            Log::warning('Cannot grant all-org access: no organization_id', [
                'subscription_id' => $subscription->id,
            ]);
            return null;
        }

        // Get all courses for this org
        $courses = Course::where('organization_id', $orgId)->get();
        $courseIds = $courses->pluck('id')->toArray();

        $allContentLessonIds = collect();
        $allLiveSessionIds = collect();
        $allAssessmentIds = collect();
        $allModuleIds = collect();

        foreach ($courses as $course) {
            $allContentLessonIds = $allContentLessonIds->merge($course->getAllLessonIds());
            $allLiveSessionIds = $allLiveSessionIds->merge($course->getAllLiveSessionIds());
            $allAssessmentIds = $allAssessmentIds->merge($course->getAllAssessmentIds());
            $allModuleIds = $allModuleIds->merge($course->getAllModuleIds());
        }

        // Also get standalone assessments for the org
        $standaloneAssessments = Assessment::where('organization_id', $orgId)->pluck('id');
        $allAssessmentIds = $allAssessmentIds->merge($standaloneAssessments);

        $existingAccess = $this->findExistingAccess($child, $subscription);

        $accessData = [
            'course_ids' => $courseIds,
            'lesson_ids' => [],
            'assessment_ids' => $allAssessmentIds->unique()->values()->toArray(),
            'module_ids' => $allModuleIds->unique()->values()->toArray(),
            'access' => true,
            'payment_status' => 'paid',
            'metadata' => [
                'granted_via' => 'subscription',
                'subscription_id' => $subscription->id,
                'subscription_name' => $subscription->name,
                'owner_type' => $subscription->owner_type,
                'content_lesson_ids' => $allContentLessonIds->unique()->values()->toArray(),
                'live_lesson_session_ids' => $allLiveSessionIds->unique()->values()->toArray(),
                'granted_at' => now()->toIso8601String(),
            ],
        ];

        if ($existingAccess) {
            $existingAccess->update($accessData);
            return $existingAccess;
        }

        return Access::create(array_merge($accessData, [
            'child_id' => $child->id,
            'lesson_id' => null,
            'content_lesson_id' => null,
            'assessment_id' => null,
            'transaction_id' => '',
            'invoice_id' => '',
            'purchase_date' => now(),
        ]));
    }

    // ── Year Group helpers (preserved from YearGroupSubscriptionService) ──

    protected function createYearGroupAccess(Child $child, Subscription $subscription): Access
    {
        $childYearGroup = $child->year_group;
        $courses = Course::where('year_group', $childYearGroup)->get();

        $courseIds = $courses->pluck('id')->toArray();
        $allContentLessonIds = collect();
        $allLiveSessionIds = collect();
        $allAssessmentIds = collect();
        $allModuleIds = collect();

        foreach ($courses as $course) {
            $allContentLessonIds = $allContentLessonIds->merge($course->getAllLessonIds());
            $allLiveSessionIds = $allLiveSessionIds->merge($course->getAllLiveSessionIds());
            $allAssessmentIds = $allAssessmentIds->merge($course->getAllAssessmentIds());
            $allModuleIds = $allModuleIds->merge($course->getAllModuleIds());
        }

        $standaloneAssessments = Assessment::where('year_group', $childYearGroup)->pluck('id');
        $allAssessmentIds = $allAssessmentIds->merge($standaloneAssessments);

        $contentLessonIds = $allContentLessonIds->unique()->values()->toArray();
        $liveSessionIds = $allLiveSessionIds->unique()->values()->toArray();
        $assessmentIds = $allAssessmentIds->unique()->values()->toArray();
        $moduleIds = $allModuleIds->unique()->values()->toArray();

        $access = Access::create([
            'child_id' => $child->id,
            'course_ids' => $courseIds,
            'lesson_id' => null,
            'lesson_ids' => [],
            'content_lesson_id' => null,
            'assessment_id' => null,
            'assessment_ids' => $assessmentIds,
            'module_ids' => $moduleIds,
            'transaction_id' => '',
            'invoice_id' => '',
            'access' => true,
            'purchase_date' => now(),
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

        Log::info('Year group subscription access granted', [
            'child_id' => $child->id,
            'child_year_group' => $child->year_group,
            'subscription_name' => $subscription->name,
            'courses_count' => count($courseIds),
        ]);

        return $access;
    }

    protected function updateYearGroupAccess(Access $access, Child $child, Subscription $subscription): Access
    {
        $childYearGroup = $child->year_group;
        $courses = Course::where('year_group', $childYearGroup)->get();

        $courseIds = $courses->pluck('id')->toArray();
        $allContentLessonIds = collect();
        $allLiveSessionIds = collect();
        $allAssessmentIds = collect();
        $allModuleIds = collect();

        foreach ($courses as $course) {
            $allContentLessonIds = $allContentLessonIds->merge($course->getAllLessonIds());
            $allLiveSessionIds = $allLiveSessionIds->merge($course->getAllLiveSessionIds());
            $allAssessmentIds = $allAssessmentIds->merge($course->getAllAssessmentIds());
            $allModuleIds = $allModuleIds->merge($course->getAllModuleIds());
        }

        $standaloneAssessments = Assessment::where('year_group', $childYearGroup)->pluck('id');
        $allAssessmentIds = $allAssessmentIds->merge($standaloneAssessments);

        $access->update([
            'course_ids' => $courseIds,
            'assessment_ids' => $allAssessmentIds->unique()->values()->toArray(),
            'module_ids' => $allModuleIds->unique()->values()->toArray(),
            'access' => true,
            'payment_status' => 'paid',
            'metadata' => [
                'granted_via' => 'year_group_subscription',
                'subscription_id' => $subscription->id,
                'subscription_name' => $subscription->name,
                'year_group' => $child->year_group,
                'content_lesson_ids' => $allContentLessonIds->unique()->values()->toArray(),
                'live_lesson_session_ids' => $allLiveSessionIds->unique()->values()->toArray(),
                'granted_at' => now()->toIso8601String(),
            ],
        ]);

        Log::info('Year group subscription access updated', [
            'access_id' => $access->id,
            'child_id' => $child->id,
        ]);

        return $access;
    }

    // ── Shared helpers ──

    protected function findExistingAccess(Child $child, Subscription $subscription): ?Access
    {
        return Access::where('child_id', $child->id)
            ->where(function ($q) use ($subscription) {
                $q->whereJsonContains('metadata->subscription_id', $subscription->id)
                  ->orWhereJsonContains('metadata->granted_via', 'year_group_subscription');
            })
            ->first();
    }

    /**
     * Revoke subscription access for a child.
     * If subscriptionId is given, only revoke for that subscription.
     */
    public function revokeAccess(Child $child, ?int $subscriptionId = null): bool
    {
        $query = Access::where('child_id', $child->id);

        if ($subscriptionId) {
            $query->whereJsonContains('metadata->subscription_id', $subscriptionId);
        } else {
            $query->where(function ($q) {
                $q->whereJsonContains('metadata->granted_via', 'year_group_subscription')
                  ->orWhereJsonContains('metadata->granted_via', 'subscription');
            });
        }

        $deleted = $query->delete();

        if ($deleted > 0) {
            Log::info('Subscription access revoked', [
                'child_id' => $child->id,
                'subscription_id' => $subscriptionId,
                'records_deleted' => $deleted,
            ]);
        }

        return $deleted > 0;
    }

    /**
     * Check if a child has access to specific content via subscription.
     */
    public function hasAccessTo(Child $child, string $contentType, int $contentId): bool
    {
        $access = Access::where('child_id', $child->id)
            ->where('access', true)
            ->where(function ($q) {
                $q->whereJsonContains('metadata->granted_via', 'year_group_subscription')
                  ->orWhereJsonContains('metadata->granted_via', 'subscription');
            })
            ->first();

        if (!$access) {
            return false;
        }

        return match ($contentType) {
            'course' => in_array($contentId, $access->course_ids ?? []),
            'content_lesson' => in_array($contentId, $access->metadata['content_lesson_ids'] ?? []),
            'live_session' => in_array($contentId, $access->metadata['live_lesson_session_ids'] ?? []),
            'assessment' => in_array($contentId, $access->assessment_ids ?? []),
            'module' => in_array($contentId, $access->module_ids ?? []),
            default => false,
        };
    }

    /**
     * Get all children eligible for the user's active subscriptions.
     */
    public function getEligibleChildren(User $user)
    {
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

        $allowedYearGroups = $activeYearGroupSubs->flatMap(function ($sub) {
            return $sub->content_filters['year_groups'] ?? [];
        })->unique();

        return $user->children->filter(function ($child) use ($allowedYearGroups) {
            return $allowedYearGroups->contains($child->year_group);
        });
    }
}
