<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\TrackingLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrackingLinkController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = TrackingLink::query()->with('affiliate');
        $this->applyOrgScope($request, $query);

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('label', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($affiliateId = $request->input('affiliate_id')) {
            $query->where('affiliate_id', $affiliateId);
        }

        $query->orderBy('created_at', 'desc');
        $paginator = $query->paginate($request->integer('per_page', 15));

        $items = $paginator->getCollection()->map(function ($link) {
            $data = $link->toArray();
            $data['full_url'] = $link->fullUrl();
            $data['conversion_count'] = $link->conversions()->count();
            return $data;
        });

        return $this->paginated($paginator, $items);
    }

    public function store(Request $request): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);

        $validated = $request->validate([
            'label' => 'nullable|string|max:255',
            'type' => 'required|in:affiliate,internal',
            'affiliate_id' => 'nullable|required_if:type,affiliate|integer|exists:affiliates,id',
            'destination_path' => 'nullable|string|max:500',
            'expires_at' => 'nullable|date|after:today',
        ]);

        // Validate affiliate belongs to this org
        if (!empty($validated['affiliate_id'])) {
            $affiliateExists = \App\Models\Affiliate::where('id', $validated['affiliate_id'])
                ->where('organization_id', $orgId)
                ->exists();

            if (!$affiliateExists) {
                return $this->error('Affiliate not found in this organisation.', [], 422);
            }
        }

        $link = TrackingLink::create([
            'organization_id' => $orgId,
            'affiliate_id' => $validated['type'] === 'affiliate' ? ($validated['affiliate_id'] ?? null) : null,
            'code' => TrackingLink::generateCode(),
            'label' => $validated['label'] ?? null,
            'destination_path' => $validated['destination_path'] ?? '/applications/create',
            'type' => $validated['type'],
            'status' => 'active',
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        $link->load('affiliate');

        return $this->success(array_merge($link->toArray(), [
            'full_url' => $link->fullUrl(),
        ]), [], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $link = TrackingLink::with(['affiliate', 'conversions'])->findOrFail($id);

        if ($response = $this->ensureScope($request, $link)) {
            return $response;
        }

        return $this->success(array_merge($link->toArray(), [
            'full_url' => $link->fullUrl(),
            'conversion_count' => $link->conversions()->count(),
        ]));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $link = TrackingLink::findOrFail($id);

        if ($response = $this->ensureScope($request, $link)) {
            return $response;
        }

        $validated = $request->validate([
            'label' => 'nullable|string|max:255',
            'destination_path' => 'nullable|string|max:500',
            'status' => 'sometimes|in:active,paused,expired',
            'expires_at' => 'nullable|date',
        ]);

        $link->update($validated);

        return $this->success(array_merge($link->toArray(), [
            'full_url' => $link->fullUrl(),
        ]));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $link = TrackingLink::findOrFail($id);

        if ($response = $this->ensureScope($request, $link)) {
            return $response;
        }

        $link->delete();

        return $this->success(['message' => 'Tracking link deleted.']);
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
