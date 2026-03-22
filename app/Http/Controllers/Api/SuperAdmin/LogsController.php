<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Api\ApiController;
use App\Models\AIUploadLog;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\Transaction;
use App\Models\TransactionLog;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Carbon\Carbon;

class LogsController extends ApiController
{
    public function system(Request $request): JsonResponse
    {
        $limit = $this->resolveLimit($request);
        $logFile = $this->resolveLogFile();
        $lines = $logFile ? $this->tailFile($logFile, $limit) : [];

        return $this->success([
            'logs' => $lines,
            'source' => $logFile ? basename($logFile) : null,
        ]);
    }

    public function userActivity(Request $request): JsonResponse
    {
        $limit = $this->resolveLimit($request);

        $userRows = User::query()
            ->select(['id', 'name', 'email', 'created_at'])
            ->latest()
            ->take($limit)
            ->get()
            ->map(fn ($user) => [
                'type' => 'user_created',
                'description' => "{$user->name} ({$user->email}) created",
                'created_at' => $user->created_at?->toISOString(),
            ]);

        $orgMemberships = DB::table('organization_users')
            ->join('users', 'users.id', '=', 'organization_users.user_id')
            ->join('organizations', 'organizations.id', '=', 'organization_users.organization_id')
            ->select(
                'users.name as user_name',
                'organizations.name as organization_name',
                'organization_users.created_at'
            )
            ->orderByDesc('organization_users.created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'type' => 'organization_membership',
                'description' => "{$row->user_name} joined {$row->organization_name}",
                'created_at' => $row->created_at,
            ]);

