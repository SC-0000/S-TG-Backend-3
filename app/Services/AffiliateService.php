<?php

namespace App\Services;

use App\Models\Affiliate;
use App\Models\AffiliateConversion;
use App\Models\AffiliatePayout;
use App\Models\Application;
use App\Models\LinkClick;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AffiliateService
{
    /**
     * Record a click on a tracking link.
     */
    public function recordClick(TrackingLink $link, Request $request): LinkClick
    {
        $link->incrementClicks();

        return LinkClick::create([
            'tracking_link_id' => $link->id,
            'ip_hash' => hash('sha256', $request->ip()),
            'user_agent' => Str::limit($request->userAgent(), 500),
            'referer_url' => Str::limit($request->header('referer'), 500),
            'clicked_at' => now(),
        ]);
    }

    /**
     * Attribute an application to a tracking link/affiliate.
     */
    public function attributeApplication(Application $app, ?string $trackingCode, ?int $affiliateId = null): ?AffiliateConversion
    {
        if (!$trackingCode && !$affiliateId) {
            return null;
        }

        $link = null;
        $resolvedAffiliateId = $affiliateId;

        if ($trackingCode) {
            $link = TrackingLink::where('code', $trackingCode)->first();

            if ($link) {
                $resolvedAffiliateId = $resolvedAffiliateId ?? $link->affiliate_id;

                $app->update([
                    'tracking_code' => $trackingCode,
                    'affiliate_id' => $resolvedAffiliateId,
                ]);
            }
        }

        if (!$resolvedAffiliateId && !$link) {
            return null;
        }

        // If we have an affiliate, snapshot their commission rate
        $commissionRate = null;
        if ($resolvedAffiliateId) {
            $affiliate = Affiliate::find($resolvedAffiliateId);
            if ($affiliate) {
                $commissionRate = $affiliate->effectiveCommissionRate();

                if (!$app->affiliate_id) {
                    $app->update(['affiliate_id' => $resolvedAffiliateId]);
                }
            }
        }

        return AffiliateConversion::create([
            'organization_id' => $app->organization_id,
            'tracking_link_id' => $link?->id,
            'affiliate_id' => $resolvedAffiliateId,
            'application_id' => $app->application_id,
            'type' => 'signup',
            'commission_rate_snapshot' => $commissionRate,
            'status' => 'pending',
            'attribution_method' => $affiliateId && !$trackingCode ? 'manual' : 'link',
        ]);
    }

    /**
     * Manually attribute an application to an affiliate (admin action).
     */
    public function manualAttribution(string $applicationId, int $affiliateId, int $orgId): ?AffiliateConversion
    {
        $app = Application::where('application_id', $applicationId)->firstOrFail();
        $affiliate = Affiliate::where('id', $affiliateId)
            ->where('organization_id', $orgId)
            ->firstOrFail();

        $app->update([
            'affiliate_id' => $affiliateId,
        ]);

        // Check for existing conversion
        $existing = AffiliateConversion::where('application_id', $applicationId)
            ->where('affiliate_id', $affiliateId)
            ->first();

        if ($existing) {
            return $existing;
        }

        return AffiliateConversion::create([
            'organization_id' => $orgId,
            'tracking_link_id' => null,
            'affiliate_id' => $affiliateId,
            'application_id' => $applicationId,
            'type' => 'signup',
            'commission_rate_snapshot' => $affiliate->effectiveCommissionRate(),
            'status' => 'pending',
            'attribution_method' => 'manual',
        ]);
    }

    /**
     * Record an affiliate payout (admin action).
     */
    public function recordPayout(Affiliate $affiliate, array $data, User $admin): AffiliatePayout
    {
        $payout = AffiliatePayout::create([
            'organization_id' => $affiliate->organization_id,
            'affiliate_id' => $affiliate->id,
            'amount' => $data['amount'],
            'method' => $data['method'] ?? null,
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'recorded_by' => $admin->id,
            'paid_at' => $data['paid_at'] ?? now(),
        ]);

        // Mark approved conversions as paid up to the payout amount
        if (!empty($data['mark_conversions_paid'])) {
            $affiliate->conversions()
                ->where('status', 'approved')
                ->update(['status' => 'paid']);
        }

        return $payout;
    }

    /**
     * Generate a magic link for affiliate authentication.
     */
    public function generateMagicLink(Affiliate $affiliate): string
    {
        $rawToken = $affiliate->generateMagicToken();

        $domain = $affiliate->organization?->public_domain ?? config('app.url');
        $domain = rtrim($domain, '/');
        if (!str_starts_with($domain, 'http')) {
            $domain = 'https://' . $domain;
        }

        return $domain . '/affiliate/verify?token=' . $rawToken;
    }

    /**
     * Verify a magic token and return the affiliate + session token.
     */
    public function verifyMagicToken(string $token): ?array
    {
        $hash = hash('sha256', $token);

        $affiliate = Affiliate::where('magic_token', $hash)
            ->where('magic_token_expires_at', '>', now())
            ->where('status', 'active')
            ->first();

        if (!$affiliate) {
            return null;
        }

        // Clear the magic token (single use)
        $affiliate->update([
            'magic_token' => null,
            'magic_token_expires_at' => null,
            'last_login_at' => now(),
        ]);

        // Create a cache-based session token (24h TTL)
        $sessionToken = Str::random(64);
        Cache::put(
            'affiliate_session:' . $sessionToken,
            $affiliate->id,
            now()->addHours(24)
        );

        return [
            'affiliate' => $affiliate,
            'session_token' => $sessionToken,
        ];
    }

    /**
     * Resolve an affiliate from a session token.
     */
    public function resolveSession(string $sessionToken): ?Affiliate
    {
        $affiliateId = Cache::get('affiliate_session:' . $sessionToken);

        if (!$affiliateId) {
            return null;
        }

        return Affiliate::find($affiliateId);
    }

    /**
     * Revoke an affiliate session.
     */
    public function revokeSession(string $sessionToken): void
    {
        Cache::forget('affiliate_session:' . $sessionToken);
    }

    /**
     * Get stats for an affiliate.
     */
    public function getAffiliateStats(Affiliate $affiliate): array
    {
        $totalClicks = $affiliate->trackingLinks()->sum('click_count');
        $totalConversions = $affiliate->conversions()->count();
        $pendingCommission = $affiliate->conversions()
            ->whereIn('status', ['pending', 'approved'])
            ->sum('commission_amount');
        $paidCommission = $affiliate->payouts()->sum('amount');

        return [
            'total_clicks' => (int) $totalClicks,
            'total_conversions' => $totalConversions,
            'pending_commission' => round((float) $pendingCommission, 2),
            'paid_commission' => round((float) $paidCommission, 2),
            'total_earned' => round((float) $affiliate->totalEarned(), 2),
        ];
    }
}
