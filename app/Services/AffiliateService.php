<?php

namespace App\Services;

use App\Models\Affiliate;
use App\Models\AffiliateConversion;
use App\Models\AffiliatePayout;
use App\Models\Application;
use App\Models\LinkClick;
use App\Models\TrackingEvent;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            'ip_hash' => hash('sha256', $request->ip() ?? ''),
            'user_agent' => Str::limit($request->userAgent() ?? '', 500),
            'referer_url' => Str::limit($request->header('referer', ''), 500),
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
            }
        }

        // If no affiliate and no valid link, nothing to attribute (but still record tracking_code)
        if (!$resolvedAffiliateId && !$link) {
            if ($trackingCode) {
                $app->update(['tracking_code' => $trackingCode]);
            }
            return null;
        }

        // Update application tracking fields
        $app->update([
            'tracking_code' => $trackingCode ?? $app->tracking_code,
            'affiliate_id' => $resolvedAffiliateId ?? $app->affiliate_id,
        ]);

        // Prevent duplicate conversions for the same application
        $existing = AffiliateConversion::where('application_id', $app->application_id)->first();
        if ($existing) {
            return $existing;
        }

        // Snapshot commission rate if we have an active affiliate
        $commissionRate = null;
        if ($resolvedAffiliateId) {
            $affiliate = Affiliate::where('id', $resolvedAffiliateId)
                ->where('status', 'active')
                ->first();

            if ($affiliate) {
                $commissionRate = $affiliate->effectiveCommissionRate();
            } else {
                // Affiliate inactive — still record the conversion but no commission
                $resolvedAffiliateId = $affiliateId; // keep original, just no rate
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

        // Check for existing conversion for this application
        $existing = AffiliateConversion::where('application_id', $applicationId)->first();
        if ($existing) {
            // Update the affiliate if it changed
            if ((int) $existing->affiliate_id !== $affiliateId) {
                $existing->update([
                    'affiliate_id' => $affiliateId,
                    'commission_rate_snapshot' => $affiliate->effectiveCommissionRate(),
                    'attribution_method' => 'manual',
                ]);
            }
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
     * Update a conversion's status and optionally set commission amount.
     */
    public function updateConversionStatus(AffiliateConversion $conversion, string $status, ?float $commissionAmount = null): AffiliateConversion
    {
        $data = ['status' => $status];

        if ($commissionAmount !== null) {
            $data['commission_amount'] = $commissionAmount;
        }

        $conversion->update($data);

        return $conversion;
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

        // Optionally mark approved conversions as paid (up to payout amount)
        if (!empty($data['mark_conversions_paid'])) {
            $remaining = (float) $data['amount'];
            $conversions = $affiliate->conversions()
                ->where('status', 'approved')
                ->orderBy('created_at', 'asc')
                ->get();

            foreach ($conversions as $conversion) {
                if ($remaining <= 0) {
                    break;
                }

                $convAmount = (float) ($conversion->commission_amount ?? 0);
                if ($convAmount <= $remaining) {
                    $conversion->update(['status' => 'paid']);
                    $remaining -= $convAmount;
                } else {
                    // Partial — just mark it paid (admin made the decision)
                    $conversion->update(['status' => 'paid']);
                    $remaining = 0;
                }
            }
        }

        return $payout;
    }

    /**
     * Generate a magic link for affiliate authentication.
     */
    public function generateMagicLink(Affiliate $affiliate): string
    {
        $rawToken = $affiliate->generateMagicToken();

        // Magic link should point to the frontend
        $domain = rtrim((string) config('app.frontend_url'), '/');
        if (!$domain) {
            $domain = $affiliate->organization?->public_domain ?? config('app.url');
            $domain = rtrim((string) $domain, '/');
            if ($domain && !str_starts_with($domain, 'http')) {
                $domain = 'https://' . $domain;
            }
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
            [
                'affiliate_id' => $affiliate->id,
                'organization_id' => $affiliate->organization_id,
            ],
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
        $data = Cache::get('affiliate_session:' . $sessionToken);

        if (!$data) {
            return null;
        }

        // Support both old (int) and new (array) cache format
        $affiliateId = is_array($data) ? ($data['affiliate_id'] ?? null) : $data;

        if (!$affiliateId) {
            return null;
        }

        return Affiliate::find($affiliateId);
    }

    /**
     * Get the organization ID from a session token.
     */
    public function resolveSessionOrgId(string $sessionToken): ?int
    {
        $data = Cache::get('affiliate_session:' . $sessionToken);

        if (is_array($data)) {
            return $data['organization_id'] ?? null;
        }

        // Fallback: load from affiliate
        $affiliate = $this->resolveSession($sessionToken);
        return $affiliate?->organization_id;
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
        $totalClicks = (int) $affiliate->trackingLinks()->sum('click_count');
        $totalConversions = $affiliate->conversions()->count();

        // Commission owed: conversions that are pending or approved
        // Use commission_amount if set, otherwise count conversions with rate snapshots
        $owedConversions = $affiliate->conversions()
            ->whereIn('status', ['pending', 'approved'])
            ->get();

        $pendingCommission = 0;
        $pendingCount = 0;
        foreach ($owedConversions as $conv) {
            if ($conv->commission_amount !== null) {
                $pendingCommission += (float) $conv->commission_amount;
            } else {
                $pendingCount++;
            }
        }

        $paidCommission = (float) $affiliate->payouts()->sum('amount');

        return [
            'total_clicks' => $totalClicks,
            'total_conversions' => $totalConversions,
            'pending_commission' => round($pendingCommission, 2),
            'pending_conversions_without_amount' => $pendingCount,
            'paid_commission' => round($paidCommission, 2),
            'total_earned' => round((float) $affiliate->totalEarned(), 2),
        ];
    }

    // ---------------------------------------------------------------
    // Funnel / Journey Tracking
    // ---------------------------------------------------------------

    /**
     * Record a tracking event (lightweight, fire-and-forget).
     */
    public function recordEvent(string $trackingCode, string $event, Request $request, ?string $pagePath = null, ?array $meta = null): void
    {
        $link = TrackingLink::where('code', $trackingCode)->first();
        if (!$link) {
            return;
        }

        // Deduplicate: same session + same event + same page within 30 seconds
        $sessionHash = hash('sha256', ($request->ip() ?? '') . '|' . ($request->userAgent() ?? ''));

        $recent = TrackingEvent::where('tracking_link_id', $link->id)
            ->where('session_hash', $sessionHash)
            ->where('event', $event)
            ->where('occurred_at', '>', now()->subSeconds(30))
            ->exists();

        if ($recent) {
            return;
        }

        TrackingEvent::create([
            'tracking_link_id' => $link->id,
            'session_hash' => $sessionHash,
            'event' => $event,
            'page_path' => $pagePath ? Str::limit($pagePath, 500) : null,
            'meta' => $meta,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Get funnel data for a single tracking link.
     */
    public function getLinkFunnel(int $linkId, ?string $from = null, ?string $to = null): array
    {
        $query = TrackingEvent::where('tracking_link_id', $linkId);

        if ($from) {
            $query->where('occurred_at', '>=', $from);
        }
        if ($to) {
            $query->where('occurred_at', '<=', $to);
        }

        // Count unique sessions per event stage
        $stages = [];
        foreach (TrackingEvent::FUNNEL_STAGES as $stage) {
            $stages[$stage] = (clone $query)
                ->where('event', $stage)
                ->distinct('session_hash')
                ->count('session_hash');
        }

        return $stages;
    }

    /**
     * Get aggregated funnel data for all links in an org.
     */
    public function getOrgFunnel(int $orgId, ?string $from = null, ?string $to = null): array
    {
        $linkIds = TrackingLink::where('organization_id', $orgId)->pluck('id');

        if ($linkIds->isEmpty()) {
            return array_fill_keys(TrackingEvent::FUNNEL_STAGES, 0);
        }

        $query = TrackingEvent::whereIn('tracking_link_id', $linkIds);

        if ($from) {
            $query->where('occurred_at', '>=', $from);
        }
        if ($to) {
            $query->where('occurred_at', '<=', $to);
        }

        $stages = [];
        foreach (TrackingEvent::FUNNEL_STAGES as $stage) {
            $stages[$stage] = (clone $query)
                ->where('event', $stage)
                ->distinct('session_hash')
                ->count('session_hash');
        }

        return $stages;
    }

    /**
     * Get top-performing links for an org by conversion rate.
     */
    public function getTopLinks(int $orgId, int $limit = 10): array
    {
        return TrackingLink::where('organization_id', $orgId)
            ->where('click_count', '>', 0)
            ->withCount('conversions')
            ->orderByDesc('conversions_count')
            ->limit($limit)
            ->get()
            ->map(function ($link) {
                return [
                    'id' => $link->id,
                    'code' => $link->code,
                    'label' => $link->label,
                    'type' => $link->type,
                    'clicks' => $link->click_count,
                    'conversions' => $link->conversions_count,
                    'conversion_rate' => $link->click_count > 0
                        ? round(($link->conversions_count / $link->click_count) * 100, 1)
                        : 0,
                ];
            })
            ->toArray();
    }

    /**
     * Get daily click/event trend for a link or org.
     */
    public function getEventTrend(int $orgId, ?int $linkId = null, int $days = 30): array
    {
        $query = TrackingEvent::query();

        if ($linkId) {
            $query->where('tracking_link_id', $linkId);
        } else {
            $linkIds = TrackingLink::where('organization_id', $orgId)->pluck('id');
            $query->whereIn('tracking_link_id', $linkIds);
        }

        $query->where('occurred_at', '>=', now()->subDays($days));

        $results = $query->select(
                DB::raw('DATE(occurred_at) as date'),
                'event',
                DB::raw('COUNT(DISTINCT session_hash) as unique_count')
            )
            ->groupBy('date', 'event')
            ->orderBy('date')
            ->get();

        // Pivot into { date: { click: N, page_view: N, ... } }
        $trend = [];
        foreach ($results as $row) {
            $trend[$row->date][$row->event] = (int) $row->unique_count;
        }

        // Fill in missing dates
        $output = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dayData = $trend[$date] ?? [];
            $output[] = array_merge(
                ['date' => $date],
                array_fill_keys(TrackingEvent::FUNNEL_STAGES, 0),
                $dayData
            );
        }

        return $output;
    }
}
