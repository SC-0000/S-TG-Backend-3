<?php

namespace App\Services\AI\BackgroundAgents\Agents;

use App\Mail\BrandedMailable;
use App\Models\BackgroundAgentAction;
use App\Models\Child;
use App\Models\User;
use App\Services\AI\BackgroundAgents\AbstractBackgroundAgent;
use App\Support\MailContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ParentManagerAgent extends AbstractBackgroundAgent
{
    public static function getAgentType(): string
    {
        return 'parent_manager';
    }

    public static function getDefaultSchedule(): string
    {
        return '0 9 * * *'; // Daily at 9 AM
    }

    public static function getDescription(): string
    {
        return 'Monitors parent engagement, sends personalised progress updates and re-engagement communications.';
    }

    public static function getEstimatedTokensPerRun(): int
    {
        return 80;
    }

    protected function execute(): void
    {
        if (!$this->organization) {
            return;
        }

        $maxEmailsPerRun = $this->getConfig('max_emails_per_run', 20);
        $maxEmailsPerParentPerWeek = $this->getConfig('max_emails_per_parent_per_week', 2);
        $engagementThreshold = $this->getConfig('engagement_threshold', 40);
        $emailsSent = 0;

        // Get all parent users for this organisation
        $parents = User::whereHas('children', function ($q) {
            $q->where('organization_id', $this->organization->id);
        })->with(['children' => function ($q) {
            $q->where('organization_id', $this->organization->id);
        }])->get();

        foreach ($parents as $parent) {
            if ($emailsSent >= $maxEmailsPerRun || !$this->hasRemainingBudget()) {
                break;
            }

            $this->incrementProcessed();

            // Check weekly email cap
            $recentEmails = BackgroundAgentAction::whereHas('run', function ($q) {
                $q->where('agent_type', static::getAgentType())
                    ->where('organization_id', $this->organization->id);
            })
                ->where('action_type', BackgroundAgentAction::ACTION_SEND_EMAIL)
                ->where('target_type', User::class)
                ->where('target_id', $parent->id)
                ->where('created_at', '>=', now()->subWeek())
                ->count();

            if ($recentEmails >= $maxEmailsPerParentPerWeek) {
                continue;
            }

            // Analyse each child's performance
            foreach ($parent->children as $child) {
                if ($emailsSent >= $maxEmailsPerRun || !$this->hasRemainingBudget()) {
                    break;
                }

                $emailType = $this->determineEmailType($child, $engagementThreshold);

                if ($emailType) {
                    try {
                        $this->sendParentEmail($parent, $child, $emailType);
                        $emailsSent++;
                    } catch (\Exception $e) {
                        $this->logAction(
                            BackgroundAgentAction::ACTION_SEND_EMAIL,
                            $parent,
                            "Failed to send {$emailType} email to {$parent->email}",
                            null, null, 0,
                            BackgroundAgentAction::STATUS_FAILED,
                            $e->getMessage()
                        );
                    }
                }
            }
        }
    }

    /**
     * Determine what type of email (if any) to send for this child.
     */
    protected function determineEmailType(Child $child, int $threshold): ?string
    {
        $performance = $child->calculateOverallPerformance();
        $riskFactors = $child->identifyRiskFactors();
        $achievements = $child->getRecentAchievements();

        // Priority: achievements > risk > disengagement
        if (!empty($achievements)) {
            return 'celebration';
        }

        if (!empty($riskFactors)) {
            return 'supportive';
        }

        // Check for disengagement: no recent submissions or lesson progress
        $lastActivity = $child->assessmentSubmissions()
            ->latest('finished_at')
            ->value('finished_at');

        $daysSinceActivity = $lastActivity ? now()->diffInDays($lastActivity) : 30;

        if ($daysSinceActivity > 7) {
            return 'reengagement';
        }

        return null;
    }

    /**
     * Generate and send a personalised parent email.
     */
    protected function sendParentEmail(User $parent, Child $child, string $emailType): void
    {
        $performance = $child->calculateOverallPerformance();
        $riskFactors = $child->identifyRiskFactors();
        $achievements = $child->getRecentAchievements();

        $context = implode("\n", [
            "Parent Name: {$parent->name}",
            "Child Name: {$child->child_name}",
            "Email Type: {$emailType}",
            "Overall Performance: " . json_encode($performance),
            "Risk Factors: " . json_encode($riskFactors),
            "Recent Achievements: " . json_encode($achievements),
            "Year Group: {$child->year_group}",
        ]);

        $systemPrompt = match ($emailType) {
            'celebration' => 'You are a warm, encouraging educational advisor. Write a brief congratulatory email to a parent celebrating their child\'s achievements. Be specific about what the child accomplished. Keep it to 3-4 paragraphs.',
            'supportive' => 'You are a caring educational advisor. Write a supportive, non-alarming email to a parent about areas where their child could use additional focus. Offer practical suggestions. Keep it to 3-4 paragraphs.',
            'reengagement' => 'You are a friendly educational advisor. Write a warm, encouraging email to a parent whose child hasn\'t been active recently. Motivate them to get back on track without being pushy. Keep it to 2-3 paragraphs.',
            default => 'You are an educational advisor. Write a brief progress update email.',
        };

        $prompt = "Write a personalised email for this parent. Do not include a subject line, just the email body.\n\n{$context}";

        $emailBody = $this->aiGenerateText($prompt, $systemPrompt);

        // Generate subject line
        $subject = match ($emailType) {
            'celebration' => "{$child->child_name}'s Recent Achievements",
            'supportive' => "Supporting {$child->child_name}'s Learning Journey",
            'reengagement' => "We Miss {$child->child_name}!",
            default => "Update on {$child->child_name}'s Progress",
        };

        // Send via branded mail
        $organization = $this->organization;
        Mail::to($parent->email)->send(
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

        $this->incrementAffected();

        $this->logAction(
            BackgroundAgentAction::ACTION_SEND_EMAIL,
            $parent,
            "Sent {$emailType} email to {$parent->email} about {$child->child_name}",
            null,
            ['email_type' => $emailType, 'child_id' => $child->id, 'subject' => $subject]
        );
    }
}
