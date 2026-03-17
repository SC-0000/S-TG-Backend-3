<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\BackgroundAgentAction;
use App\Models\BackgroundAgentConfig;
use App\Models\BackgroundAgentRun;
use App\Models\ContentQualityIssue;
use App\Services\AI\BackgroundAgents\BackgroundAgentOrchestrator;
use App\Services\AI\BackgroundAgents\BackgroundAgentRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BackgroundAgentController extends ApiController
{
    /**
     * GET /api/admin/agents/dashboard
     * Aggregate overview of all agents.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');

        $agents = BackgroundAgentRegistry::allWithMeta();
        $agentData = [];

        foreach ($agents as $type => $meta) {
            $config = $orgId
                ? BackgroundAgentConfig::getOrCreate($orgId, $type)
                : null;

            $lastRun = BackgroundAgentRun::forAgent($type)
                ->when($orgId, fn($q) => $q->forOrganization($orgId))
                ->latest()
                ->first();

            $recentRuns = BackgroundAgentRun::forAgent($type)
                ->when($orgId, fn($q) => $q->forOrganization($orgId))
                ->recent(7);

            $totalRuns = (clone $recentRuns)->count();
            $successfulRuns = (clone $recentRuns)->where('status', 'completed')->count();

            $agentData[$type] = [
                'type' => $type,
                'description' => $meta['description'],
                'is_stub' => $meta['is_stub'],
                'is_enabled' => $config?->is_enabled ?? true,
                'default_schedule' => $meta['default_schedule'],
                'schedule_override' => $config?->schedule_override,
                'estimated_tokens_per_run' => $meta['estimated_tokens_per_run'],
                'last_run' => $lastRun ? [
                    'id' => $lastRun->id,
                    'status' => $lastRun->status,
                    'started_at' => $lastRun->started_at,
                    'completed_at' => $lastRun->completed_at,
                    'created_at' => $lastRun->created_at,
                    'items_processed' => $lastRun->items_processed,
                    'items_affected' => $lastRun->items_affected,
                    'tokens_used' => $lastRun->platform_tokens_used,
                ] : null,
                'stats_7d' => [
                    'total_runs' => $totalRuns,
                    'successful_runs' => $successfulRuns,
                    'success_rate' => $totalRuns > 0 ? round(($successfulRuns / $totalRuns) * 100, 1) : 0,
                    'tokens_used' => (clone $recentRuns)->sum('platform_tokens_used'),
                ],
            ];
        }

        // Quality issues summary
        $qualityIssues = ContentQualityIssue::when($orgId, fn($q) => $q->forOrganization($orgId))
            ->selectRaw("severity, status, COUNT(*) as count")
            ->groupBy('severity', 'status')
            ->get()
            ->groupBy('severity')
            ->map(fn($group) => $group->pluck('count', 'status'));

        return $this->success([
            'agents' => $agentData,
            'quality_issues_summary' => $qualityIssues,
        ]);
    }

    /**
     * GET /api/admin/agents
     * List all agent types.
     */
    public function index(Request $request): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');
        $agents = BackgroundAgentRegistry::allWithMeta();

        $result = [];
        foreach ($agents as $type => $meta) {
            $config = $orgId ? BackgroundAgentConfig::getOrCreate($orgId, $type) : null;

            $result[] = array_merge($meta, [
                'is_enabled' => $config?->is_enabled ?? true,
                'schedule_override' => $config?->schedule_override,
                'last_run_at' => $config?->last_run_at,
                'settings' => $config?->settings ?? [],
            ]);
        }

        return $this->success($result);
    }

    /**
     * GET /api/admin/agents/{type}
     * Agent detail with recent runs.
     */
    public function show(Request $request, string $type): JsonResponse
    {
        $class = BackgroundAgentRegistry::get($type);
        if (!$class) {
            return $this->error('Unknown agent type', [], 404);
        }

        $orgId = $request->attributes->get('organization_id');
        $config = $orgId ? BackgroundAgentConfig::getOrCreate($orgId, $type) : null;
        $meta = BackgroundAgentRegistry::allWithMeta()[$type];

        $recentRuns = BackgroundAgentRun::forAgent($type)
            ->when($orgId, fn($q) => $q->forOrganization($orgId))
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn($run) => [
                'id' => $run->id,
                'trigger_type' => $run->trigger_type,
                'status' => $run->status,
                'started_at' => $run->started_at,
                'completed_at' => $run->completed_at,
                'created_at' => $run->created_at,
                'duration_seconds' => $run->duration,
                'items_processed' => $run->items_processed,
                'items_affected' => $run->items_affected,
                'tokens_used' => $run->platform_tokens_used,
                'error_message' => $run->error_message,
            ]);

        // Aggregate lifetime stats for this agent
        $allRuns = BackgroundAgentRun::forAgent($type)
            ->when($orgId, fn($q) => $q->forOrganization($orgId));

        $lifetimeStats = [
            'total_runs' => (clone $allRuns)->count(),
            'completed_runs' => (clone $allRuns)->where('status', 'completed')->count(),
            'failed_runs' => (clone $allRuns)->where('status', 'failed')->count(),
            'skipped_runs' => (clone $allRuns)->where('status', 'skipped')->count(),
            'total_items_processed' => (clone $allRuns)->sum('items_processed'),
            'total_items_affected' => (clone $allRuns)->sum('items_affected'),
            'total_tokens_used' => (clone $allRuns)->sum('platform_tokens_used'),
        ];

        // Last 30 days stats
        $last30Runs = BackgroundAgentRun::forAgent($type)
            ->when($orgId, fn($q) => $q->forOrganization($orgId))
            ->recent(30);

        $stats30d = [
            'total_runs' => (clone $last30Runs)->count(),
            'completed_runs' => (clone $last30Runs)->where('status', 'completed')->count(),
            'items_processed' => (clone $last30Runs)->sum('items_processed'),
            'items_affected' => (clone $last30Runs)->sum('items_affected'),
            'tokens_used' => (clone $last30Runs)->sum('platform_tokens_used'),
        ];

        // Recent successful actions (the actual changes made) — last 50
        $recentActions = BackgroundAgentAction::whereHas('run', function ($q) use ($type, $orgId) {
            $q->where('agent_type', $type)
                ->when($orgId, fn($q2) => $q2->where('organization_id', $orgId));
        })
            ->where('status', 'success')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn($a) => [
                'id' => $a->id,
                'action_type' => $a->action_type,
                'target_type' => $a->target_type ? class_basename($a->target_type) : null,
                'target_id' => $a->target_id,
                'description' => $a->description,
                'tokens_used' => $a->platform_tokens_used,
                'created_at' => $a->created_at,
            ]);

        // Action type breakdown (all time)
        $actionBreakdown = BackgroundAgentAction::whereHas('run', function ($q) use ($type, $orgId) {
            $q->where('agent_type', $type)
                ->when($orgId, fn($q2) => $q2->where('organization_id', $orgId));
        })
            ->selectRaw('action_type, status, COUNT(*) as count, SUM(platform_tokens_used) as tokens')
            ->groupBy('action_type', 'status')
            ->get()
            ->groupBy('action_type')
            ->map(fn($group) => [
                'total' => $group->sum('count'),
                'successful' => $group->where('status', 'success')->sum('count'),
                'failed' => $group->where('status', 'failed')->sum('count'),
                'tokens' => $group->sum('tokens'),
            ]);

        return $this->success([
            'agent' => array_merge($meta, [
                'is_enabled' => $config?->is_enabled ?? true,
                'schedule_override' => $config?->schedule_override,
                'settings' => $config?->settings ?? [],
                'last_run_at' => $config?->last_run_at,
            ]),
            'recent_runs' => $recentRuns,
            'lifetime_stats' => $lifetimeStats,
            'stats_30d' => $stats30d,
            'recent_actions' => $recentActions,
            'action_breakdown' => $actionBreakdown,
        ]);
    }

    /**
     * GET /api/admin/agents/{type}/runs
     * Paginated run history.
     */
    public function runs(Request $request, string $type): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');

        $runs = BackgroundAgentRun::forAgent($type)
            ->when($orgId, fn($q) => $q->forOrganization($orgId))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 15));

        return $this->success($runs);
    }

    /**
     * GET /api/admin/agents/{type}/runs/{id}
     * Run detail with actions.
     */
    public function runDetail(Request $request, string $type, int $id): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');

        $run = BackgroundAgentRun::forAgent($type)
            ->when($orgId, fn($q) => $q->forOrganization($orgId))
            ->with('actions')
            ->findOrFail($id);

        return $this->success([
            'run' => $run,
            'actions' => $run->actions->map(fn($a) => [
                'id' => $a->id,
                'action_type' => $a->action_type,
                'target_type' => $a->target_type ? class_basename($a->target_type) : null,
                'target_id' => $a->target_id,
                'description' => $a->description,
                'status' => $a->status,
                'tokens_used' => $a->platform_tokens_used,
                'error_message' => $a->error_message,
                'created_at' => $a->created_at,
            ]),
        ]);
    }

    /**
     * POST /api/admin/agents/{type}/trigger
     * Manually trigger an agent.
     */
    public function trigger(Request $request, string $type): JsonResponse
    {
        $class = BackgroundAgentRegistry::get($type);
        if (!$class) {
            return $this->error('Unknown agent type', [], 404);
        }

        if (method_exists($class, 'isStub') && $class::isStub()) {
            return $this->error('This agent is not yet implemented', [], 400);
        }

        $orgId = $request->attributes->get('organization_id');
        if (!$orgId) {
            return $this->error('Organization context required', [], 400);
        }

        $run = app(BackgroundAgentOrchestrator::class)->dispatchManual($type, $orgId);

        return $this->success([
            'message' => "Agent '{$type}' has been queued for execution",
            'run_id' => $run->id,
            'status' => $run->status,
        ]);
    }

    /**
     * PUT /api/admin/agents/{type}/config
     * Update agent configuration.
     */
    public function updateConfig(Request $request, string $type): JsonResponse
    {
        $class = BackgroundAgentRegistry::get($type);
        if (!$class) {
            return $this->error('Unknown agent type', [], 404);
        }

        $orgId = $request->attributes->get('organization_id');
        if (!$orgId) {
            return $this->error('Organization context required', [], 400);
        }

        $config = BackgroundAgentConfig::getOrCreate($orgId, $type);

        if ($request->has('is_enabled')) {
            $config->is_enabled = $request->boolean('is_enabled');
        }
        if ($request->has('schedule_override')) {
            $config->schedule_override = $request->input('schedule_override');
        }
        if ($request->has('settings')) {
            $config->settings = array_merge($config->settings ?? [], $request->input('settings'));
        }

        $config->save();

        return $this->success($config);
    }

    /**
     * GET /api/admin/agents/quality-issues
     * List content quality issues.
     */
    public function qualityIssues(Request $request): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');

        $query = ContentQualityIssue::query()
            ->when($orgId, fn($q) => $q->forOrganization($orgId))
            ->orderByRaw("FIELD(severity, 'critical', 'warning', 'info')")
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('severity')) {
            $query->bySeverity($request->input('severity'));
        }
        if ($request->filled('issue_type')) {
            $query->where('issue_type', $request->input('issue_type'));
        }

        $issues = $query->paginate($request->integer('per_page', 20));

        return $this->success($issues);
    }

    /**
     * POST /api/admin/agents/quality-issues/{id}/dismiss
     */
    public function dismissIssue(Request $request, int $id): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');

        $issue = ContentQualityIssue::when($orgId, fn($q) => $q->forOrganization($orgId))
            ->findOrFail($id);

        $issue->dismiss();

        return $this->success(['message' => 'Issue dismissed']);
    }

    /**
     * POST /api/admin/agents/quality-issues/{id}/fix
     * Trigger auto-fix for a specific issue.
     */
    public function fixIssue(Request $request, int $id): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');

        $issue = ContentQualityIssue::when($orgId, fn($q) => $q->forOrganization($orgId))
            ->where('auto_fixable', true)
            ->where('status', ContentQualityIssue::STATUS_OPEN)
            ->findOrFail($id);

        // Dispatch a manual trigger of the data quality agent to fix this one issue
        app(BackgroundAgentOrchestrator::class)->dispatchManual('data_quality', $orgId);

        return $this->success(['message' => 'Auto-fix has been queued']);
    }
}