        $subscriptions = DB::table('user_subscriptions as us')
            ->join('users', 'users.id', '=', 'us.user_id')
            ->join('subscriptions', 'subscriptions.id', '=', 'us.subscription_id')
            ->select(
                'users.name as user_name',
                'subscriptions.name as plan_name',
                'us.status',
                'us.created_at'
            )
            ->orderByDesc('us.created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'type' => 'subscription_change',
                'description' => "{$row->user_name} subscription {$row->plan_name} ({$row->status})",
                'created_at' => $row->created_at,
            ]);

        $items = collect()
            ->merge($userRows)
            ->merge($orgMemberships)
            ->merge($subscriptions)
            ->sortByDesc('created_at')
            ->take($limit)
            ->values()
            ->all();

        return $this->success([
            'logs' => $items,
        ]);
    }

    public function errors(Request $request): JsonResponse
    {
        $limit = $this->resolveLimit($request);

        $transactionErrors = TransactionLog::query()
            ->where('log_type', 'error')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($log) => [
                'type' => 'transaction',
                'message' => $log->log_message,
                'created_at' => $log->created_at,
            ]);

        $aiErrors = AIUploadLog::query()
            ->where('level', AIUploadLog::LEVEL_ERROR)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($log) => [
                'type' => 'ai_upload',
                'message' => $log->message,
                'created_at' => $log->created_at?->toISOString(),
            ]);

        $logFile = $this->resolveLogFile();
        $fileErrors = [];
        if ($logFile) {
            $lines = $this->tailFile($logFile, $limit * 4);
            $fileErrors = collect($lines)
                ->filter(fn ($line) => str_contains($line, 'ERROR'))
                ->take($limit)
                ->map(fn ($line) => [
                    'type' => 'laravel',
                    'message' => $line,
                    'created_at' => null,
                ])
                ->values()
                ->all();
        }

        $items = collect()
            ->merge($transactionErrors)
            ->merge($aiErrors)
            ->merge($fileErrors)
            ->sortByDesc('created_at')
            ->take($limit)
            ->values()
            ->all();

        return $this->success([
            'logs' => $items,
        ]);
    }

    public function audit(Request $request): JsonResponse
    {
        $limit = $this->resolveLimit($request);

        // System settings changes
        $settings = SystemSetting::query()
            ->with('updatedBy:id,name,email')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(function ($setting) {
                $actor = $setting->updatedBy
                    ? "{$setting->updatedBy->name} ({$setting->updatedBy->email})"
                    : 'system';

                return [
                    'type' => 'system_setting',
                    'key' => $setting->key,
                    'actor' => $actor,
                    'created_at' => $setting->updated_at?->toISOString(),
                ];
            });

        // Model audit logs (from AuditLog table)
        $auditLogs = AuditLog::query()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($log) => [
                'type' => 'audit',
                'action' => $log->action,
                'resource_type' => $log->resource_type,
                'resource_id' => $log->resource_id,
                'resource_name' => $log->resource_name,
                'actor' => $log->user_name ? "{$log->user_name} ({$log->user_role})" : 'system',
                'changes' => $log->changes,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toISOString(),
            ]);

        $items = collect()
            ->merge($settings)
            ->merge($auditLogs)
            ->sortByDesc('created_at')
            ->take($limit)
            ->values()
            ->all();

        return $this->success([
            'logs' => $items,
        ]);
    }

    /**
     * Billing system audit — at-a-glance health dashboard for webhooks,
     * transactions, and billing provider connectivity.
     */
    public function billingAudit(Request $request): JsonResponse
    {
        $limit = $this->resolveLimit($request);

        // ── 1. System Health Checks ──────────────────────────────────────
        $health = $this->billingHealthChecks();

        // ── 2. Webhook event summary (last 24h / 7d) ────────────────────
        $now = Carbon::now();
        $webhookStats = $this->webhookStats($now);

        // ── 3. Recent webhook events (filterable) ────────────────────────
        $query = TransactionLog::query()
            ->whereNotNull('webhook_delivery_id')
            ->orderByDesc('created_at');

        // Filters
        if ($eventType = $request->query('event_type')) {
            $query->where('event_type', $eventType);
        }
        if ($logType = $request->query('log_type')) {
            $query->where('log_type', $logType);
        }
        if ($from = $request->query('date_from')) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->where('created_at', '<=', $to);
        }

        $events = $query->limit($limit)->get()->map(fn ($log) => [
            'id' => $log->id,
            'event_type' => $log->event_type ?? $this->extractEventFromMessage($log->log_message),
            'log_type' => $log->log_type,
            'message' => $log->log_message,
            'transaction_id' => $log->transaction_id,
            'webhook_delivery_id' => $log->webhook_delivery_id,
            'payload' => $log->payload,
            'source_ip' => $log->source_ip,
            'created_at' => $log->created_at?->toISOString(),
        ]);

        // ── 4. Recent failures ───────────────────────────────────────────
        $failures = TransactionLog::query()
            ->where('log_type', 'error')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'event_type' => $log->event_type ?? $this->extractEventFromMessage($log->log_message),
                'message' => $log->log_message,
                'transaction_id' => $log->transaction_id,
                'webhook_delivery_id' => $log->webhook_delivery_id,
                'payload' => $log->payload,
                'created_at' => $log->created_at?->toISOString(),
            ]);

        // ── 5. Transaction status breakdown ──────────────────────────────
        $transactionStats = Transaction::query()
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $pendingOld = Transaction::query()
            ->where('status', 'pending')
            ->where('created_at', '<', $now->copy()->subDays(2))
            ->count();

        return $this->success([
            'health' => $health,
            'webhook_stats' => $webhookStats,
            'events' => $events,
            'failures' => $failures,
            'transaction_summary' => [
                'by_status' => $transactionStats,
                'stale_pending' => $pendingOld,
            ],
        ]);
    }

    /**
     * Run billing system health checks and return status for each subsystem.
     */
    private function billingHealthChecks(): array
    {
        $checks = [];

        // Check: webhook secret configured
        $webhookSecret = config('services.billingsystems.webhook_secret');
        $checks[] = [
            'name' => 'Webhook Secret',
            'status' => ! empty($webhookSecret) ? 'ok' : 'failing',
            'detail' => ! empty($webhookSecret)
                ? 'Configured'
                : 'BILLING_WEBHOOK_SECRET not set — signature verification is DISABLED',
        ];

        // Check: billing API URL configured
        $apiUrl = config('services.billingsystems.base_uri');
        $checks[] = [
            'name' => 'Billing API URL',
            'status' => ! empty($apiUrl) ? 'ok' : 'failing',
            'detail' => ! empty($apiUrl) ? $apiUrl : 'BILLING_SYSTEMS_API_URL not set',
        ];

        // Check: billing API token configured
        $apiToken = config('services.billingsystems.token');
        $checks[] = [
            'name' => 'Billing API Token',
            'status' => ! empty($apiToken) ? 'ok' : 'failing',
            'detail' => ! empty($apiToken) ? 'Configured' : 'BILLING_SYSTEMS_API_TOKEN not set',
        ];

        // Check: billing API connectivity
        try {
            $billing = app(BillingService::class);
            $response = $billing->ping();
            $checks[] = [
                'name' => 'Billing API Connectivity',
                'status' => $response ? 'ok' : 'warning',
                'detail' => $response ? 'Reachable' : 'API responded but may have issues',
            ];
        } catch (\Throwable $e) {
            $checks[] = [
                'name' => 'Billing API Connectivity',
                'status' => 'failing',
                'detail' => 'Cannot reach billing API: ' . $e->getMessage(),
            ];
        }

        // Check: recent webhook activity (any in last 24h?)
        $recentWebhooks = TransactionLog::query()
            ->whereNotNull('webhook_delivery_id')
            ->where('created_at', '>=', Carbon::now()->subDay())
            ->count();
        $checks[] = [
            'name' => 'Recent Webhook Activity',
            'status' => $recentWebhooks > 0 ? 'ok' : 'warning',
            'detail' => $recentWebhooks > 0
                ? "{$recentWebhooks} webhook(s) received in last 24h"
                : 'No webhooks received in last 24h — may indicate connectivity issue',
        ];

        // Check: recent errors
        $recentErrors = TransactionLog::query()
            ->where('log_type', 'error')
            ->where('created_at', '>=', Carbon::now()->subDay())
            ->count();
        $checks[] = [
            'name' => 'Webhook Errors (24h)',
            'status' => $recentErrors === 0 ? 'ok' : 'failing',
            'detail' => $recentErrors === 0
                ? 'No errors'
                : "{$recentErrors} error(s) in last 24h — check failures below",
        ];

        // Check: queue connection
        $queueConnection = config('queue.default');
        $checks[] = [
            'name' => 'Queue Driver',
            'status' => $queueConnection !== 'sync' ? 'ok' : 'warning',
            'detail' => "Using: {$queueConnection}" . ($queueConnection === 'sync' ? ' (jobs run synchronously — may slow webhook responses)' : ''),
        ];

        return $checks;
    }

    /**
     * Compute webhook event statistics for the last 24h and 7d.
     */
    private function webhookStats(Carbon $now): array
    {
        $periods = [
            '24h' => $now->copy()->subDay(),
            '7d'  => $now->copy()->subDays(7),
        ];

        $stats = [];
        foreach ($periods as $label => $since) {
            $byEvent = TransactionLog::query()
                ->whereNotNull('webhook_delivery_id')
                ->where('created_at', '>=', $since)
                ->select('event_type', 'log_type', DB::raw('COUNT(*) as count'))
                ->groupBy('event_type', 'log_type')
                ->get();

            $total = $byEvent->sum('count');
            $errors = $byEvent->where('log_type', 'error')->sum('count');
            $warnings = $byEvent->where('log_type', 'warning')->sum('count');

            $byEventGrouped = $byEvent->groupBy('event_type')->map(function ($group) {
                return [
                    'total' => $group->sum('count'),
                    'errors' => $group->where('log_type', 'error')->sum('count'),
                    'warnings' => $group->where('log_type', 'warning')->sum('count'),
                ];
            });

            $stats[$label] = [
                'total' => $total,
                'errors' => $errors,
                'warnings' => $warnings,
                'success_rate' => $total > 0 ? round((($total - $errors) / $total) * 100, 1) : null,
                'by_event' => $byEventGrouped,
            ];
        }

        return $stats;
    }

    /**
     * Extract event type from legacy log messages that don't have event_type column.
     */
    private function extractEventFromMessage(string $message): ?string
    {
        if (preg_match('/Webhook event: (.+)/', $message, $m)) {
            return $m[1];
        }
        if (preg_match('/^(payment\.\w+|invoice\.\w+|refund\.\w+|dispute\.\w+|subscription\.\w+)/', $message, $m)) {
            return $m[1];
        }
        return null;
    }

    public function performance(Request $request): JsonResponse
    {
        $limit = $this->resolveLimit($request);

        $aiMetrics = AIUploadLog::query()
            ->whereNotNull('duration_ms')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($log) => [
                'type' => 'ai_upload',
                'action' => $log->action,
                'duration_ms' => $log->duration_ms,
                'tokens_input' => $log->tokens_input,
                'tokens_output' => $log->tokens_output,
                'cost_usd' => $log->cost_usd,
                'created_at' => $log->created_at?->toISOString(),
            ]);

        return $this->success([
            'logs' => $aiMetrics,
        ]);
    }

    private function resolveLimit(Request $request, int $default = 50): int
    {
        $limit = (int) $request->query('limit', $default);
        if ($limit <= 0) {
            $limit = $default;
        }

        return min($limit, 200);
    }

    private function resolveLogFile(): ?string
    {
        $dir = storage_path('logs');
        if (!File::exists($dir)) {
            return null;
        }

        $files = collect(File::files($dir))
            ->sortByDesc(fn ($file) => $file->getMTime())
            ->values();

        return $files->first()?->getPathname();
    }

    private function tailFile(string $path, int $lines): array
    {
        if (!File::exists($path)) {
            return [];
        }

        $size = File::size($path);
        $readSize = min($size, 256 * 1024);
        $handle = fopen($path, 'rb');
        if (!$handle) {
            return [];
        }

        fseek($handle, -$readSize, SEEK_END);
        $chunk = fread($handle, $readSize);
        fclose($handle);

        $allLines = preg_split('/\r\n|\r|\n/', trim((string) $chunk));
        if (!$allLines) {
            return [];
        }

        return array_slice($allLines, max(count($allLines) - $lines, 0));
    }
}
