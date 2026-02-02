<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\Assessment;
use App\Models\AssessmentSubmission;
use App\Models\Child;
use App\Models\ContentLesson;
use App\Models\Course;
use App\Models\Journey;
use App\Models\LessonProgress;
use App\Models\Product;
use App\Models\LiveLessonSession;
use App\Models\Service;
use App\Models\Slide;
use App\Models\Testimonial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\FacadesLog;
use Inertia\Inertia;

class PortalController extends Controller
{
    public function index(Request $request)
    {
        // 1) Fetch all slides and approved testimonials
        $userId = auth()->id();
        $slides = Slide::all();
        $hasUnpaidDue = false;

        $testimonials = Testimonial::where('Status', 'approved')
                                   ->orderBy('DisplayOrder')
                                   ->get();

        // --- Billing: Grant/revive subscription access if active subscription found ---
        $user = Auth::user();
         
        if ($user && $user->billing_customer_id) {
            $billingService = app(\App\Services\BillingService::class);
            $subsResp = $billingService->getSubscriptions();
           

            // New API call to get customer details by ID
            $customerResp = $billingService->getCustomerById($user->billing_customer_id);
            $hasUnpaidDue = false;
            if ($customerResp && isset($customerResp['data']['invoices'])) {
                foreach ($customerResp['data']['invoices'] as $invoice) {
                    Log::info('PortalController: checking invoice', ['invoice' => $invoice]);
                    if (in_array($invoice['status'], ['open', 'draft']) || $invoice['amount_due'] > 0) {
                        $hasUnpaidDue = true;
                        break;
                    }
                }
            }
           

            if ($subsResp && isset($subsResp['data']['data'])) {
                $subsData = $subsResp['data']['data'];
                Log::info('PortalController: subscriptions data array', ['subsData' => $subsData]);
                $activeSubs = collect($subsData)
                    ->where('customer_id', $user->billing_customer_id)
                    ->whereIn('status', ['active', 'paid']);
                Log::info('PortalController: filtered activeSubs', ['activeSubs' => $activeSubs]);
                // Grant/revive access for all active remote subscriptions
                $activeLocalSubscriptionIds = [];
                foreach ($activeSubs as $remoteSub) {
                    // Try to match by subscription name or plan name
                    $subscriptionName = $remoteSub['name'] ?? ($remoteSub['plan_name'] ?? null);
                    Log::info('PortalController: checking subscriptionName', ['subscriptionName' => $subscriptionName]);
                    if ($subscriptionName) {
                        $subscription = \App\Models\Subscription::where('name', $subscriptionName)->first();
                        Log::info('PortalController: found local subscription', ['subscription' => $subscription]);
                        if ($subscription) {
                            $activeLocalSubscriptionIds[] = $subscription->id;
                            // Check if user already has this subscription (active or inactive)
                            $existing = $user->subscriptions()
                                ->where('subscriptions.id', $subscription->id)
                                ->withPivot(['status', 'starts_at', 'ends_at'])
                                ->first();
                            Log::info('PortalController: user existing subscription', ['existing' => $existing]);

                            $now = now();
                            $endsAt = $now->copy()->addDays(30);

                            if ($existing) {
                                // If denied, revive; if already active, extend
                                if ($existing->pivot->status !== 'active' || $existing->pivot->ends_at < $now) {
                                    Log::info('PortalController: updating existing pivot', ['subscription_id' => $subscription->id]);
                                    $user->subscriptions()->updateExistingPivot(
                                        $subscription->id,
                                        [
                                            'status' => 'active',
                                            'starts_at' => $now,
                                            'ends_at' => $endsAt,
                                        ]
                                    );
                                } else {
                                    Log::info('PortalController: subscription already active and valid', ['subscription_id' => $subscription->id]);
                                }
                            } else {
                                // Create new subscription
                                Log::info('PortalController: attaching new subscription', ['subscription_id' => $subscription->id]);
                                $user->subscriptions()->attach(
                                    $subscription->id,
                                    [
                                        'status' => 'active',
                                        'starts_at' => $now,
                                        'ends_at' => $endsAt,
                                    ]
                                );
                            }
                            
                            // NEW: Grant year-group subscription access if applicable
                            $contentFilters = $subscription->content_filters ?? [];
                            if (($contentFilters['type'] ?? null) === 'year_group') {
                                $yearGroupService = app(\App\Services\YearGroupSubscriptionService::class);
                                
                                // UPDATED: Only grant access if child_id is assigned in pivot
                                $assignedChildId = $existing ? $existing->pivot->child_id : null;
                                
                                if ($assignedChildId) {
                                    $child = $user->children()->find($assignedChildId);
                                    if ($child) {
                                        $yearGroupService->grantAccess($user, $subscription, $child);
                                        Log::info('PortalController: granted year-group subscription access to assigned child', [
                                            'subscription_id' => $subscription->id,
                                            'child_id' => $assignedChildId,
                                        ]);
                                    }
                                } else {
                                    Log::info('PortalController: subscription pending child assignment', [
                                        'subscription_id' => $subscription->id,
                                        'user_id' => $user->id,
                                    ]);
                                }
                            }
                        } else {
                            Log::warning('PortalController: No local subscription found for name', ['subscriptionName' => $subscriptionName]);
                        }
                    } else {
                        Log::warning('PortalController: No subscription name found in remoteSub', ['remoteSub' => $remoteSub]);
                    }
                }

                // Revoke access for any local subscriptions not in active remote subscriptions
                $userLocalSubs = $user->subscriptions()->withPivot(['status', 'starts_at', 'ends_at'])->get();
                foreach ($userLocalSubs as $localSub) {
                    if (!in_array($localSub->id, $activeLocalSubscriptionIds)) {
                        if ($localSub->pivot->status === 'active') {
                            Log::info('PortalController: revoking local subscription not in remote active', ['subscription_id' => $localSub->id]);
                            $user->subscriptions()->updateExistingPivot(
                                $localSub->id,
                                [
                                    'status' => 'canceled',
                                ]
                            );
                            
                            // NEW: Revoke year-group subscription access if applicable
                            $contentFilters = $localSub->content_filters ?? [];
                            if (($contentFilters['type'] ?? null) === 'year_group') {
                                $yearGroupService = app(\App\Services\YearGroupSubscriptionService::class);
                                foreach ($user->children as $child) {
                                    $yearGroupService->revokeAccess($child);
                                }
                                Log::info('PortalController: revoked year-group subscription access', [
                                    'subscription_id' => $localSub->id,
                                    'children_count' => $user->children->count(),
                                ]);
                            }
                        }
                    }
                }
            } else {
                Log::warning('PortalController: No subscriptions data found in API response', ['subsResp' => $subsResp]);
            }
        }
            
        
        

        // 2) Determine which children belong to this user (guard against null)
        $user = Auth::user();
        $allChildren = collect(); // default empty collection

        if ($user && $user->role === 'admin') {
            // Admin sees every child in the system
            $allChildren = Child::select([
                    'id',
                    'child_name as name',    // alias so front‐end can do c.name
                    'year_group',            // must exist in the table
                ])
                ->get();
        } elseif ($user && $user->role !== null) {
            // A regular parent (or any logged‐in user with a non‐null role other than "admin")
            // sees only their own children
            $allChildren = $user->children()
                                ->select([
                                    'id',
                                    'child_name as name',  // alias
                                    'year_group',
                                ])
                                ->get();
        }
        // If $user is null, or $user->role is null, $allChildren remains empty.

        // 3) Check if a specific child was requested via ?child=...
        $selectedChild = null;
        $requestedId   = $request->query('child');

        if ($requestedId) {
            // Find that child in $allChildren (if it exists)
            $selectedChild = $allChildren->firstWhere('id', (int) $requestedId) ?: null;
        }

        // 4) Build an array of child IDs to filter by.
        //    If a child is selected, use only that one; otherwise use all of this user’s children.
        $childIds = $selectedChild
            ? [ $selectedChild->id ]
            : $allChildren->pluck('id')->all();

        // 5) Use access table to determine lessons/assessments for these children
        $accessRecords = \App\Models\Access::whereIn('child_id', $childIds)
            ->where('access', true)
            ->where('payment_status', 'paid')
            ->get();

        $liveSessionIds = collect();
        $contentLessonIds = collect();
        $assessmentIds = collect();
        
        foreach ($accessRecords as $access) {
            // Live Lesson Sessions (lesson_id and lesson_ids now point to LiveLessonSession)
            if ($access->lesson_id) {
                $liveSessionIds->push($access->lesson_id);
            }
            if ($access->lesson_ids) {
                foreach ((array) $access->lesson_ids as $lid) {
                    $liveSessionIds->push($lid);
                }
            }
            
            // NEW: Extract live_lesson_session_ids from metadata (course purchases)
            if ($access->metadata) {
                $metadata = is_string($access->metadata) 
                    ? json_decode($access->metadata, true) 
                    : $access->metadata;
                
                if (isset($metadata['live_lesson_session_ids']) && is_array($metadata['live_lesson_session_ids'])) {
                    foreach ($metadata['live_lesson_session_ids'] as $lsid) {
                        $liveSessionIds->push($lsid);
                    }
                }
            }
            
            // Content Lessons (only singular field exists)
            if ($access->content_lesson_id) {
                $contentLessonIds->push($access->content_lesson_id);
            }

            // Content Lessons from lesson_ids (array) if provided
            if ($access->lesson_ids) {
                foreach ((array) $access->lesson_ids as $lid) {
                    $contentLessonIds->push($lid);
                }
            }

            // Content Lessons from metadata -> content_lesson_ids
            if ($access->metadata) {
                $metadata = is_string($access->metadata)
                    ? json_decode($access->metadata, true)
                    : $access->metadata;

                if (!empty($metadata['content_lesson_ids']) && is_array($metadata['content_lesson_ids'])) {
                    foreach ($metadata['content_lesson_ids'] as $lid) {
                        $contentLessonIds->push($lid);
                    }
                }
            }
            
            // Assessments
            if ($access->assessment_id) {
                $assessmentIds->push($access->assessment_id);
            }
            if ($access->assessment_ids) {
                foreach ((array) $access->assessment_ids as $aid) {
                    $assessmentIds->push($aid);
                }
            }
        }
        
        $liveSessionIds = $liveSessionIds->unique()->values();
        $contentLessonIds = $contentLessonIds->unique()->values();
        $assessmentIds = $assessmentIds->unique()->values();

        // Calculate overall progress across content lessons for the selected children
        $overallProgress = 0;
        if ($contentLessonIds->count() > 0 && !empty($childIds)) {
            // LessonProgress uses the column name "lesson_id" for content lessons
            $progressRecords = LessonProgress::whereIn('child_id', $childIds)
                ->whereIn('lesson_id', $contentLessonIds)
                ->pluck('completion_percentage');

            if ($progressRecords->count() > 0) {
                $overallProgress = round($progressRecords->average());
            }
        }
        Log::info('Overall progress calculated:', ['overallProgress' => $overallProgress]);
        // 6) Load lessons by access - NOW USING LIVE LESSON SESSIONS
        // OLD: Lesson model migration pending (deprecated)
        // NEW: Load LiveLessonSessions instead with proper formatting
        $lessons = LiveLessonSession::whereIn('id', $liveSessionIds)
            ->orderBy('scheduled_start_time')
            ->get()
            ->map(function($session) use ($childIds) {
                return [
                    'id'         => $session->id,
                    'title'      => $session->title,
                    'description' => $session->description ?? '',
                    'start_time' => $session->scheduled_start_time->toIso8601String(),
                    'end_time'   => $session->scheduled_end_time?->toIso8601String(),
                    'scheduled_start_time' => $session->scheduled_start_time->toIso8601String(),
                    'scheduled_end_time' => $session->scheduled_end_time?->toIso8601String(),
                    'status'     => $session->status,
                    'child_ids'  => collect($childIds)->map(fn($id) => (string) $id),
                    'attendance_count' => 0, // TODO: Implement attendance tracking
                ];
            });
        $expected = count($childIds);

        // 7) Load assessments by access
        $assessments = Assessment::whereIn('id', $assessmentIds)
            ->with('service:id,service_name')
            ->withCount([
                'submissions as submissions_count' => fn($q) =>
                    $q->whereIn('child_id', $childIds)
            ])
            ->get()
            ->map(function ($assessment) use ($childIds) {
                return [
                    'id'                => $assessment->id,
                    'title'             => $assessment->title,
                    'availability'      => optional($assessment->availability)->toIso8601String(),
                    'deadline'          => optional($assessment->deadline)->toIso8601String(),
                    'service'           => $assessment->service?->only(['id', 'service_name']),
                    'submissions_count' => $assessment->submissions_count,
                    // Needed so the home page can filter assessments by selected child
                    'child_ids'         => collect($childIds)->map(fn ($id) => (string) $id)->values(),
                ];
            });
        Log::info('Assessments:', $assessments->toArray());
        // 8) Build a webcal:// feed URL
        $https  = route('calendar.feed', ['token' => encrypt($user->id)]);
        $webcal = preg_replace('#^https?://#', 'webcal://', $https);
                 $notifications = AppNotification::where('user_id', $userId)->orderByDesc('created_at')
                  ->take(4)
                  ->get();

        // COMMENTED OUT - Lesson model migration pending
        // $journeys = Journey::with([
        //     'categories' => fn ($q) => $q->with([
        //         'lessons:id,title,journey_category_id',
        //         'assessments:id,title,journey_category_id',
        //     ])->orderBy('topic')->orderBy('name'),
        // ])->orderBy('title')->get();

        // // 2) Reshape: group each journey's categories by TOPIC
        // $journeys = $journeys->map(function (Journey $j) {
        //     /** @var Collection $byTopic */
        //     $byTopic = $j->categories
        //         ->groupBy('topic')
        //         ->map(function ($cats) {
        //             return $cats->map(function ($cat) {
        //                 return [
        //                     'id'          => $cat->id,
        //                     'name'        => $cat->name,
        //                     'lessons'     => $cat->lessons->map->only(['id', 'title']),
        //                     'assessments' => $cat->assessments->map->only(['id', 'title']),
        //                 ];
        //             })->values();
        //         });
                                
        //     return [
        //         'id'      => $j->id,
        //         'title'   => $j->title,
        //         'topics'  => $byTopic,   // key = topic string, value = [ {category,…}, … ]
        //     ];
        // });
        $journeys = collect(); // Empty collection until migration is complete
        $currentUser = auth()->user();
        $childrenData = [];

        // Build childrenData from accessRecords to reflect actual access
        $accessByChild = $accessRecords->groupBy('child_id');

        foreach ($childIds as $cid) {
            $lessonIds = collect();
            $assessmentIds = collect();

            if (isset($accessByChild[$cid])) {
                foreach ($accessByChild[$cid] as $access) {
                    if ($access->lesson_id) {
                        $lessonIds->push($access->lesson_id);
                    }
                    if ($access->lesson_ids) {
                        foreach ((array) $access->lesson_ids as $lid) {
                            $lessonIds->push($lid);
                        }
                    }
                    if ($access->assessment_id) {
                        $assessmentIds->push($access->assessment_id);
                    }
                    if ($access->assessment_ids) {
                        foreach ((array) $access->assessment_ids as $aid) {
                            $assessmentIds->push($aid);
                        }
                    }
                }
            }

            $childrenData[] = [
                'child_id' => $cid,
                'lesson_ids' => $lessonIds->unique()->values(),
                'assessment_ids' => $assessmentIds->unique()->values(),
            ];
        }
        
        // 9) Pass everything into Inertia
        return Inertia::render('@parent/Main/Home', [
            'slides'        => $slides,
            'testimonials'  => $testimonials,
            'allChildren'   => $allChildren,       // each: { id, name, year_group }
            'selectedChild' => $selectedChild,     // or null
            'lessons'       => $lessons,
            'assessments'   => $assessments,
            'feedUrl'       => $webcal,
            'notifications'=> $notifications,
            'expected'    => $expected,
            'journeys' => $journeys,
            'childrenData' => $childrenData,
            'hasUnpaidDue' => $hasUnpaidDue,
            'overallProgress' => $overallProgress,
        ]);
    }
 // App\Http\Controllers\ScheduleController.php (or wherever)
public function calenderIndex(Request $request)
{
    $childId = $request->get('child', 'all');
    $userId = auth()->id();
    $user = Auth::user();
    $childIds = $childId === 'all'
        ? Child::pluck('id')->toArray()
        : [(int)$childId];

    // Use access table to determine lessons/assessments for these children
    $accessRecords = \App\Models\Access::whereIn('child_id', $childIds)
        ->where('access', true)
        ->where('payment_status', 'paid')
        ->get();

    $liveSessionIds = collect();
    $assessmentIds = collect();
    foreach ($accessRecords as $access) {
        // Live Lesson Sessions (lesson_id and lesson_ids now point to LiveLessonSession)
        if ($access->lesson_id) {
            $liveSessionIds->push($access->lesson_id);
        }
        if ($access->lesson_ids) {
            foreach ((array) $access->lesson_ids as $lid) {
                $liveSessionIds->push($lid);
            }
        }
        
        // NEW: Extract live_lesson_session_ids from metadata (course purchases)
        if ($access->metadata) {
            $metadata = is_string($access->metadata) 
                ? json_decode($access->metadata, true) 
                : $access->metadata;
            
            if (isset($metadata['live_lesson_session_ids']) && is_array($metadata['live_lesson_session_ids'])) {
                foreach ($metadata['live_lesson_session_ids'] as $lsid) {
                    $liveSessionIds->push($lsid);
                }
            }
        }
        
        if ($access->assessment_id) {
            $assessmentIds->push($access->assessment_id);
        }
        if ($access->assessment_ids) {
            foreach ((array) $access->assessment_ids as $aid) {
                $assessmentIds->push($aid);
            }
        }
    }
    $liveSessionIds = $liveSessionIds->unique()->values();
    $assessmentIds = $assessmentIds->unique()->values();

    $https  = route('calendar.feed', ['token' => encrypt($user->id)]);
    $webcal = preg_replace('#^https?://#', 'webcal://', $https);
    $notifications = AppNotification::where('user_id', $userId)->orderByDesc('created_at')
        ->where('status', 'unread')
        ->take(4)
        ->get();

    // COMMENTED OUT - Lesson model migration pending
    // $lessons = Lesson::whereIn('id', $lessonIds)
    //     ->get()
    //     ->map(fn($l) => [
    //         'id'         => $l->id,
    //         'title'      => $l->title,
    //         'start_time' => $l->start_time->toIso8601String(),
    //         'end_time'   => $l->end_time?->toIso8601String(),
    //     ]);
    $lessons = collect(); // Empty collection until migration is complete

    $assessments = Assessment::whereIn('id', $assessmentIds)
        ->get()
        ->map(fn($a) => [
            'id'          => $a->id,
            'title'       => $a->title,
            'availability'=> $a->availability->toIso8601String(),
            'deadline'    => $a->deadline->toIso8601String(),
        ]);

    return Inertia::render('@parent/Schedule/Calender', [
        'lessons'     => $lessons,
        'assessments' => $assessments,
        'childrenList'=> $request->allChildren,
        'selectedChild'=> $childId,
        'feedUrl'       => $webcal,
    ]);
}


