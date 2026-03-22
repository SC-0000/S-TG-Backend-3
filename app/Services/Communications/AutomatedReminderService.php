<?php

namespace App\Services\Communications;

use App\DTOs\SendMessageDTO;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutomatedReminderService
{
    public function __construct(
        protected ChannelDispatcher $dispatcher,
        protected PreferenceService $preferenceService,
    ) {}

    /**
     * Send lesson reminders for upcoming lessons within the configured window.
     */
    public function processLessonReminders(Organization $org): int
    {
        $hoursAhead = (int) $org->getSetting('communications.auto_reminder_lesson_hours_before', 24);
        $window = now()->addHours($hoursAhead);
        $sent = 0;

        // Query upcoming lessons within the reminder window that haven't been reminded
        $lessons = DB::table('live_sessions')
            ->where('organization_id', $org->id)
            ->where('status', '!=', 'cancelled')
            ->whereBetween('start_time', [now(), $window])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('communication_messages')
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(communication_messages.metadata, '$.lesson_id')) = CAST(live_sessions.id AS CHAR)")
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(communication_messages.metadata, '$.type')) = 'lesson_reminder'");
            })
            ->get();

        foreach ($lessons as $lesson) {
            // Get enrolled children → parents
            $parentIds = DB::table('child_live_session')
                ->join('children', 'children.id', '=', 'child_live_session.child_id')
                ->where('child_live_session.live_session_id', $lesson->id)
                ->whereNotNull('children.user_id')
                ->pluck('children.user_id')
                ->unique();

            foreach ($parentIds as $parentId) {
                $parent = User::find($parentId);
                if (!$parent) continue;

                $startTime = Carbon::parse($lesson->start_time)->format('l jS M, g:ia');
                $message = "Reminder: {$parent->name}, you have a lesson \"{$lesson->title}\" starting at {$startTime}.";

                $this->dispatcher->sendViaPreferred(
                    $org,
                    $parentId,
                    'lesson_reminder',
                    $message,
                    "Lesson Reminder: {$lesson->title}",
                    null,
                    'system',
                    null,
                    ['type' => 'lesson_reminder', 'lesson_id' => $lesson->id],
                );
                $sent++;
            }
        }

        if ($sent > 0) {
            Log::info("[AutomatedReminder] Sent {$sent} lesson reminders for org {$org->id}");
        }

        return $sent;
    }

    /**
     * Send payment reminders for overdue transactions.
     */
    public function processPaymentReminders(Organization $org): int
    {
        $daysOverdue = (int) $org->getSetting('communications.auto_reminder_payment_days_overdue', 3);
        $cutoff = now()->subDays($daysOverdue);
        $sent = 0;

        $overdueTransactions = DB::table('transactions')
            ->where('organization_id', $org->id)
            ->whereIn('status', ['pending', 'overdue', 'unpaid'])
            ->where('created_at', '<=', $cutoff)
            ->whereNotNull('user_id')
            ->whereNotExists(function ($q) use ($cutoff) {
                $q->select(DB::raw(1))
                    ->from('communication_messages')
                    ->whereColumn('communication_messages.recipient_user_id', 'transactions.user_id')
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(communication_messages.metadata, '$.type')) = 'payment_reminder'")
                    ->where('communication_messages.created_at', '>=', $cutoff);
            })
            ->limit(50)
            ->get();

        foreach ($overdueTransactions as $transaction) {
            $parent = User::find($transaction->user_id);
            if (!$parent) continue;

            $amount = number_format($transaction->amount / 100, 2);
            $message = "Payment reminder: You have an outstanding balance of £{$amount}. Please make a payment at your earliest convenience.";

            $this->dispatcher->sendViaPreferred(
                $org,
                $parent->id,
                'payment_reminder',
                $message,
                'Payment Reminder',
                null,
                'system',
                null,
                ['type' => 'payment_reminder', 'transaction_id' => $transaction->id],
            );
            $sent++;
        }

        if ($sent > 0) {
            Log::info("[AutomatedReminder] Sent {$sent} payment reminders for org {$org->id}");
        }

        return $sent;
    }

    /**
     * Send homework deadline reminders.
     */
    public function processHomeworkReminders(Organization $org): int
    {
        $sent = 0;
        $window = now()->addHours(24);

        $homeworkDue = DB::table('homework_assignments')
            ->where('organization_id', $org->id)
            ->where('status', 'published')
            ->whereBetween('due_date', [now(), $window])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('communication_messages')
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(communication_messages.metadata, '$.type')) = 'homework_reminder'")
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(communication_messages.metadata, '$.homework_id')) = CAST(homework_assignments.id AS CHAR)");
            })
            ->get();

        foreach ($homeworkDue as $homework) {
            // Get assigned children → parents via homework_targets
            $parentIds = DB::table('homework_targets')
                ->join('children', 'children.id', '=', 'homework_targets.child_id')
                ->where('homework_targets.homework_assignment_id', $homework->id)
                ->whereNotNull('children.user_id')
                ->pluck('children.user_id')
                ->unique();

            foreach ($parentIds as $parentId) {
                $dueDate = Carbon::parse($homework->due_date)->format('l jS M, g:ia');
                $message = "Homework reminder: \"{$homework->title}\" is due by {$dueDate}. Please ensure it's completed on time.";

                $this->dispatcher->sendViaPreferred(
                    $org,
                    $parentId,
                    'homework_deadline',
                    $message,
                    "Homework Due: {$homework->title}",
                    null,
                    'system',
                    null,
                    ['type' => 'homework_reminder', 'homework_id' => $homework->id],
                );
                $sent++;
            }
        }

        return $sent;
    }
}
