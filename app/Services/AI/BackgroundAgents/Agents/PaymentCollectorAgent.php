<?php

namespace App\Services\AI\BackgroundAgents\Agents;

use App\Mail\BrandedMailable;
use App\Models\BackgroundAgentAction;
use App\Services\Tasks\TaskService;
use App\Models\PaymentFollowup;
use App\Models\Transaction;
use App\Services\AI\BackgroundAgents\AbstractBackgroundAgent;
use App\Support\MailContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PaymentCollectorAgent extends AbstractBackgroundAgent
{
    public static function getAgentType(): string
    {
        return 'payment_collector';
    }

    public static function getDefaultSchedule(): string
    {
        return '0 10 * * *'; // Daily at 10 AM
    }

    public static function getDescription(): string
    {
        return 'Identifies overdue payments and sends automated follow-up sequences with escalation.';
    }

    public static function getEstimatedTokensPerRun(): int
    {
        return 30;
    }

    protected function execute(): void
    {
        if (!$this->organization) {
            return;
        }

        $gracePeriodDays = $this->getConfig('grace_period_days', 3);

        // 1. Resolve any followups where payment has been completed
        $this->resolveCompletedPayments();

        // 2. Create new followups for overdue transactions
        $this->createNewFollowups($gracePeriodDays);

        // 3. Process due followups (send emails, escalate)
        $this->processDueFollowups();
    }

    /**
     * Auto-resolve followups where the transaction has been paid.
     */
    protected function resolveCompletedPayments(): void
    {
        $resolved = PaymentFollowup::active()
            ->where('organization_id', $this->organization->id)
            ->whereHas('transaction', function ($q) {
                $q->whereIn('status', ['completed', 'paid', 'successful']);
            })
            ->get();

        foreach ($resolved as $followup) {
            $followup->resolve();
            $this->incrementAffected();

            $this->logAction(
                BackgroundAgentAction::ACTION_UPDATE_RECORD,
                $followup,
                "Auto-resolved followup for transaction #{$followup->transaction_id} (payment completed)"
            );
        }
    }

    /**
     * Create followup records for newly overdue transactions.
     */
    protected function createNewFollowups(int $gracePeriodDays): void
    {
        $overdueTransactions = Transaction::where('organization_id', $this->organization->id)
            ->whereIn('status', ['pending', 'failed', 'overdue'])
            ->where('created_at', '<=', now()->subDays($gracePeriodDays))
            ->whereDoesntHave('paymentFollowup')
            ->with('user')
            ->limit(50)
            ->get();

        foreach ($overdueTransactions as $transaction) {
            if (!$transaction->user) continue;

            PaymentFollowup::create([
                'organization_id' => $this->organization->id,
                'transaction_id' => $transaction->id,
                'user_id' => $transaction->user_id,
                'followup_stage' => PaymentFollowup::STAGE_GENTLE,
                'next_followup_at' => now(),
                'status' => PaymentFollowup::STATUS_ACTIVE,
                'notes' => [
                    ['message' => 'Followup created', 'stage' => 1, 'timestamp' => now()->toISOString()],
                ],
            ]);

            $this->incrementAffected();
        }
    }

    /**
     * Process followups that are due for action.
     */
    protected function processDueFollowups(): void
    {
        $maxPerRun = $this->getConfig('max_followups_per_run', 20);

        $dueFollowups = PaymentFollowup::due()
            ->where('organization_id', $this->organization->id)
            ->with(['transaction', 'user'])
            ->limit($maxPerRun)
            ->get();

        foreach ($dueFollowups as $followup) {
            $this->incrementProcessed();

            if (!$this->hasRemainingBudget()) {
                break;
            }

            try {
                $this->processFollowup($followup);
            } catch (\Exception $e) {
                $this->logAction(
                    BackgroundAgentAction::ACTION_SEND_EMAIL,
                    $followup,
                    "Followup processing failed for transaction #{$followup->transaction_id}",
                    null, null, 0,
                    BackgroundAgentAction::STATUS_FAILED,
                    $e->getMessage()
                );
            }
        }
    }

    /**
     * Process a single followup based on its stage.
     */
    protected function processFollowup(PaymentFollowup $followup): void
    {
        $user = $followup->user;
        $transaction = $followup->transaction;

        if (!$user || !$transaction) {
            return;
        }

        if ($followup->followup_stage >= PaymentFollowup::STAGE_ESCALATED) {
            $this->escalateToAdmin($followup);
            return;
        }

        // Generate email based on stage
        $toneMap = [
            PaymentFollowup::STAGE_GENTLE => 'friendly and helpful',
            PaymentFollowup::STAGE_FIRM => 'professional and direct',
            PaymentFollowup::STAGE_FINAL => 'formal and urgent',
        ];

        $tone = $toneMap[$followup->followup_stage] ?? 'professional';
        $amount = $transaction->amount ?? 'the outstanding amount';

        $prompt = implode("\n", [
            "Write a {$tone} payment reminder email. Do not include a subject line.",
            "",
            "Customer Name: {$user->name}",
            "Outstanding Amount: £{$amount}",
            "Original Date: " . ($transaction->created_at?->format('F j, Y') ?? 'N/A'),
            "Reminder Stage: {$followup->followup_stage} of 3",
            "",
            "Keep it to 2-3 paragraphs. Be empathetic but clear about the need for payment.",
        ]);

        $emailBody = $this->aiGenerateText(
            $prompt,
            'You are a professional accounts team member for an education company. Write polite, empathetic payment reminders.'
        );

        $subjectMap = [
            PaymentFollowup::STAGE_GENTLE => 'Friendly Payment Reminder',
            PaymentFollowup::STAGE_FIRM => 'Payment Reminder - Action Required',
            PaymentFollowup::STAGE_FINAL => 'Final Payment Notice',
        ];
        $subject = $subjectMap[$followup->followup_stage] ?? 'Payment Reminder';

        // Send email
        $organization = $this->organization;
        Mail::to($user->email)->send(
            new class($emailBody, $subject, $organization) extends BrandedMailable {
                public string $emailBody;

                public function __construct(string $emailBody, string $subject, $organization)
                {
                    parent::__construct($organization);
                    $this->emailBody = $emailBody;
                    $this->subject($subject);
                }

                public function build()
                {
                    return $this->view('emails.generic-branded')
                        ->with(['body' => $this->emailBody]);
                }
            }
        );

        $sentStage = $followup->followup_stage;
        $followup->addNote("Stage {$sentStage} email sent to {$user->email}");

        $this->incrementAffected();

        $this->logAction(
            BackgroundAgentAction::ACTION_SEND_EMAIL,
            $followup,
            "Sent stage {$sentStage} payment reminder to {$user->email} for transaction #{$followup->transaction_id}",
            null,
            ['stage' => $sentStage, 'subject' => $subject]
        );

        // Advance stage AFTER logging to preserve accurate audit trail
        $followup->advanceStage();
    }

    /**
     * Escalate a followup to admin for manual handling.
     */
    protected function escalateToAdmin(PaymentFollowup $followup): void
    {
        // Create an admin task
        TaskService::createFromEvent('payment_followup', [
            'organization_id' => $this->organization->id,
            'title' => "Overdue payment requires manual action - Transaction #{$followup->transaction_id}",
            'description' => "Payment followup has been through all automated stages. Customer: {$followup->user->name}. Amount: £{$followup->transaction->amount}. Please contact them directly.",
            'priority' => 'high',
            'source_model' => $followup,
        ]);

        $followup->update(['status' => PaymentFollowup::STATUS_ESCALATED]);
        $followup->addNote('Escalated to admin for manual handling');

        $this->incrementAffected();

        $this->logAction(
            BackgroundAgentAction::ACTION_CREATE_RECORD,
            $followup,
            "Escalated payment followup to admin for transaction #{$followup->transaction_id}"
        );
    }
}
