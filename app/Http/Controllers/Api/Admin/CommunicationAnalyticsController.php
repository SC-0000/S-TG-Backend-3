<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\CommunicationMessage;
use App\Services\AI\TokenBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommunicationAnalyticsController extends ApiController
{
    public function __construct(
        protected TokenBillingService $tokenBillingService,
    ) {}

    /**
     * Get communication analytics for the organization.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;
        $days = (int) $request->input('days', 30);

        $from = now()->subDays($days)->startOfDay();
        $to = now();

        // Channel breakdown
        $channelStats = CommunicationMessage::forOrganization($orgId)
            ->where('created_at', '>=', $from)
            ->outbound()
            ->selectRaw('channel, status, COUNT(*) as count')
            ->groupBy('channel', 'status')
            ->get()
            ->groupBy('channel')
            ->map(function ($rows) {
                $stats = ['total' => 0, 'sent' => 0, 'delivered' => 0, 'failed' => 0, 'read' => 0];
                foreach ($rows as $row) {
                    $stats[$row->status] = (int) $row->count;
                    $stats['total'] += (int) $row->count;
                }
                return $stats;
            });

        // Daily message volume (last N days)
        $dailyVolume = CommunicationMessage::forOrganization($orgId)
            ->where('created_at', '>=', $from)
            ->outbound()
            ->selectRaw('DATE(created_at) as date, channel, COUNT(*) as count')
            ->groupBy('date', 'channel')
            ->orderBy('date')
            ->get()
            ->groupBy('date')
            ->map(fn ($rows) => $rows->pluck('count', 'channel')->toArray());

        // Token spend on communications
        $org = \App\Models\Organization::find($orgId);
        $tokenUsage = $org ? $this->tokenBillingService->getUsageSummary($org, $from, $to) : [];

        // Total messages this period
        $totalMessages = CommunicationMessage::forOrganization($orgId)
            ->where('created_at', '>=', $from)
            ->outbound()
            ->count();

        // Inbound messages
        $totalInbound = CommunicationMessage::forOrganization($orgId)
            ->where('created_at', '>=', $from)
            ->inbound()
            ->count();

        return $this->success([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days],
            'total_outbound' => $totalMessages,
            'total_inbound' => $totalInbound,
            'by_channel' => $channelStats,
            'daily_volume' => $dailyVolume,
            'token_usage' => $tokenUsage['by_channel'] ?? [],
        ]);
    }
}
