<?php

namespace App\Services;

use App\Models\Affiliate;
use App\Models\AffiliateConversion;
use App\Models\Application;
use App\Models\CommissionRule;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class CommissionEngine
{
    /**
     * Fire a trigger event and evaluate all matching commission rules.
     *
     * @param string           $trigger      One of CommissionRule::TRIGGERS
     * @param int              $orgId        Organization ID
     * @param User             $referredUser The user who was referred
     * @param Transaction|null $transaction  The transaction (for purchase triggers)
     * @return Collection<AffiliateConversion>  New commissions created
     */
    public function fire(string $trigger, int $orgId, User $referredUser, ?Transaction $transaction = null): Collection
    {
        $created = collect();

        // Find the affiliate who referred this user
        $affiliateId = $this->resolveAffiliateForUser($referredUser, $orgId);
        if (!$affiliateId) {
            return $created;
        }

        $affiliate = Affiliate::where('id', $affiliateId)->where('status', 'active')->first();
        if (!$affiliate) {
            return $created;
        }

        // Get all active rules for this trigger in this org
        $rules = CommissionRule::where('organization_id', $orgId)
            ->where('trigger', $trigger)
            ->where('active', true)
            ->orderByDesc('priority')
            ->get();

        if ($rules->isEmpty()) {
            return $created;
        }

        $transactionTotal = $transaction ? (float) $transaction->total : null;
        $lifetimeSpend = $this->getLifetimeSpend($referredUser->id, $orgId);

        foreach ($rules as $rule) {
            // Check one_time: has this rule already fired for this user+affiliate?
            if ($rule->one_time) {
                $alreadyFired = AffiliateConversion::where('affiliate_id', $affiliate->id)
                    ->where('user_id', $referredUser->id)
                    ->where('commission_rule_id', $rule->id)
                    ->exists();

                if ($alreadyFired) {
                    continue;
                }
            }

            // For every_purchase, check if this specific transaction already has a commission from this rule
            if ($trigger === CommissionRule::TRIGGER_EVERY_PURCHASE && $transaction) {
                $alreadyFired = AffiliateConversion::where('commission_rule_id', $rule->id)
                    ->where('transaction_id', $transaction->id)
                    ->exists();

                if ($alreadyFired) {
                    continue;
                }
            }

            // Check conditions
            if (!$rule->meetsConditions($transactionTotal, $lifetimeSpend)) {
                continue;
            }

            // Calculate commission
            $amount = $rule->calculateCommission($transactionTotal);
            if ($amount <= 0) {
                continue;
            }

            // Find the original application for this user (for linking)
            $applicationId = Application::where('user_id', $referredUser->id)
                ->where('organization_id', $orgId)
                ->value('application_id');

            // Find the tracking link used
            $trackingLinkId = null;
            if ($applicationId) {
                $app = Application::where('application_id', $applicationId)->first();
                if ($app && $app->tracking_code) {
                    $trackingLinkId = \App\Models\TrackingLink::where('code', $app->tracking_code)->value('id');
                }
            }

            $conversion = AffiliateConversion::create([
                'organization_id' => $orgId,
                'tracking_link_id' => $trackingLinkId,
                'affiliate_id' => $affiliate->id,
                'application_id' => $applicationId,
                'user_id' => $referredUser->id,
                'type' => $this->triggerToType($trigger),
                'commission_amount' => $amount,
                'commission_rate_snapshot' => $rule->commission_type === 'percentage'
                    ? (float) $rule->commission_value
                    : null,
                'status' => 'approved', // auto-approved since rule-based
                'attribution_method' => 'rule',
                'commission_rule_id' => $rule->id,
                'trigger_event' => $trigger,
                'transaction_id' => $transaction?->id,
            ]);

            $created->push($conversion);

            Log::info('CommissionEngine: commission created', [
                'rule_id' => $rule->id,
                'rule_name' => $rule->name,
                'affiliate_id' => $affiliate->id,
                'user_id' => $referredUser->id,
                'amount' => $amount,
                'trigger' => $trigger,
            ]);
        }

        return $created;
    }

    /**
     * Convenience: fire signup_approved trigger.
     */
    public function onSignupApproved(int $orgId, User $user): Collection
    {
        $this->recordFunnelEvent($user, $orgId, 'approved');
        return $this->fire(CommissionRule::TRIGGER_SIGNUP_APPROVED, $orgId, $user);
    }

    /**
     * Convenience: fire purchase triggers (first_purchase, every_purchase, spend_threshold).
     */
    public function onTransactionPaid(Transaction $transaction): Collection
    {
        $created = collect();
        $user = $transaction->user;
        $orgId = (int) $transaction->organization_id;

        if (!$user) {
            return $created;
        }

        // Check if this is the user's first paid transaction
        $paidCount = Transaction::where('user_id', $user->id)
            ->where('organization_id', $orgId)
            ->whereIn('status', ['paid', 'completed'])
            ->count();

        if ($paidCount <= 1) {
            $created = $created->merge(
                $this->fire(CommissionRule::TRIGGER_FIRST_PURCHASE, $orgId, $user, $transaction)
            );

            // Record first_purchase funnel event for tracking link analytics
            $this->recordFunnelEvent($user, $orgId, 'first_purchase');
        }

        // Every purchase
        $created = $created->merge(
            $this->fire(CommissionRule::TRIGGER_EVERY_PURCHASE, $orgId, $user, $transaction)
        );

        // Spend threshold
        $created = $created->merge(
            $this->fire(CommissionRule::TRIGGER_SPEND_THRESHOLD, $orgId, $user, $transaction)
        );

        return $created;
    }

    /**
     * Look up which affiliate referred a user (via their application).
     */
    private function resolveAffiliateForUser(User $user, int $orgId): ?int
    {
        // Check applications for this user in this org
        $affiliateId = Application::where('user_id', $user->id)
            ->where('organization_id', $orgId)
            ->whereNotNull('affiliate_id')
            ->value('affiliate_id');

        if ($affiliateId) {
            return (int) $affiliateId;
        }

        // Also check if there's any conversion already pointing to this user
        $affiliateId = AffiliateConversion::where('user_id', $user->id)
            ->where('organization_id', $orgId)
            ->whereNotNull('affiliate_id')
            ->value('affiliate_id');

        return $affiliateId ? (int) $affiliateId : null;
    }

    /**
     * Get lifetime spend for a user in an org.
     */
    private function getLifetimeSpend(int $userId, int $orgId): float
    {
        return (float) Transaction::where('user_id', $userId)
            ->where('organization_id', $orgId)
            ->whereIn('status', ['paid', 'completed'])
            ->sum('total');
    }

    private function triggerToType(string $trigger): string
    {
        return match ($trigger) {
            CommissionRule::TRIGGER_SIGNUP_APPROVED => 'signup',
            CommissionRule::TRIGGER_FIRST_PURCHASE => 'first_purchase',
            CommissionRule::TRIGGER_EVERY_PURCHASE => 'purchase',
            CommissionRule::TRIGGER_SPEND_THRESHOLD => 'spend_threshold',
            default => 'other',
        };
    }

    /**
     * Record a funnel event for a user's original tracking link.
     */
    private function recordFunnelEvent(User $user, int $orgId, string $event): void
    {
        try {
            $app = Application::where('user_id', $user->id)
                ->where('organization_id', $orgId)
                ->whereNotNull('tracking_code')
                ->first();

            if (!$app || !$app->tracking_code) {
                return;
            }

            $link = \App\Models\TrackingLink::where('code', $app->tracking_code)->first();
            if (!$link) {
                return;
            }

            \App\Models\TrackingEvent::create([
                'tracking_link_id' => $link->id,
                'session_hash' => hash('sha256', 'user:' . $user->id),
                'event' => $event,
                'page_path' => null,
                'meta' => ['user_id' => $user->id],
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Never fail the commission flow for analytics
        }
    }
}
