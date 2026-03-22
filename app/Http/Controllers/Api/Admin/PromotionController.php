<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Promotions\StorePromotionRequest;
use App\Models\Promotion;
use App\Models\PromotionUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromotionController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $orgId = $this->resolveOrgId($request);

        $query = Promotion::query();

        if ($orgId) {
            $query->forOrg($orgId);
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('status')) {
            match ($request->input('status')) {
                'active'   => $query->active(),
                'inactive' => $query->where('is_active', false),
                'expired'  => $query->where('ends_at', '<', now()),
                default    => null,
            };
        }

        $query->orderByDesc('created_at');

        $promotions = $query->paginate($request->input('per_page', 20));

        $data = $promotions->getCollection()->map(fn ($p) => $this->mapPromotion($p))->values();

        // Aggregate stats
        $statsQuery = Promotion::query();
        if ($orgId) $statsQuery->forOrg($orgId);

        $stats = [
            'total'          => $statsQuery->count(),
            'active'         => (clone $statsQuery)->active()->count(),
            'total_discount' => PromotionUsage::whereIn(
                'promotion_id',
                (clone $statsQuery)->pluck('id')
            )->sum('discount_applied'),
        ];

        return $this->paginated($promotions, $data, ['stats' => $stats]);
    }

    public function store(StorePromotionRequest $request): JsonResponse
    {
        $user  = $request->user();
        $orgId = $this->resolveOrgId($request);

        $data = $request->validated();
        $data['organization_id'] = $data['organization_id'] ?? $orgId;
        $data['created_by']      = $user->id;

        // Uppercase the code for consistency
        if (!empty($data['code'])) {
            $data['code'] = strtoupper($data['code']);
        }

        // Validate percentage doesn't exceed 100
        if ($data['discount_type'] === Promotion::DISCOUNT_PERCENTAGE && $data['discount_value'] > 100) {
            return $this->error('Percentage discount cannot exceed 100%.', [], 422);
        }

        $promotion = Promotion::create($data);

        return $this->success([
            'promotion' => $this->mapPromotion($promotion),
            'message'   => 'Promotion created successfully.',
        ], [], 201);
    }

    public function show(Request $request, Promotion $promotion): JsonResponse
    {
        if ($response = $this->ensureScoped($request, $promotion)) {
            return $response;
        }

        $promotion->load(['usages.user', 'usages.transaction', 'creator']);

        $mapped = $this->mapPromotion($promotion, true);

        // Usage stats
        $mapped['total_discount_given'] = $promotion->usages->sum('discount_applied');
        $mapped['unique_users']         = $promotion->usages->pluck('user_id')->unique()->count();
        $mapped['recent_usages']        = $promotion->usages->sortByDesc('created_at')->take(20)->map(fn ($u) => [
            'id'               => $u->id,
            'user_name'        => $u->user?->name ?? 'Unknown',
            'user_email'       => $u->user?->email,
            'discount_applied' => $u->discount_applied,
            'transaction_id'   => $u->transaction_id,
            'used_at'          => $u->created_at?->toIso8601String(),
        ])->values();

        return $this->success(['promotion' => $mapped]);
    }

    public function update(StorePromotionRequest $request, Promotion $promotion): JsonResponse
    {
        if ($response = $this->ensureScoped($request, $promotion)) {
            return $response;
        }

        $data = $request->validated();

        if (!empty($data['code'])) {
            $data['code'] = strtoupper($data['code']);
        }

        if (($data['discount_type'] ?? $promotion->discount_type) === Promotion::DISCOUNT_PERCENTAGE
            && ($data['discount_value'] ?? $promotion->discount_value) > 100) {
            return $this->error('Percentage discount cannot exceed 100%.', [], 422);
        }

        $promotion->update($data);

        return $this->success([
            'promotion' => $this->mapPromotion($promotion->fresh()),
            'message'   => 'Promotion updated successfully.',
        ]);
    }

    public function destroy(Request $request, Promotion $promotion): JsonResponse
    {
        if ($response = $this->ensureScoped($request, $promotion)) {
            return $response;
        }

        $promotion->delete();

        return $this->success(['message' => 'Promotion deleted successfully.']);
    }

    /* ─── Private Helpers ─── */

    private function mapPromotion(Promotion $promotion, bool $detailed = false): array
    {
        $data = [
            'id'                    => $promotion->id,
            'organization_id'       => $promotion->organization_id,
            'code'                  => $promotion->code,
            'name'                  => $promotion->name,
            'description'           => $promotion->description,
            'type'                  => $promotion->type,
            'discount_type'         => $promotion->discount_type,
            'discount_value'        => $promotion->discount_value,
            'min_purchase_amount'   => $promotion->min_purchase_amount,
            'max_discount_amount'   => $promotion->max_discount_amount,
            'usage_limit'           => $promotion->usage_limit,
            'usage_limit_per_user'  => $promotion->usage_limit_per_user,
            'used_count'            => $promotion->used_count,
            'starts_at'             => $promotion->starts_at?->toIso8601String(),
            'ends_at'               => $promotion->ends_at?->toIso8601String(),
            'is_active'             => $promotion->is_active,
            'applicable_to'         => $promotion->applicable_to,
            'applicable_item_ids'   => $promotion->applicable_item_ids,
            'applicable_item_type'  => $promotion->applicable_item_type,
            'status_label'          => $promotion->status_label,
            'created_at'            => $promotion->created_at?->toIso8601String(),
            'updated_at'            => $promotion->updated_at?->toIso8601String(),
        ];

        if ($detailed) {
            $data['created_by_name'] = $promotion->creator?->name;
        }

        return $data;
    }

    private function resolveOrgId(Request $request): ?int
    {
        $user = $request->user();
        if ($user->isSuperAdmin() && $request->filled('organization_id')) {
            return $request->integer('organization_id');
        }
        return $user->current_organization_id ?? $request->attributes->get('organization_id');
    }

    private function ensureScoped(Request $request, Promotion $promotion): ?JsonResponse
    {
        $user = $request->user();
        if (!$user) return $this->error('Unauthenticated.', [], 401);

        $orgId = $this->resolveOrgId($request);

        if ($orgId && $promotion->organization_id && (int) $promotion->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        return null;
    }
}
