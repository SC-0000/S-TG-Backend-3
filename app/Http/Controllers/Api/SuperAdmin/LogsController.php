<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Api\ApiController;
use App\Models\AIUploadLog;
use App\Models\SystemSetting;
use App\Models\TransactionLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

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
                    'updated_at' => $setting->updated_at?->toISOString(),
                ];
            });

        return $this->success([
            'logs' => $settings,
        ]);
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