    public function deadlineIndex(Request $request)
    {
        $childId = $request->get('child', 'all');

        $childIds = $childId === 'all'
            ? Child::pluck('id')->toArray()
            : [(int)$childId];

        // Use access table to determine lessons/assessments for these children
        $accessRecords = \App\Models\Access::whereIn('child_id', $childIds)
            ->where('access', true)
            ->where('payment_status', 'paid')
            ->get();

        $liveSessionIds = collect();
        $assessmentIds = collect();
        foreach ($accessRecords as $access) {
            // Live Lesson Sessions (lesson_id and lesson_ids now point to LiveLessonSession)
            if ($access->lesson_id) {
                $liveSessionIds->push($access->lesson_id);
            }
            if ($access->lesson_ids) {
                foreach ((array) $access->lesson_ids as $lid) {
                    $liveSessionIds->push($lid);
                }
            }
            
            // NEW: Extract live_lesson_session_ids from metadata (course purchases)
            if ($access->metadata) {
                $metadata = is_string($access->metadata) 
                    ? json_decode($access->metadata, true) 
                    : $access->metadata;
                
                if (isset($metadata['live_lesson_session_ids']) && is_array($metadata['live_lesson_session_ids'])) {
                    foreach ($metadata['live_lesson_session_ids'] as $lsid) {
                        $liveSessionIds->push($lsid);
                    }
                }
            }
            
            if ($access->assessment_id) {
                $assessmentIds->push($access->assessment_id);
            }
            if ($access->assessment_ids) {
                foreach ((array) $access->assessment_ids as $aid) {
                    $assessmentIds->push($aid);
                }
            }
        }
        $liveSessionIds = $liveSessionIds->unique()->values();
        $assessmentIds = $assessmentIds->unique()->values();

        // Fetch LiveLessonSessions with upcoming deadlines
        $lessons = LiveLessonSession::whereIn('id', $liveSessionIds)
            ->where('scheduled_start_time', '>=', now())
            ->orderBy('scheduled_start_time')
            ->get()
            ->map(function($session) {
                return [
                    'id'         => $session->id,
                    'title'      => $session->title,
                    'description' => $session->description ?? '',
                    'start_time' => $session->scheduled_start_time->toIso8601String(),
                    'end_time'   => $session->scheduled_end_time?->toIso8601String(),
                    'status'     => $session->status,
                ];
            });

        $assessments = Assessment::whereIn('id', $assessmentIds)
            ->where('deadline', '>=', now())
            ->get();

        return Inertia::render('@parent/Schedule/Deadlines', [
            'assessments' => $assessments,
            'lessons' => $lessons,
        ]);
    }
  
