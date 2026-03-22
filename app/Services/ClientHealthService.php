<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AssessmentSubmission;
use App\Models\Child;
use App\Models\ClientHealthScore;
use App\Models\Conversation;
use App\Models\CommunicationMessage;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Organization;
use App\Models\ServiceCredit;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClientHealthService
{
    /* ─── Score Weights ─── */
    private const WEIGHT_BOOKING       = 0.30;
    private const WEIGHT_PAYMENT       = 0.25;
    private const WEIGHT_ENGAGEMENT    = 0.25;
    private const WEIGHT_COMMUNICATION = 0.20;

    /**
     * Compute health scores for all parents in an organization (chunked).
     */
    public function computeForOrganization(int $orgId): int
    {
        $count = 0;

        User::where('role', 'parent')
            ->whereHas('organizations', fn ($q) => $q->where('organizations.id', $orgId))
            ->chunkById(50, function ($parents) use ($orgId, &$count) {
                foreach ($parents as $parent) {
                    try {
                        $this->computeForParent($parent, $orgId);
                        $count++;
                    } catch (\Throwable $e) {
                        Log::error('ClientHealthService: failed to compute for parent', [
                            'user_id' => $parent->id,
                            'org_id'  => $orgId,
                            'error'   => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $count;
    }

    /**
     * Compute and upsert health score for a single parent within an organization.
     */
    public function computeForParent(User $parent, int $orgId): ClientHealthScore
    {
        $childIds = Child::where('user_id', $parent->id)
            ->where('organization_id', $orgId)
            ->pluck('id')
            ->toArray();

        $bookingData       = $this->computeBookingData($parent, $orgId, $childIds);
        $paymentData       = $this->computePaymentData($parent, $orgId);
        $engagementData    = $this->computeEngagementData($parent, $orgId, $childIds);
        $communicationData = $this->computeCommunicationData($parent, $orgId);

        $bookingScore       = $this->calculateBookingScore($bookingData);
        $paymentScore       = $this->calculatePaymentScore($paymentData);
        $engagementScore    = $this->calculateEngagementScore($engagementData);
        $communicationScore = $this->calculateCommunicationScore($communicationData);

        $overallScore = (int) round(
            ($bookingScore * self::WEIGHT_BOOKING)
            + ($paymentScore * self::WEIGHT_PAYMENT)
            + ($engagementScore * self::WEIGHT_ENGAGEMENT)
            + ($communicationScore * self::WEIGHT_COMMUNICATION)
        );

        $metadata = [
            'booking'       => $bookingData,
            'payment'       => $paymentData,
            'engagement'    => $engagementData,
            'communication' => $communicationData,
            'child_ids'     => $childIds,
        ];

        $flags = $this->generateFlags($bookingData, $paymentData, $engagementData, $communicationData, $overallScore);

        return ClientHealthScore::updateOrCreate(
            ['user_id' => $parent->id, 'organization_id' => $orgId],
            [
                'overall_score'       => $overallScore,
                'booking_score'       => $bookingScore,
                'payment_score'       => $paymentScore,
                'engagement_score'    => $engagementScore,
                'communication_score' => $communicationScore,
                'flags'               => $flags,
                'metadata'            => $metadata,
                'last_booking_at'     => $bookingData['last_completed_at'],
                'last_payment_at'     => $paymentData['last_paid_at'],
                'last_message_at'     => $communicationData['last_parent_message_at'],
                'last_login_at'       => null, // User model does not track last_login_at
                'computed_at'         => now(),
            ]
        );
    }

    /* ═══════════════════════════════════════════════════════════════
     |  DATA GATHERING
     | ═══════════════════════════════════════════════════════════════ */

    private function computeBookingData(User $parent, int $orgId, array $childIds): array
    {
        if (empty($childIds)) {
            return [
                'upcoming_count'       => 0,
                'sessions_last_30d'    => 0,
                'last_completed_at'    => null,
                'credits_remaining'    => 0,
                'credits_expiring_7d'  => 0,
                'credits_depleted'     => 0,
                'has_active_service'   => false,
            ];
        }

        $upcomingCount = Lesson::whereHas('children', fn ($q) => $q->whereIn('children.id', $childIds))
            ->where('start_time', '>', now())
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->count();

        $sessionsLast30d = Lesson::whereHas('children', fn ($q) => $q->whereIn('children.id', $childIds))
            ->where('start_time', '>=', now()->subDays(30))
            ->where('start_time', '<=', now())
            ->where('status', 'completed')
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->count();

        $lastCompleted = Lesson::whereHas('children', fn ($q) => $q->whereIn('children.id', $childIds))
            ->where('status', 'completed')
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->max('start_time');

        $credits = ServiceCredit::whereIn('child_id', $childIds)
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->get();

        $creditsRemaining = $credits->sum(fn ($c) => max(0, $c->total_credits - $c->used_credits));
        $creditsExpiring7d = $credits->filter(fn ($c) =>
            $c->expires_at && $c->expires_at->between(now(), now()->addDays(7)) && $c->remaining > 0
        )->count();
        $creditsDepleted = $credits->filter(fn ($c) => $c->remaining <= 0)->count();

        $hasActiveService = $credits->contains(fn ($c) => $c->isValid());

        return [
            'upcoming_count'       => $upcomingCount,
            'sessions_last_30d'    => $sessionsLast30d,
            'last_completed_at'    => $lastCompleted ? Carbon::parse($lastCompleted) : null,
            'credits_remaining'    => $creditsRemaining,
            'credits_expiring_7d'  => $creditsExpiring7d,
            'credits_depleted'     => $creditsDepleted,
            'has_active_service'   => $hasActiveService,
        ];
    }

    private function computePaymentData(User $parent, int $orgId): array
    {
        $transactions = Transaction::where('user_id', $parent->id)
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->get();

        $failedLast30d = $transactions->filter(fn ($t) =>
            $t->status === Transaction::STATUS_FAILED
            && $t->created_at->gte(now()->subDays(30))
        )->count();

        $pendingCount = $transactions->where('status', Transaction::STATUS_PENDING)->count();

        $lastPaid = $transactions->where('status', Transaction::STATUS_PAID)
            ->sortByDesc('paid_at')
            ->first();

        $hasActiveSubscription = DB::table('user_subscriptions')
            ->where('user_id', $parent->id)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->exists();

        $totalLifetimeSpend = $transactions->where('status', Transaction::STATUS_PAID)->sum('total');

        return [
            'failed_last_30d'        => $failedLast30d,
            'pending_count'          => $pendingCount,
            'last_paid_at'           => $lastPaid?->paid_at,
            'has_active_subscription' => $hasActiveSubscription,
            'total_lifetime_spend'   => $totalLifetimeSpend,
        ];
    }

    private function computeEngagementData(User $parent, int $orgId, array $childIds): array
    {
        if (empty($childIds)) {
            return [
                'last_login_at'         => null,
                'attendance_rate_30d'   => null,
                'submissions_last_14d'  => 0,
                'lesson_progress_14d'   => 0,
                'has_any_activity_30d'  => false,
            ];
        }

        // Attendance rate over last 30 days
        $attendanceRecords = Attendance::whereIn('child_id', $childIds)
            ->where('created_at', '>=', now()->subDays(30))
            ->get();

        $totalAttendance = $attendanceRecords->count();
        $presentCount = $attendanceRecords->where('status', 'present')->count();
        $attendanceRate = $totalAttendance > 0 ? round(($presentCount / $totalAttendance) * 100) : null;

        // Assessment submissions last 14 days
        $submissionsLast14d = AssessmentSubmission::whereIn('child_id', $childIds)
            ->where('created_at', '>=', now()->subDays(14))
            ->count();

        // Lesson progress last 14 days
        $lessonProgressLast14d = LessonProgress::whereIn('child_id', $childIds)
            ->where('updated_at', '>=', now()->subDays(14))
            ->count();

        $hasAnyActivity30d = $totalAttendance > 0
            || $submissionsLast14d > 0
            || $lessonProgressLast14d > 0;

        return [
            'last_login_at'         => null,
            'attendance_rate_30d'   => $attendanceRate,
            'submissions_last_14d'  => $submissionsLast14d,
            'lesson_progress_14d'   => $lessonProgressLast14d,
            'has_any_activity_30d'  => $hasAnyActivity30d,
        ];
    }

    private function computeCommunicationData(User $parent, int $orgId): array
    {
        $conversation = Conversation::where('contact_user_id', $parent->id)
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->orderByDesc('last_message_at')
            ->first();

        if (!$conversation) {
            return [
                'has_conversation'         => false,
                'conversation_status'      => null,
                'unread_admin_messages'     => 0,
                'last_parent_message_at'   => null,
                'last_check_in_at'         => null,
                'days_since_last_response' => null,
            ];
        }

        // Find last message FROM the parent (sender_type = 'parent')
        $lastParentMessage = CommunicationMessage::where('conversation_id', $conversation->id)
            ->where('sender_type', 'parent')
            ->orderByDesc('created_at')
            ->first();

        // Find last message FROM admin/agent to parent
        $lastAdminMessage = CommunicationMessage::where('conversation_id', $conversation->id)
            ->whereIn('sender_type', ['admin', 'agent', 'system'])
            ->orderByDesc('created_at')
            ->first();

        // Days since admin sent a message that parent hasn't replied to
        $daysSinceResponse = null;
        if ($lastAdminMessage && (!$lastParentMessage || $lastParentMessage->created_at->lt($lastAdminMessage->created_at))) {
            $daysSinceResponse = (int) $lastAdminMessage->created_at->diffInDays(now());
        }

        return [
            'has_conversation'         => true,
            'conversation_status'      => $conversation->status,
            'unread_admin_messages'    => $conversation->unread_count,
            'last_parent_message_at'   => $lastParentMessage?->created_at,
            'last_check_in_at'         => $conversation->last_check_in_at,
            'days_since_last_response' => $daysSinceResponse,
        ];
    }

    /* ═══════════════════════════════════════════════════════════════
     |  SCORE CALCULATION (each returns 0–100)
     | ═══════════════════════════════════════════════════════════════ */

    private function calculateBookingScore(array $data): int
    {
        $score = 50; // base

        if ($data['upcoming_count'] > 0) $score += 25;
        if ($data['sessions_last_30d'] > 0) $score += 10;
        if ($data['last_completed_at'] && Carbon::parse($data['last_completed_at'])->gte(now()->subDays(7))) $score += 15;

        if ($data['upcoming_count'] === 0 && $data['sessions_last_30d'] === 0) $score -= 20;
        if ($data['credits_depleted'] > 0) $score -= 15;
        if ($data['credits_expiring_7d'] > 0) $score -= 10;
        if ($data['has_active_service']) $score += 10;

        return $this->clamp($score);
    }

    private function calculatePaymentScore(array $data): int
    {
        $score = 50;

        if ($data['failed_last_30d'] === 0) $score += 20;
        if ($data['failed_last_30d'] > 0)   $score -= 30;
        if ($data['pending_count'] > 0)      $score -= 15;
        if ($data['has_active_subscription']) $score += 20;
        if ($data['last_paid_at'] && Carbon::parse($data['last_paid_at'])->gte(now()->subDays(30))) $score += 10;

        return $this->clamp($score);
    }

    private function calculateEngagementScore(array $data): int
    {
        $score = 50;

        $lastLogin = $data['last_login_at'] ? Carbon::parse($data['last_login_at']) : null;
        if ($lastLogin && $lastLogin->gte(now()->subDays(7)))  $score += 15;
        elseif ($lastLogin && $lastLogin->gte(now()->subDays(30))) $score += 10;

        if ($data['submissions_last_14d'] > 0) $score += 10;
        if ($data['lesson_progress_14d'] > 0)  $score += 10;

        if ($data['attendance_rate_30d'] !== null) {
            if ($data['attendance_rate_30d'] >= 90) $score += 20;
            elseif ($data['attendance_rate_30d'] >= 70) $score += 10;
            elseif ($data['attendance_rate_30d'] < 50) $score -= 15;
        }

        if (!$data['has_any_activity_30d']) $score -= 25;

        return $this->clamp($score);
    }

    private function calculateCommunicationScore(array $data): int
    {
        $score = 50;

        if (!$data['has_conversation']) {
            // No conversation exists — neutral, slight deduction
            return $this->clamp($score - 10);
        }

        if ($data['last_parent_message_at']) $score += 15;
        if ($data['last_check_in_at'] && Carbon::parse($data['last_check_in_at'])->gte(now()->subDays(14))) $score += 15;
        if ($data['conversation_status'] === 'open') $score += 5;

        if ($data['days_since_last_response'] !== null && $data['days_since_last_response'] >= 7) $score -= 20;
        if ($data['unread_admin_messages'] > 3) $score -= 10;

        return $this->clamp($score);
    }

    /* ═══════════════════════════════════════════════════════════════
     |  FLAG GENERATION
     | ═══════════════════════════════════════════════════════════════ */

    private function generateFlags(array $booking, array $payment, array $engagement, array $communication, int $overallScore): array
    {
        $flags = [];

        // Booking flags
        if ($booking['upcoming_count'] === 0) $flags[] = 'no_upcoming_bookings';
        if ($booking['credits_expiring_7d'] > 0) $flags[] = 'credits_expiring';
        if ($booking['credits_depleted'] > 0) $flags[] = 'credits_depleted';

        // Payment flags
        if ($payment['failed_last_30d'] > 0) $flags[] = 'failed_payment';
        if ($payment['pending_count'] > 0) $flags[] = 'pending_payment';
        if (!$payment['has_active_subscription']) $flags[] = 'no_subscription';

        // Engagement flags
        if (!$engagement['has_any_activity_30d']) $flags[] = 'inactive_30d';
        if ($engagement['attendance_rate_30d'] !== null && $engagement['attendance_rate_30d'] < 70) $flags[] = 'low_attendance';

        // Communication flags
        if ($communication['days_since_last_response'] !== null && $communication['days_since_last_response'] >= 7) {
            $flags[] = 'unresponsive_7d';
        }

        // Composite flags
        if ($overallScore < 40 && ($payment['total_lifetime_spend'] ?? 0) > 500) {
            $flags[] = 'high_value_at_risk';
        }

        return $flags;
    }

    /* ─── Helpers ─── */

    private function clamp(int $score): int
    {
        return max(0, min(100, $score));
    }
}
