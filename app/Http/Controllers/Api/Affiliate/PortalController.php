<?php

namespace App\Http\Controllers\Api\Affiliate;

use App\Http\Controllers\Api\ApiController;
use App\Services\AffiliateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalController extends ApiController
{
    public function __construct(protected AffiliateService $affiliateService)
    {
    }

    public function dashboard(Request $request): JsonResponse
    {
        $affiliate = $request->attributes->get('affiliate');
        $stats = $this->affiliateService->getAffiliateStats($affiliate);

        return $this->success([
            'affiliate' => $affiliate,
            'stats' => $stats,
            'commission_rate' => $affiliate->effectiveCommissionRate(),
        ]);
    }

    public function links(Request $request): JsonResponse
    {
        $affiliate = $request->attributes->get('affiliate');

        $links = $affiliate->trackingLinks()
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($link) {
                $data = $link->toArray();
                $data['full_url'] = $link->fullUrl();
                $data['conversion_count'] = $link->conversions()->count();
                return $data;
            });

        return $this->success($links);
    }

    public function conversions(Request $request): JsonResponse
    {
        $affiliate = $request->attributes->get('affiliate');

        $conversions = $affiliate->conversions()
            ->with('trackingLink')
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 15));

        return $this->paginated($conversions, $conversions->items());
    }

    public function payouts(Request $request): JsonResponse
    {
        $affiliate = $request->attributes->get('affiliate');

        $payouts = $affiliate->payouts()
            ->orderBy('paid_at', 'desc')
            ->paginate($request->integer('per_page', 15));

        return $this->paginated($payouts, $payouts->items());
    }

    public function profile(Request $request): JsonResponse
    {
        $affiliate = $request->attributes->get('affiliate');

        return $this->success([
            'id' => $affiliate->id,
            'name' => $affiliate->name,
            'email' => $affiliate->email,
            'phone' => $affiliate->phone,
            'commission_rate' => $affiliate->effectiveCommissionRate(),
            'meta' => $affiliate->meta,
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $affiliate = $request->attributes->get('affiliate');

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:50',
            'meta' => 'nullable|array',
            'meta.payment_details' => 'nullable|string|max:500',
        ]);

        $affiliate->update($validated);

        return $this->success($affiliate);
    }

    public function logout(Request $request): JsonResponse
    {
        $sessionToken = $request->attributes->get('affiliate_session_token');
        if ($sessionToken) {
            $this->affiliateService->revokeSession($sessionToken);
        }

        return $this->success(['message' => 'Logged out.']);
    }
}