        public function submissionIndex()
        {
            $user = Auth::user();

            // Get all children for the current user
            $children = $user ? $user->children()->pluck('id')->all() : [];

            // Get all submissions for these children, eager load assessment and child
            $submissions = AssessmentSubmission::with(['assessment:id,title', 'child:id,child_name'])
                ->whereIn('child_id', $children)
                ->orderByDesc('created_at')
                ->get();

            return Inertia::render('@parent/Assessments/MySubmissions', [
                'submissions' => $submissions,
            ]);
        }

        // New: Combined Schedule action
    public function scheduleIndex(Request $request)
    {
        $childId = $request->get('child', 'all');
        $userId = auth()->id();
        $user = Auth::user();

        // Get children based on user role
        if ($user->role === 'admin') {
            $allChildren = Child::select(['id', 'child_name as name', 'year_group'])->get();
            $childIds = $childId === 'all' ? $allChildren->pluck('id')->toArray() : [(int)$childId];
        } else {
            $allChildren = $user->children()->select(['id', 'child_name as name', 'year_group'])->get();
            $childIds = $childId === 'all' ? $allChildren->pluck('id')->toArray() : [(int)$childId];
        }

        // Use access table to determine lessons/assessments for these children
        $accessRecords = \App\Models\Access::whereIn('child_id', $childIds)
            ->where('access', true)
            ->where('payment_status', 'paid')
            ->get();

        $liveSessionIds = collect();
        $contentLessonIds = collect();
        $assessmentIds = collect();
        
        foreach ($accessRecords as $access) {
            // Live Lesson Sessions (lesson_id and lesson_ids now point to LiveLessonSession)
            if ($access->lesson_id) {
                $liveSessionIds->push($access->lesson_id);
            }
            if ($access->lesson_ids) {
                foreach ((array) $access->lesson_ids as $lid) {
                    $liveSessionIds->push($lid);
                }
            }
            
            // NEW: Extract live_lesson_session_ids from metadata (course purchases)
            if ($access->metadata) {
                $metadata = is_string($access->metadata) 
                    ? json_decode($access->metadata, true) 
                    : $access->metadata;
                
                if (isset($metadata['live_lesson_session_ids']) && is_array($metadata['live_lesson_session_ids'])) {
                    foreach ($metadata['live_lesson_session_ids'] as $lsid) {
                        $liveSessionIds->push($lsid);
                    }
                }
            }
            
            // Content Lessons (only singular field exists)
            if ($access->content_lesson_id) {
                $contentLessonIds->push($access->content_lesson_id);
            }
            
            // Assessments
            if ($access->assessment_id) {
                $assessmentIds->push($access->assessment_id);
            }
            if ($access->assessment_ids) {
                foreach ((array) $access->assessment_ids as $aid) {
                    $assessmentIds->push($aid);
                }
            }
        }
        
        $liveSessionIds = $liveSessionIds->unique()->values();
        $contentLessonIds = $contentLessonIds->unique()->values();
        $assessmentIds = $assessmentIds->unique()->values();

        // DEBUG LOGGING
        Log::info('Schedule Index - Data Extraction', [
            'user_id' => $userId,
            'child_ids' => $childIds,
            'access_records_count' => $accessRecords->count(),
            'live_session_ids' => $liveSessionIds->toArray(),
            'content_lesson_ids' => $contentLessonIds->toArray(),
            'assessment_ids' => $assessmentIds->toArray(),
        ]);

        $https  = route('calendar.feed', ['token' => encrypt($user->id)]);
        $webcal = preg_replace('#^https?://#', 'webcal://', $https);

        // NEW: Fetch Live Lesson Sessions for calendar (include recent past)
        $calendarLiveSessions = LiveLessonSession::whereIn('id', $liveSessionIds)
            ->where('scheduled_start_time', '>=', now()->subDays(7))
            ->orderBy('scheduled_start_time')
            ->get()
            ->map(function($session) use ($childIds) {
                return [
                    'id'         => $session->id,
                    'title'      => $session->title,
                    'description' => $session->description ?? '',
                    'start_time' => $session->scheduled_start_time->toIso8601String(),
                    'end_time'   => $session->end_time?->toIso8601String(),
                    'status'     => $session->status,
                    'type'       => 'live_session',
                    'child_ids'  => collect($childIds)->map(fn($id) => (string) $id),
                ];
            });

        // NEW: Fetch incomplete Content Lessons (self-paced, no times)
        $incompleteContentLessons = ContentLesson::whereIn('id', $contentLessonIds)
            ->with(['course', 'module'])
            ->get()
            ->filter(function($lesson) use ($childIds) {
                // Check if any child has incomplete progress
                foreach ($childIds as $childId) {
                    $progress = LessonProgress::where('content_lesson_id', $lesson->id)
                        ->where('child_id', $childId)
                        ->first();
                    
                    // Include if no progress or not completed
                    if (!$progress || $progress->completion_percentage < 100) {
                        return true;
                    }
                }
                return false;
            })
            ->map(function($lesson) use ($childIds) {
                // Get progress for first child (or aggregate if multiple)
                $progress = LessonProgress::where('content_lesson_id', $lesson->id)
                    ->whereIn('child_id', $childIds)
                    ->first();
                    
                return [
                    'id' => $lesson->id,
                    'title' => $lesson->title,
                    'description' => $lesson->description ?? '',
                    'course_name' => $lesson->course->title ?? null,
                    'module_name' => $lesson->module->title ?? null,
                    'progress' => $progress ? $progress->completion_percentage : 0,
                    'estimated_duration' => $lesson->estimated_duration_minutes ?? null,
                    'type' => 'content_lesson',
                ];
            })
            ->values();

        // Calendar events: all lessons and assessments with additional info
        // OLD: COMMENTED OUT - Lesson model migration pending
        // $calendarLessons = Lesson::whereIn('id', $lessonIds)
        //     ->get()
        //     ->map(function($l) use ($childIds) {
        //         return [
        //             'id'         => $l->id,
        //             'title'      => $l->title,
        //             'description' => $l->description ?? '',
        //             'start_time' => $l->start_time->toIso8601String(),
        //             'end_time'   => $l->end_time?->toIso8601String(),
        //             'location'   => $l->location ?? 'Online',
        //             'status'     => $l->status ?? 'scheduled',
        //             'allowed_child_ids' => collect($childIds)->map(fn($id) => (string) $id),
        //         ];
        //     });

        $calendarAssessments = Assessment::whereIn('id', $assessmentIds)
            ->get()
            ->map(function($a) use ($childIds) {
                return [
                    'id'          => $a->id,
                    'title'       => $a->title,
                    'description' => $a->description ?? '',
                    'availability'=> $a->availability->toIso8601String(),
                    'deadline'    => $a->deadline->toIso8601String(),
                    'duration'    => $a->duration ?? 60,
                    'status'      => $a->status ?? 'available',
                    'child_ids'   => collect($childIds)->map(fn($id) => (string) $id),
                ];
            });

        // Deadlines: only future lessons/assessments with additional info
        // COMMENTED OUT - Lesson model migration pending
        // $deadlineLessons = Lesson::whereIn('id', $lessonIds)
        //     ->where('end_time', '>=', now())
        //     ->orderBy('end_time')
        //     ->get()
        //     ->map(function($l) use ($childIds) {
        //         return [
        //             'id'         => $l->id,
        //             'title'      => $l->title,
        //             'description' => $l->description ?? '',
        //             'end_time'   => $l->end_time->toIso8601String(),
        //             'location'   => $l->location ?? 'Online',
        //             'status'     => $l->status ?? 'scheduled',
        //             'allowed_child_ids' => collect($childIds)->map(fn($id) => (string) $id),
        //         ];
        //     });
        $deadlineLessons = collect(); // Empty collection until migration is complete

        $deadlineAssessments = Assessment::whereIn('id', $assessmentIds)
            ->where('deadline', '>=', now())
            ->orderBy('deadline')
            ->get()
            ->map(function($a) use ($childIds) {
                return [
                    'id'          => $a->id,
                    'title'       => $a->title,
                    'description' => $a->description ?? '',
                    'deadline'    => $a->deadline->toIso8601String(),
                    'duration'    => $a->duration ?? 60,
                    'status'      => $a->status ?? 'available',
                    'child_ids'   => collect($childIds)->map(fn($id) => (string) $id),
                ];
            });

        // Get notifications related to schedule
        $notifications = AppNotification::where('user_id', $userId)
            ->where('type', 'schedule')
            ->orderByDesc('created_at')
            ->take(5)
            ->get();

        // NEW: Updated response structure
        $calendarEvents = [
            'liveSessions' => $calendarLiveSessions,
            'assessments' => $calendarAssessments,
        ];
        
        $deadlines = [
            'liveSessions' => LiveLessonSession::whereIn('id', $liveSessionIds)
                ->where('scheduled_start_time', '>=', now())
                ->where('scheduled_start_time', '<=', now()->addDays(30))
                ->orderBy('scheduled_start_time')
                ->get()
                ->map(function($session) use ($childIds) {
                    return [
                        'id'         => $session->id,
                        'title'      => $session->title,
                        'description' => $session->description ?? '',
                        'start_time' => $session->scheduled_start_time->toIso8601String(),
                        'type'       => 'live_session',
                        'child_ids'  => collect($childIds)->map(fn($id) => (string) $id),
                    ];
                }),
            'assessments' => $deadlineAssessments,
        ];

        // Children list for tab switching/filtering
        $childrenList = $allChildren;
        $selectedChild = $childId;

        return Inertia::render('@parent/Schedule/Schedule', [
            'calendarEvents' => $calendarEvents,
            'deadlines' => $deadlines,
            'incompleteContentLessons' => $incompleteContentLessons,
            'childrenList' => $childrenList,
            'selectedChild' => $selectedChild,
            'feedUrl' => $webcal,
            'notifications' => $notifications,
        ]);
    }
    // Show all transactions for the current user, with items and invoice_id
    public function transactionsIndex()
    {
        $user = Auth::user();
        $transactions = $user->transactions()
            ->with('items')
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('@public/Transactions/Index', [
            'transactions' => $transactions,
        ]);
    }

