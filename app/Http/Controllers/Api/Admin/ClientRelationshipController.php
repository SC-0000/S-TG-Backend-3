<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Jobs\GrantAccessForTransactionJob;
use App\Models\AdminTask;
use App\Models\Attendance;
use App\Models\AssessmentSubmission;
use App\Models\Child;
use App\Models\ClientHealthScore;
use App\Models\CommunicationMessage;
use App\Models\Conversation;
use App\Models\Lesson;
use App\Models\Service;
use App\Models\ServiceCredit;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\BillingService;
use App\Services\ClientHealthService;
use App\Services\TeacherScheduleService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClientRelationshipController extends ApiController
{
    /* ═══════════════════════════════════════════════════════════════
     |  READ ENDPOINTS
     | ═══════════════════════════════════════════════════════════════ */

    /**
     * GET /api/v1/admin/clients
     * Paginated list of parents with health scores.
     */
    public function index(Request $request): JsonResponse
    {
        $orgId = $this->resolveOrganizationId($request);
        $perPage = min($request->integer('per_page', 20), 50);

        // Build the parent query — no JOIN to avoid Eloquent count/relation conflicts
        $query = User::where('role', 'parent')
            ->when($orgId, fn ($q) => $q->whereHas('organizations', fn ($o) => $o->where('organizations.id', $orgId)))
            ->with(['children' => fn ($q) => $q->when($orgId, fn ($c) => $c->where('organization_id', $orgId))]);

        // Search
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('mobile_number', 'like', "%{$search}%");
            });
        }

        // Health status / flag filters — subquery on client_health_scores
        if ($request->filled('health_status') || $request->filled('has_flag')) {
            $query->whereHas('clientHealthScore', function ($q) use ($request, $orgId) {
                $q->when($orgId, fn ($sq) => $sq->where('organization_id', $orgId));

                if ($request->filled('health_status')) {
                    match ($request->input('health_status')) {
                        'critical' => $q->where('overall_score', '<', 25),
                        'at_risk'  => $q->where('overall_score', '<', 50)->where('overall_score', '>=', 25),
                        'healthy'  => $q->where('overall_score', '>=', 50),
                        default    => null,
                    };
                }

                if ($request->filled('has_flag')) {
                    $q->whereJsonContains('flags', $request->input('has_flag'));
                }
            });
        }

        // Sort — safe whitelist approach
        $sortBy = $request->input('sort_by', 'name');
        $sortDir = in_array($request->input('sort_dir'), ['asc', 'desc']) ? $request->input('sort_dir') : 'asc';

        if ($sortBy === 'name') {
            $query->orderBy('name', $sortDir);
        } elseif (in_array($sortBy, ['overall_score', 'last_booking_at', 'last_payment_at', 'last_message_at'])) {
            // Sort via a subquery on client_health_scores
            $query->orderBy(
                ClientHealthScore::select($sortBy)
                    ->whereColumn('user_id', 'users.id')
                    ->when($orgId, fn ($sq) => $sq->where('organization_id', $orgId))
                    ->limit(1),
                $sortDir
            );
        } else {
            $query->orderBy('name', 'asc');
        }

        $paginator = $query->paginate($perPage);

        // Load health scores for the current page in a single query
        $userIds = $paginator->getCollection()->pluck('id')->toArray();
        $healthScores = ClientHealthScore::whereIn('user_id', $userIds)
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->get()
            ->keyBy('user_id');

        $data = $paginator->getCollection()->map(function ($user) use ($healthScores) {
            $hs = $healthScores->get($user->id);

            $nextBooking = null;
            $childIds = $user->children->pluck('id')->toArray();
            if (!empty($childIds)) {
                $nextBooking = Lesson::whereHas('children', fn ($q) => $q->whereIn('children.id', $childIds))
                    ->where('start_time', '>', now())
                    ->whereNotIn('status', ['cancelled', 'draft'])
                    ->orderBy('start_time')
                    ->value('start_time');
            }

            return [
                'id'                   => $user->id,
                'name'                 => $user->name,
                'email'                => $user->email,
                'mobile'               => $user->mobile_number,
                'avatar'               => $user->avatar_path ? '/storage/' . $user->avatar_path : null,
                'children_count'       => $user->children->count(),
                'children'             => $user->children->map(fn ($c) => [
                    'id' => $c->id, 'name' => $c->child_name, 'year_group' => $c->year_group,
                ])->values(),
                'health_score'         => $hs ? [
                    'overall'       => $hs->overall_score,
                    'booking'       => $hs->booking_score,
                    'payment'       => $hs->payment_score,
                    'engagement'    => $hs->engagement_score,
                    'communication' => $hs->communication_score,
                    'risk_level'    => $hs->risk_level,
                ] : null,
                'flags'                => $hs?->flags ?? [],
                'next_booking'         => $nextBooking ? Carbon::parse($nextBooking)->toIso8601String() : null,
                'last_booking_at'      => $hs?->last_booking_at?->toIso8601String(),
                'last_payment_at'      => $hs?->last_payment_at?->toIso8601String(),
                'last_message_at'      => $hs?->last_message_at?->toIso8601String(),
                'computed_at'          => $hs?->computed_at?->toIso8601String(),
            ];
        });

        return $this->paginated($paginator, $data);
    }

    /**
     * GET /api/v1/admin/clients/preview
     * Compact data for Delivery Hub widget — clients ordered by priority with children + teachers.
     */
    public function preview(Request $request): JsonResponse
    {
        $orgId = $this->resolveOrganizationId($request);
        $limit = min($request->integer('limit', 15), 30);

        // Get all parents in this org, ordered by health score (worst first)
        $parents = User::where('role', 'parent')
            ->when($orgId, fn ($q) => $q->whereHas('organizations', fn ($o) => $o->where('organizations.id', $orgId)))
            ->with(['children' => fn ($q) => $q
                ->when($orgId, fn ($c) => $c->where('organization_id', $orgId))
                ->with(['assignedTeachers' => fn ($t) => $t->select('users.id', 'users.name', 'users.avatar_path')])
            ])
            ->get();

        // Load health scores
        $healthScores = ClientHealthScore::whereIn('user_id', $parents->pluck('id'))
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->get()
            ->keyBy('user_id');

        // Load upcoming booking counts per child
        $childIds = $parents->flatMap(fn ($p) => $p->children->pluck('id'))->toArray();
        $upcomingByChild = !empty($childIds)
            ? Lesson::where('start_time', '>', now())
                ->whereNotIn('status', ['cancelled', 'draft'])
                ->selectRaw('child_live_session.child_id, COUNT(*) as cnt')
                ->join('child_live_session', 'live_sessions.id', '=', 'child_live_session.lesson_id')
                ->whereIn('child_live_session.child_id', $childIds)
                ->groupBy('child_live_session.child_id')
                ->pluck('cnt', 'child_live_session.child_id')
            : collect();

        // Load spend data per parent (last 30 days + total)
        $parentIds = $parents->pluck('id')->toArray();
        $spendByParent = !empty($parentIds)
            ? Transaction::whereIn('user_id', $parentIds)
                ->where('status', Transaction::STATUS_PAID)
                ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
                ->selectRaw('user_id, SUM(total) as total_spend, SUM(CASE WHEN paid_at >= ? THEN total ELSE 0 END) as spend_30d', [now()->subDays(30)])
                ->groupBy('user_id')
                ->get()
                ->keyBy('user_id')
            : collect();

        // Build output sorted by priority (lowest score first, null scores at end)
        $items = $parents->map(function ($parent) use ($healthScores, $upcomingByChild, $spendByParent) {
            $hs = $healthScores->get($parent->id);
            $spend = $spendByParent->get($parent->id);
            $flags = $hs?->flags ?? [];

            // Determine primary action — the single most important thing to do
            $primaryAction = self::determinePrimaryAction($flags, $hs);

            return [
                'id'          => $parent->id,
                'name'        => $parent->name,
                'email'       => $parent->email,
                'mobile'      => $parent->mobile_number,
                'score'       => $hs?->overall_score,
                'risk_level'  => $hs?->risk_level ?? 'unknown',
                'flags'       => $flags,
                'primary_action' => $primaryAction,
                'spend_30d'   => $spend?->spend_30d ? round((float) $spend->spend_30d, 2) : 0,
                'total_spend' => $spend?->total_spend ? round((float) $spend->total_spend, 2) : 0,
                'last_payment_at' => $hs?->last_payment_at?->toIso8601String(),
                'children'    => $parent->children->map(function ($child) use ($upcomingByChild) {
                    $teacher = $child->assignedTeachers->first();

                    return [
                        'id'             => $child->id,
                        'name'           => $child->child_name,
                        'year_group'     => $child->year_group,
                        'upcoming_count' => $upcomingByChild->get($child->id, 0),
                        'teacher'        => $teacher ? [
                            'id'     => $teacher->id,
                            'name'   => $teacher->name,
                            'avatar' => $teacher->avatar_path ? '/storage/' . $teacher->avatar_path : null,
                        ] : null,
                    ];
                })->values(),
            ];
        })
        ->sortBy(fn ($item) => $item['score'] ?? 999)
        ->values()
        ->take($limit);

        // Summary stats
        $scores = $healthScores->values();
        $stats = [
            'total'    => $parents->count(),
            'healthy'  => $scores->where('overall_score', '>=', 50)->count(),
            'at_risk'  => $scores->where('overall_score', '<', 50)->where('overall_score', '>=', 25)->count(),
            'critical' => $scores->where('overall_score', '<', 25)->count(),
        ];

        return $this->success([
            'clients' => $items,
            'stats'   => $stats,
        ]);
    }

    /**
     * GET /api/v1/admin/clients/stats
     */
    public function stats(Request $request): JsonResponse
    {
        $orgId = $this->resolveOrganizationId($request);

        $query = ClientHealthScore::query()
            ->when($orgId, fn ($q) => $q->forOrganization($orgId));

        $total    = $query->count();
        $atRisk   = (clone $query)->where('overall_score', '<', 50)->where('overall_score', '>=', 25)->count();
        $critical = (clone $query)->where('overall_score', '<', 25)->count();
        $healthy  = (clone $query)->where('overall_score', '>=', 50)->count();
        $avgScore = (clone $query)->avg('overall_score');

        return $this->success([
            'total'     => $total,
            'healthy'   => $healthy,
            'at_risk'   => $atRisk,
            'critical'  => $critical,
            'avg_score' => $avgScore ? round($avgScore) : null,
        ]);
    }

    /**
     * GET /api/v1/admin/clients/alerts
     * Top priority alerts from flagged clients.
     */
    public function alerts(Request $request): JsonResponse
    {
        $orgId = $this->resolveOrganizationId($request);
        $limit = min($request->integer('limit', 10), 25);

        $flaggedClients = ClientHealthScore::query()
            ->when($orgId, fn ($q) => $q->forOrganization($orgId))
            ->whereNotNull('flags')
            ->where('flags', '!=', '[]')
            ->orderBy('overall_score')
            ->limit($limit)
            ->with('user:id,name,email,mobile_number,avatar_path')
            ->get();

        $alerts = $flaggedClients->flatMap(function ($score) {
            $flags = $score->flags ?? [];
            return collect($flags)->map(fn ($flag) => [
                'user_id'    => $score->user_id,
                'user_name'  => $score->user?->name,
                'user_email' => $score->user?->email,
                'flag'       => $flag,
                'flag_label' => self::flagLabel($flag),
                'severity'   => self::flagSeverity($flag),
                'score'      => $score->overall_score,
                'risk_level' => $score->risk_level,
            ]);
        })
        ->sortBy('score')
        ->sortByDesc(fn ($a) => $a['severity'] === 'critical' ? 2 : ($a['severity'] === 'high' ? 1 : 0))
        ->values()
        ->take($limit);

        return $this->success($alerts);
    }

    /**
     * GET /api/v1/admin/clients/{user}
     * Full client profile.
     */
    public function show(Request $request, User $user): JsonResponse
    {
        $orgId = $this->resolveOrganizationId($request);

        $children = Child::where('user_id', $user->id)
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->get();

        $childIds = $children->pluck('id')->toArray();

        $healthScore = ClientHealthScore::where('user_id', $user->id)
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->first();

        // Per-child metrics
        $childrenData = $children->map(function ($child) use ($orgId) {
            $upcomingBookings = Lesson::whereHas('children', fn ($q) => $q->where('children.id', $child->id))
                ->where('start_time', '>', now())
                ->whereNotIn('status', ['cancelled', 'draft'])
                ->count();

            $credits = ServiceCredit::where('child_id', $child->id)
                ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
                ->get();

            $attendanceRecords = Attendance::where('child_id', $child->id)
                ->where('created_at', '>=', now()->subDays(30))
                ->get();
            $attendTotal = $attendanceRecords->count();
            $attendPresent = $attendanceRecords->where('status', 'present')->count();

            return [
                'id'                 => $child->id,
                'name'               => $child->child_name,
                'year_group'         => $child->year_group,
                'upcoming_bookings'  => $upcomingBookings,
                'credits'            => $credits->map(fn ($c) => [
                    'service_id' => $c->service_id,
                    'remaining'  => $c->remaining,
                    'total'      => $c->total_credits,
                    'expires_at' => $c->expires_at?->toIso8601String(),
                ]),
                'attendance_rate'    => $attendTotal > 0 ? round(($attendPresent / $attendTotal) * 100) : null,
                'recent_submissions' => AssessmentSubmission::where('child_id', $child->id)
                    ->orderByDesc('created_at')
                    ->limit(3)
                    ->get(['id', 'score', 'status', 'created_at']),
            ];
        });

        // Recent transactions
        $recentTransactions = Transaction::where('user_id', $user->id)
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'type', 'status', 'total', 'paid_at', 'created_at']);

        // Active subscriptions
        $subscriptions = DB::table('user_subscriptions')
            ->join('subscriptions', 'subscriptions.id', '=', 'user_subscriptions.subscription_id')
            ->where('user_subscriptions.user_id', $user->id)
            ->where('user_subscriptions.status', 'active')
            ->select([
                'subscriptions.id', 'subscriptions.name', 'subscriptions.price',
                'user_subscriptions.starts_at', 'user_subscriptions.ends_at', 'user_subscriptions.status',
            ])
            ->get();

        // Conversation summary
        $conversation = Conversation::where('contact_user_id', $user->id)
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->orderByDesc('last_message_at')
            ->first();

        return $this->success([
            'user' => [
                'id'     => $user->id,
                'name'   => $user->name,
                'email'  => $user->email,
                'mobile' => $user->mobile_number,
                'avatar' => $user->avatar_path ? '/storage/' . $user->avatar_path : null,
                'billing_customer_id' => $user->billing_customer_id,
            ],
            'health_score' => $healthScore ? [
                'overall'       => $healthScore->overall_score,
                'booking'       => $healthScore->booking_score,
                'payment'       => $healthScore->payment_score,
                'engagement'    => $healthScore->engagement_score,
                'communication' => $healthScore->communication_score,
                'risk_level'    => $healthScore->risk_level,
                'flags'         => $healthScore->flags ?? [],
                'computed_at'   => $healthScore->computed_at?->toIso8601String(),
            ] : null,
            'children'     => $childrenData,
            'transactions' => $recentTransactions,
            'subscriptions' => $subscriptions,
            'conversation'  => $conversation ? [
                'id'              => $conversation->id,
                'status'          => $conversation->status,
                'unread_count'    => $conversation->unread_count,
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
                'last_check_in_at' => $conversation->last_check_in_at?->toIso8601String(),
            ] : null,
        ]);
    }

    /**
     * GET /api/v1/admin/clients/{user}/timeline
     * Unified activity timeline across all systems.
     */
    public function timeline(Request $request, User $user): JsonResponse
    {
        $orgId = $this->resolveOrganizationId($request);
        $perPage = min($request->integer('per_page', 20), 50);

        $childIds = Child::where('user_id', $user->id)
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->pluck('id')
            ->toArray();

        $events = collect();

        // Transactions
        Transaction::where('user_id', $user->id)
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->orderByDesc('created_at')
            ->limit(50)
            ->each(function ($tx) use (&$events) {
                $events->push([
                    'type'        => 'transaction',
                    'timestamp'   => $tx->created_at->toIso8601String(),
                    'title'       => ucfirst($tx->type) . ' — £' . number_format($tx->total, 2),
                    'description' => "Status: {$tx->status}",
                    'metadata'    => ['id' => $tx->id, 'status' => $tx->status, 'total' => $tx->total],
                ]);
            });

        // Lessons (bookings)
        if (!empty($childIds)) {
            Lesson::whereHas('children', fn ($q) => $q->whereIn('children.id', $childIds))
                ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
                ->orderByDesc('start_time')
                ->limit(50)
                ->each(function ($lesson) use (&$events) {
                    $events->push([
                        'type'        => 'lesson',
                        'timestamp'   => ($lesson->start_time ?? $lesson->created_at)->toIso8601String(),
                        'title'       => $lesson->title ?? 'Session',
                        'description' => "Status: {$lesson->status}",
                        'metadata'    => ['id' => $lesson->id, 'status' => $lesson->status],
                    ]);
                });

            // Attendance
            Attendance::whereIn('child_id', $childIds)
                ->orderByDesc('created_at')
                ->limit(30)
                ->each(function ($att) use (&$events) {
                    $events->push([
                        'type'        => 'attendance',
                        'timestamp'   => $att->created_at->toIso8601String(),
                        'title'       => 'Attendance: ' . ucfirst($att->status),
                        'description' => "Child #{$att->child_id}",
                        'metadata'    => ['id' => $att->id, 'status' => $att->status, 'child_id' => $att->child_id],
                    ]);
                });

            // Assessment Submissions
            AssessmentSubmission::whereIn('child_id', $childIds)
                ->orderByDesc('created_at')
                ->limit(30)
                ->each(function ($sub) use (&$events) {
                    $events->push([
                        'type'        => 'assessment',
                        'timestamp'   => $sub->created_at->toIso8601String(),
                        'title'       => 'Assessment submission',
                        'description' => "Score: {$sub->score} — Status: {$sub->status}",
                        'metadata'    => ['id' => $sub->id, 'score' => $sub->score, 'status' => $sub->status],
                    ]);
                });
        }

        // Communication Messages
        $conversationIds = Conversation::where('contact_user_id', $user->id)
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->pluck('id');

        if ($conversationIds->isNotEmpty()) {
            CommunicationMessage::whereIn('conversation_id', $conversationIds)
                ->orderByDesc('created_at')
                ->limit(30)
                ->each(function ($msg) use (&$events) {
                    $events->push([
                        'type'        => 'message',
                        'timestamp'   => $msg->created_at->toIso8601String(),
                        'title'       => ucfirst($msg->sender_type) . ' message',
                        'description' => mb_substr($msg->content ?? '', 0, 100),
                        'metadata'    => ['id' => $msg->id, 'sender_type' => $msg->sender_type, 'channel' => $msg->channel ?? null],
                    ]);
                });
        }

        // Admin Tasks
        AdminTask::where(function ($q) use ($user) {
                $q->whereJsonContains('metadata->user_id', $user->id)
                  ->orWhere('source_model_type', User::class)->where('source_model_id', $user->id);
            })
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->orderByDesc('created_at')
            ->limit(20)
            ->each(function ($task) use (&$events) {
                $events->push([
                    'type'        => 'task',
                    'timestamp'   => $task->created_at->toIso8601String(),
                    'title'       => $task->title,
                    'description' => "Priority: {$task->priority} — Status: {$task->status}",
                    'metadata'    => ['id' => $task->id, 'status' => $task->status, 'priority' => $task->priority],
                ]);
            });

        // Sort all events by timestamp descending and paginate
        $sorted = $events->sortByDesc('timestamp')->values();
        $page = $request->integer('page', 1);
        $sliced = $sorted->forPage($page, $perPage)->values();

        return $this->success([
            'events'       => $sliced,
            'total'        => $sorted->count(),
            'current_page' => $page,
            'per_page'     => $perPage,
            'total_pages'  => (int) ceil($sorted->count() / $perPage),
        ]);
    }

    /**
     * POST /api/v1/admin/clients/{user}/refresh-score
     */
    public function refreshScore(Request $request, User $user, ClientHealthService $service): JsonResponse
    {
        $orgId = $this->resolveOrganizationId($request);
        if (!$orgId) {
            return $this->error('Organization ID is required.', [], 422);
        }

        $score = $service->computeForParent($user, $orgId);

        return $this->success([
            'overall'       => $score->overall_score,
            'booking'       => $score->booking_score,
            'payment'       => $score->payment_score,
            'engagement'    => $score->engagement_score,
            'communication' => $score->communication_score,
            'risk_level'    => $score->risk_level,
            'flags'         => $score->flags,
            'computed_at'   => $score->computed_at->toIso8601String(),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════
     |  ON-BEHALF ACTION ENDPOINTS
     | ═══════════════════════════════════════════════════════════════ */

    /**
     * POST /api/v1/admin/clients/{user}/actions/book
     * Book a session on behalf of a parent (uses service credits).
     */
    public function bookOnBehalf(Request $request, User $user, TeacherScheduleService $scheduleService): JsonResponse
    {
        $request->validate([
            'child_id'    => 'required|integer|exists:children,id',
            'service_id'  => 'required|integer|exists:services,id',
            'teacher_id'  => 'required|integer|exists:users,id',
            'start_time'  => 'required|date|after:now',
            'lesson_mode' => 'nullable|in:in_person,online',
            'notes'       => 'nullable|string|max:500',
        ]);

        $admin = $request->user();
        $service = Service::findOrFail($request->service_id);
        $childId = $request->child_id;
        $teacherId = $request->teacher_id;
        $start = Carbon::parse($request->start_time);
        $duration = $service->session_duration_minutes ?? 60;
        $end = $start->copy()->addMinutes($duration);

        // Verify child belongs to this parent
        $child = Child::where('id', $childId)->where('user_id', $user->id)->first();
        if (!$child) {
            return $this->error('Child does not belong to this parent.', [], 422);
        }

        // Check slot availability
        if (!$scheduleService->isSlotAvailable($teacherId, $start, $end)) {
            return $this->error('This time slot is no longer available.', [], 409);
        }

        // Credit check
        if ($service->isCreditBased()) {
            $credit = ServiceCredit::where('child_id', $childId)
                ->where('service_id', $service->id)
                ->valid()
                ->first();

            if (!$credit || $credit->remaining < 1) {
                return $this->error('No available credits for this service.', [], 422);
            }
        }

        DB::beginTransaction();
        try {
            // Create the lesson
            $lesson = Lesson::create([
                'title'           => ($service->service_name ?? 'Session') . ' — ' . $child->child_name,
                'service_id'      => $service->id,
                'instructor_id'   => $teacherId,
                'start_time'      => $start,
                'end_time'        => $end,
                'status'          => 'scheduled',
                'lesson_type'     => '1:1',
                'lesson_mode'     => $request->lesson_mode ?? $service->default_lesson_mode ?? 'online',
                'organization_id' => $child->organization_id,
                'notes'           => $request->notes,
            ]);

            $lesson->children()->attach($childId);

            // Use credit
            if ($service->isCreditBased()) {
                $credit = ServiceCredit::where('child_id', $childId)
                    ->where('service_id', $service->id)
                    ->valid()
                    ->first();
                $credit?->useCredit();
            }

            DB::commit();

            // Audit log
            AuditLogger::log('admin_book_on_behalf', $lesson, [
                'acting_admin_id' => $admin->id,
                'target_user_id'  => $user->id,
                'child_id'        => $childId,
                'service_id'      => $service->id,
            ]);

            return $this->success([
                'lesson_id'  => $lesson->id,
                'start_time' => $lesson->start_time->toIso8601String(),
                'end_time'   => $lesson->end_time->toIso8601String(),
                'status'     => $lesson->status,
            ], [], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('bookOnBehalf failed', ['error' => $e->getMessage()]);
            return $this->error('Failed to create booking.', [], 500);
        }
    }

    /**
     * POST /api/v1/admin/clients/{user}/actions/purchase
     * Purchase a service on behalf of a parent (creates invoice via BillingService).
     */
    public function purchaseOnBehalf(Request $request, User $user, BillingService $billing): JsonResponse
    {
        $request->validate([
            'service_id' => 'required|integer|exists:services,id',
            'child_id'   => 'required|integer|exists:children,id',
            'quantity'   => 'nullable|integer|min:1|max:10',
        ]);

        $admin = $request->user();
        $orgId = $this->resolveOrganizationId($request);
        $service = Service::findOrFail($request->service_id);
        $quantity = $request->integer('quantity', 1);

        // Verify child belongs to parent
        $child = Child::where('id', $request->child_id)->where('user_id', $user->id)->first();
        if (!$child) {
            return $this->error('Child does not belong to this parent.', [], 422);
        }

        // Ensure billing customer exists
        $customerId = $this->ensureBillingCustomer($user, $billing);
        if (!$customerId) {
            return $this->error('Unable to create billing customer. Please check billing configuration.', [], 503);
        }

        DB::beginTransaction();
        try {
            $unitPrice = $service->price ?? 0;
            $total = $unitPrice * $quantity;

            // Create local transaction
            $transaction = Transaction::create([
                'user_id'         => $user->id,
                'user_email'      => $user->email,
                'organization_id' => $orgId,
                'type'            => 'purchase',
                'status'          => 'pending',
                'payment_method'  => 'billing',
                'subtotal'        => $total,
                'discount'        => 0,
                'total'           => $total,
                'meta'            => [
                    'admin_initiated' => true,
                    'acting_admin_id' => $admin->id,
                    'child_id'        => $child->id,
                ],
            ]);

            $transaction->items()->create([
                'item_type'   => Service::class,
                'item_id'     => $service->id,
                'description' => $service->service_name ?? $service->display_name ?? 'Service',
                'qty'         => $quantity,
                'unit_price'  => $unitPrice,
                'line_total'  => $total,
            ]);

            // Create invoice in billing system
            $invoiceId = $billing->createInvoice([
                'customer_id' => $customerId,
                'due_date'    => now()->addDays(7)->toDateString(),
                'description' => "Service purchase: {$service->service_name} (admin-initiated)",
                'items'       => [[
                    'description' => $service->service_name ?? 'Service',
                    'quantity'    => $quantity,
                    'unit_amount' => (int) ($unitPrice * 100),
                    'currency'    => 'gbp',
                ]],
                'currency'  => 'gbp',
                'auto_bill' => true,
                'status'    => 'open',
            ]);

            if ($invoiceId) {
                $transaction->invoice_id = $invoiceId;
                $transaction->save();

                // Attempt auto-pay
                $autopayResult = $billing->enableAutopay($invoiceId);
                if ($autopayResult['success'] ?? false) {
                    $transaction->status = Transaction::STATUS_PAID;
                    $transaction->paid_at = now();
                    $transaction->save();

                    // Grant access / credits
                    if (class_exists(GrantAccessForTransactionJob::class)) {
                        GrantAccessForTransactionJob::dispatch($transaction->id);
                    }
                }
            }

            DB::commit();

            // Audit log
            AuditLogger::log('admin_purchase_on_behalf', $transaction, [
                'acting_admin_id'  => $admin->id,
                'target_user_id'   => $user->id,
                'child_id'         => $child->id,
                'service_id'       => $service->id,
                'invoice_id'       => $invoiceId,
            ]);

            return $this->success([
                'transaction_id' => $transaction->id,
                'invoice_id'     => $invoiceId,
                'status'         => $transaction->status,
                'total'          => $transaction->total,
                'payment_link'   => $invoiceId ? $billing->getPaymentLink($invoiceId) : null,
            ], [], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('purchaseOnBehalf failed', ['error' => $e->getMessage()]);
            return $this->error('Failed to process purchase.', [], 500);
        }
    }

    /**
     * POST /api/v1/admin/clients/{user}/actions/subscribe
     * Subscribe a parent on behalf (creates subscription invoice via BillingService).
     */
    public function subscribeOnBehalf(Request $request, User $user, BillingService $billing): JsonResponse
    {
        $request->validate([
            'subscription_id' => 'required|integer|exists:subscriptions,id',
            'child_id'        => 'nullable|integer|exists:children,id',
        ]);

        $admin = $request->user();
        $orgId = $this->resolveOrganizationId($request);
        $subscription = Subscription::findOrFail($request->subscription_id);

        // Ensure billing customer
        $customerId = $this->ensureBillingCustomer($user, $billing);
        if (!$customerId) {
            return $this->error('Unable to create billing customer.', [], 503);
        }

        DB::beginTransaction();
        try {
            $price = $subscription->price ?? 0;

            // Create local transaction
            $transaction = Transaction::create([
                'user_id'         => $user->id,
                'user_email'      => $user->email,
                'organization_id' => $orgId,
                'type'            => 'subscription',
                'status'          => 'pending',
                'payment_method'  => 'billing',
                'subtotal'        => $price,
                'discount'        => 0,
                'total'           => $price,
                'meta'            => [
                    'admin_initiated'  => true,
                    'acting_admin_id'  => $admin->id,
                    'subscription_id'  => $subscription->id,
                    'child_id'         => $request->child_id,
                ],
            ]);

            // Create invoice
            $invoiceId = $billing->createInvoice([
                'customer_id' => $customerId,
                'due_date'    => now()->addDays(7)->toDateString(),
                'description' => "Subscription: {$subscription->name} (admin-initiated)",
                'items'       => [[
                    'description' => $subscription->name,
                    'quantity'    => 1,
                    'unit_amount' => (int) ($price * 100),
                    'currency'    => 'gbp',
                ]],
                'currency'  => 'gbp',
                'auto_bill' => true,
                'status'    => 'open',
            ]);

            if ($invoiceId) {
                $transaction->invoice_id = $invoiceId;
                $transaction->save();

                $autopayResult = $billing->enableAutopay($invoiceId);
                if ($autopayResult['success'] ?? false) {
                    $transaction->status = Transaction::STATUS_PAID;
                    $transaction->paid_at = now();
                    $transaction->save();

                    // Create the user_subscription record
                    DB::table('user_subscriptions')->insert([
                        'user_id'         => $user->id,
                        'subscription_id' => $subscription->id,
                        'child_id'        => $request->child_id,
                        'status'          => 'active',
                        'starts_at'       => now(),
                        'ends_at'         => $subscription->billing_interval === 'yearly'
                            ? now()->addYear()
                            : now()->addMonth(),
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ]);
                }
            }

            DB::commit();

            AuditLogger::log('admin_subscribe_on_behalf', $transaction, [
                'acting_admin_id'  => $admin->id,
                'target_user_id'   => $user->id,
                'subscription_id'  => $subscription->id,
                'invoice_id'       => $invoiceId,
            ]);

            return $this->success([
                'transaction_id' => $transaction->id,
                'invoice_id'     => $invoiceId,
                'status'         => $transaction->status,
                'payment_link'   => $invoiceId ? $billing->getPaymentLink($invoiceId) : null,
            ], [], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('subscribeOnBehalf failed', ['error' => $e->getMessage()]);
            return $this->error('Failed to process subscription.', [], 500);
        }
    }

    /**
     * POST /api/v1/admin/clients/{user}/actions/message
     * Send a message to a parent on behalf.
     */
    public function messageOnBehalf(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'content' => 'required|string|max:2000',
            'channel' => 'nullable|in:in_app,email,sms,whatsapp',
        ]);

        $admin = $request->user();
        $orgId = $this->resolveOrganizationId($request);
        $channel = $request->input('channel', 'in_app');

        // Find or create conversation
        $conversation = Conversation::firstOrCreate(
            [
                'contact_user_id' => $user->id,
                'organization_id' => $orgId,
            ],
            [
                'contact_name'  => $user->name,
                'contact_email' => $user->email,
                'contact_phone' => $user->mobile_number,
                'status'        => Conversation::STATUS_OPEN,
            ]
        );

        // Create message
        $message = CommunicationMessage::create([
            'conversation_id' => $conversation->id,
            'sender_type'     => 'admin',
            'sender_id'       => $admin->id,
            'content'         => $request->content,
            'channel'         => $channel,
            'status'          => 'queued',
        ]);

        // Update conversation
        $conversation->update([
            'last_message_at'    => now(),
            'last_check_in_at'   => now(),
            'last_check_in_type' => 'message',
            'last_check_in_by'   => $admin->id,
        ]);

        AuditLogger::log('admin_message_on_behalf', $message, [
            'acting_admin_id' => $admin->id,
            'target_user_id'  => $user->id,
            'channel'         => $channel,
        ]);

        return $this->success([
            'message_id'      => $message->id,
            'conversation_id' => $conversation->id,
            'status'          => $message->status,
        ], [], 201);
    }

    /* ═══════════════════════════════════════════════════════════════
     |  HELPERS
     | ═══════════════════════════════════════════════════════════════ */

    private function resolveOrganizationId(Request $request): ?int
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?: $user?->current_organization_id;

        if ($user?->isSuperAdmin() && $request->filled('organization_id')) {
            return $request->integer('organization_id');
        }

        return $orgId ? (int) $orgId : null;
    }

    private function ensureBillingCustomer(User $user, BillingService $billing): ?string
    {
        if ($user->billing_customer_id) {
            return $user->billing_customer_id;
        }

        $customerId = $billing->createCustomer($user);
        if ($customerId) {
            $user->billing_customer_id = $customerId;
            $user->save();
        }

        return $customerId;
    }

    private static function determinePrimaryAction(array $flags, ?ClientHealthScore $hs): ?array
    {
        // Priority order — most urgent actionable item first
        $actionMap = [
            'failed_payment'       => ['action' => 'Chase payment',       'verb' => 'Follow up on failed payment', 'tone' => 'red'],
            'inactive_30d'         => ['action' => 'Re-engage',           'verb' => 'Reach out — inactive 30+ days', 'tone' => 'red'],
            'high_value_at_risk'   => ['action' => 'Retain client',       'verb' => 'High-value client needs attention', 'tone' => 'red'],
            'unresponsive_7d'      => ['action' => 'Follow up',           'verb' => 'No response to messages in 7+ days', 'tone' => 'amber'],
            'no_upcoming_bookings' => ['action' => 'Book session',        'verb' => 'No upcoming sessions — suggest booking', 'tone' => 'amber'],
            'low_attendance'       => ['action' => 'Check attendance',    'verb' => 'Attendance below 70% — check in', 'tone' => 'amber'],
            'credits_depleted'     => ['action' => 'Upsell credits',     'verb' => 'Credits used up — offer renewal', 'tone' => 'amber'],
            'pending_payment'      => ['action' => 'Chase payment',       'verb' => 'Outstanding invoice pending', 'tone' => 'amber'],
            'credits_expiring'     => ['action' => 'Use credits',         'verb' => 'Credits expiring soon — book sessions', 'tone' => 'amber'],
            'no_subscription'      => ['action' => 'Offer subscription',  'verb' => 'No active subscription — upsell', 'tone' => 'gray'],
        ];

        foreach ($actionMap as $flag => $action) {
            if (in_array($flag, $flags)) {
                return $action;
            }
        }

        // No flags but low score
        if ($hs && $hs->overall_score < 50) {
            return ['action' => 'Review', 'verb' => 'Score below 50 — review client status', 'tone' => 'amber'];
        }

        return null; // Healthy, no action needed
    }

    private static function flagLabel(string $flag): string
    {
        return match ($flag) {
            'no_upcoming_bookings' => 'No upcoming bookings',
            'credits_expiring'     => 'Credits expiring soon',
            'credits_depleted'     => 'Credits depleted',
            'failed_payment'       => 'Failed payment',
            'pending_payment'      => 'Pending payment',
            'no_subscription'      => 'No active subscription',
            'inactive_30d'         => 'Inactive 30+ days',
            'low_attendance'       => 'Low attendance',
            'unresponsive_7d'      => 'Unresponsive 7+ days',
            'high_value_at_risk'   => 'High-value client at risk',
            default                => ucfirst(str_replace('_', ' ', $flag)),
        };
    }

    private static function flagSeverity(string $flag): string
    {
        return match ($flag) {
            'failed_payment', 'high_value_at_risk', 'inactive_30d' => 'critical',
            'no_upcoming_bookings', 'credits_depleted', 'unresponsive_7d' => 'high',
            default => 'medium',
        };
    }
}
