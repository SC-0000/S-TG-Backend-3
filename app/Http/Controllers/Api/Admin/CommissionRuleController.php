<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\CommissionRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommissionRuleController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = CommissionRule::query();
        $this->applyOrgScope($request, $query);

        $query->orderByDesc('priority')->orderByDesc('created_at');
        $rules = $query->get();

        // Add metadata for the frontend
        return $this->success([
            'rules' => $rules,
            'triggers' => CommissionRule::TRIGGER_LABELS,
            'types' => ['percentage' => 'Percentage', 'flat' => 'Flat Amount'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'trigger' => 'required|in:' . implode(',', CommissionRule::TRIGGERS),
            'commission_type' => 'required|in:percentage,flat',
            'commission_value' => 'required|numeric|min:0.01',
            'conditions' => 'nullable|array',
            'conditions.min_spend' => 'nullable|numeric|min:0',
            'conditions.min_total_spend' => 'nullable|numeric|min:0',
            'priority' => 'nullable|integer|min:0',
            'one_time' => 'boolean',
            'active' => 'boolean',
        ]);

        $orgId = $this->resolveOrgId($request);

        $rule = CommissionRule::create([
            'organization_id' => $orgId,
            'name' => $validated['name'],
            'trigger' => $validated['trigger'],
            'commission_type' => $validated['commission_type'],
            'commission_value' => $validated['commission_value'],
            'conditions' => $validated['conditions'] ?? null,
            'priority' => $validated['priority'] ?? 0,
            'one_time' => $validated['one_time'] ?? true,
            'active' => $validated['active'] ?? true,
        ]);

        return $this->success($rule, [], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $rule = CommissionRule::findOrFail($id);

        if ($response = $this->ensureScope($request, $rule)) {
            return $response;
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'trigger' => 'sometimes|in:' . implode(',', CommissionRule::TRIGGERS),
            'commission_type' => 'sometimes|in:percentage,flat',
            'commission_value' => 'sometimes|numeric|min:0.01',
            'conditions' => 'nullable|array',
            'conditions.min_spend' => 'nullable|numeric|min:0',
            'conditions.min_total_spend' => 'nullable|numeric|min:0',
            'priority' => 'nullable|integer|min:0',
            'one_time' => 'boolean',
            'active' => 'boolean',
        ]);

        $rule->update($validated);

        return $this->success($rule);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $rule = CommissionRule::findOrFail($id);

        if ($response = $this->ensureScope($request, $rule)) {
            return $response;
        }

        $rule->delete();

        return $this->success(['message' => 'Commission rule deleted.']);
    }

    // --- Helpers ---

    private function resolveOrgId(Request $request): int
    {
        $user = $request->user();
        if ($user && $user->isSuperAdmin() && $request->filled('organization_id')) {
            return $request->integer('organization_id');
        }
        return (int) ($request->attributes->get('organization_id') ?? $user?->current_organization_id);
    }

    private function ensureScope(Request $request, $model): ?JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $model->organization_id !== $orgId) {
            return $this->error('Not found.', [], 404);
        }
        return null;
    }

    private function applyOrgScope(Request $request, $query): void
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId) {
            $query->where('organization_id', $orgId);
        }
    }
}