    // AI Hub Demo - Phase 4 Frontend Hub
    public function aiHubDemo(Request $request)
    {
        $user = Auth::user();
        
        // Get all children for demo
        $allChildren = collect();
        if ($user && $user->role === 'admin') {
            $allChildren = Child::select([
                'id',
                'child_name as name',
                'year_group',
            ])->take(5)->get(); // Limit for demo
        } elseif ($user && $user->role !== null) {
            $allChildren = $user->children()
                                ->select([
                                    'id',
                                    'child_name as name',
                                    'year_group',
                                ])
                                ->get();
        }

        // Add mock children if none exist (for demo purposes)
        if ($allChildren->isEmpty()) {
            $allChildren = collect([
                (object) ['id' => 1, 'name' => 'Emma Thompson', 'year_group' => 'Year 7'],
                (object) ['id' => 2, 'name' => 'James Wilson', 'year_group' => 'Year 9'],
                (object) ['id' => 3, 'name' => 'Sophia Chen', 'year_group' => 'Year 8'],
            ]);
        }

        // Get selected child from request
        $selectedChild = null;
        $requestedId = $request->query('child');
        if ($requestedId) {
            $selectedChild = $allChildren->firstWhere('id', (int) $requestedId);
        }

        // Get user's feature flags (for AI features)
        $features = [];
        if ($user) {
            $userSubs = $user->subscriptions()
                ->withPivot(['status', 'starts_at', 'ends_at'])
                ->where('status', 'active')
                ->get();
            
            foreach ($userSubs as $sub) {
                if ($sub->features) {
                    // Handle both string (JSON) and array formats
                    $subFeatures = is_string($sub->features) 
                        ? json_decode($sub->features, true) ?? []
                        : (is_array($sub->features) ? $sub->features : []);
                    $features = array_merge($features, $subFeatures);
                }
            }
        }
        
        // Ensure AI features are available for demo
        if (!in_array('ai_analysis', $features)) {
            $features[] = 'ai_analysis';
        }

        return Inertia::render('@parent/AI/AIHubDemo', [
            'allChildren' => $allChildren,
            'selectedChild' => $selectedChild,
            'features' => $features,
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
            ] : null,
        ]);
    }

    /**
     * AI Console Page - Dedicated full-page AI chat interface
     * Focused AI interactions without distractions
     */
    public function aiConsole()
    {
        $user = Auth::user();
        $children = $user->children ?? collect();

        return Inertia::render('@parent/AI/AIConsolePage', [
            'children' => $children->map(function ($child) {
                return [
                    'id' => $child->id,
                    'name' => $child->child_name,
                    'grade' => $child->year_group ?? 'Year 6',
                    'school' => $child->school ?? 'Local School',
                    
                ];
            },
            )->values()->all(),
            'showTutorWidget' => false,
        ]);
    }

    /**
     * Unified Products/Services/Courses Index
     */
    public function productsIndex(Request $request)
    {
        $user = Auth::user();
        $orgId = null;
        if ($user) {
            // Super admin can optionally pass organization_id to view a specific org's catalog
            $orgId = $user->role === 'super_admin'
                ? ($request->organization_id ?? null)
                : $user->current_organization_id;
        }
        
        // Get all children for the current user
        $allChildren = collect();
        if ($user && $user->role === 'admin') {
            $allChildren = Child::select([
                'id',
                'child_name as name',
                'year_group',
            ])->get();
        } elseif ($user && $user->role !== null) {
            $allChildren = $user->children()
                ->select([
                    'id',
                    'child_name as name',
                    'year_group',
                ])
                ->get();
        }
        
        // Fetch all products
        $products = Product::when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'price' => $product->price,
                    'type' => 'product',
                ];
            });
        
        // Fetch all services with enhanced metadata (excluding course-type services)
        $services = Service::with(['lessons', 'assessments'])
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->where('availability', true)
            ->whereNull('course_id')  // Exclude course-type services
            ->whereIn('_type', ['lesson', 'assessment', 'bundle', 'flexible'])  // Only standard service types
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($service) {
                $lessonsCount = $service->lessons()->count();
                $assessmentsCount = $service->assessments()->count();
                
                // Determine effective type for filtering
                $effectiveType = $service->_type;
                if ($service->_type === 'flexible') {
                    // Smart categorization for flexible services
                    if ($lessonsCount > 0 && $assessmentsCount === 0) {
                        $effectiveType = 'lesson';
                    } elseif ($assessmentsCount > 0 && $lessonsCount === 0) {
                        $effectiveType = 'assessment';
                    } elseif ($lessonsCount > 0 && $assessmentsCount > 0) {
                        $effectiveType = 'bundle';
                    }
                }
                
                return [
                    'id' => $service->id,
                    'service_name' => $service->service_name,
                    'name' => $service->getFlexibleDisplayName(),
                    'display_name' => $service->getFlexibleDisplayName(),
                    'description' => $service->description,
                    'price' => $service->price,
                    '_type' => $service->_type,
                    'flexible_type' => $service->flexible_type,
                    'effective_type' => $effectiveType,  // New: for smart filtering
                    'is_flexible' => $service->_type === 'flexible',
                    'availability' => $service->availability,
                    'selection_description' => $service->getSelectionDescription(),
                    'display_categories' => $service->getDisplayCategories(),
                    'lessons_count' => $lessonsCount,
                    'assessments_count' => $assessmentsCount,
                ];
            });
        
        // Fetch all courses with purchase info
        $courses = Course::with(['modules'])
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->where('status', 'live')
            ->whereHas('service') // Only courses with services
            ->get()
            ->map(function ($course) use ($orgId) {
                $service = Service::where('course_id', $course->id)
                    ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
                    ->first();
                
                $totalLessons = $course->modules->sum(function ($module) {
                    return $module->lessons()->count();
                });
                
                $totalAssessments = $course->modules->sum(function ($module) {
                    return $module->assessments()->count();
                }) + $course->assessments()->count();
                
                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'description' => $course->description,
                    'thumbnail' => $course->thumbnail,
                    'category' => $course->category,
                    'level' => $course->level,
                    'estimated_duration_minutes' => $course->estimated_duration_minutes,
                    'is_featured' => $course->is_featured,
                    'content_stats' => [
                        'lessons' => $totalLessons,
                        'assessments' => $totalAssessments,
                    ],
                    'service' => $service ? [
                        'id' => $service->id,
                        'price' => $service->price,
                        'availability' => $service->availability,
                    ] : null,
                ];
            })
            ->filter(fn($course) => $course['service'] !== null);
        
        return Inertia::render('@parent/Products/Index', [
            'products' => $products,
            'services' => $services,
            'courses' => $courses->values(),
            'allChildren' => $allChildren,
        ]);
    }
}
