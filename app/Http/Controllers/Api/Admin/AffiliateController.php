<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Mail\AffiliateInvite;
use App\Mail\AffiliateMagicLink;
use App\Mail\AffiliatePayoutNotification;
use App\Models\Affiliate;
use App\Models\AffiliateConversion;
use App\Models\Organization;
use App\Services\AffiliateService;
use App\Support\MailContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AffiliateController extends ApiController
{
    public function __construct(protected AffiliateService $affiliateService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Affiliate::query()->with('organization');
        $this->applyOrgScope($request, $query);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $query->orderBy('created_at', 'desc');
        $paginator = $query->paginate($request->integer('per_page', 15));

        $items = $paginator->getCollection()->map(function ($affiliate) {
            return array_merge($affiliate->toArray(), [
                'stats' => $this->affiliateService->getAffiliateStats($affiliate),
            ]);
        });

        return $this->paginated($paginator, $items);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:50',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'meta' => 'nullable|array',
            'send_invite' => 'boolean',
        ]);

        $orgId = $this->resolveOrgId($request);

        // Check uniqueness within org
        $exists = Affiliate::where('organization_id', $orgId)
            ->where('email', $validated['email'])
            ->exists();

        if ($exists) {
            return $this->error('An affiliate with this email already exists in this organisation.', [], 422);
        }

        $affiliate = Affiliate::create([
            'organization_id' => $orgId,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'commission_rate' => $validated['commission_rate'] ?? null,
            'meta' => $validated['meta'] ?? null,
            'status' => 'active',
        ]);

        if (!empty($validated['send_invite'])) {
            $magicUrl = $this->affiliateService->generateMagicLink($affiliate);
            $organization = MailContext::resolveOrganization($orgId);
            MailContext::sendMailable($affiliate->email, new AffiliateInvite($affiliate, $magicUrl, $organization));
        }

        return $this->success($affiliate, [], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $affiliate = Affiliate::findOrFail($id);

        if ($response = $this->ensureScope($request, $affiliate)) {
            return $response;
        }

        $affiliate->load(['trackingLinks', 'conversions.application', 'payouts']);

        return $this->success(array_merge($affiliate->toArray(), [
            'stats' => $this->affiliateService->getAffiliateStats($affiliate),
        ]));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $affiliate = Affiliate::findOrFail($id);

        if ($response = $this->ensureScope($request, $affiliate)) {
            return $response;
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|max:255',
            'phone' => 'nullable|string|max:50',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'status' => 'sometimes|in:active,inactive,suspended',
            'meta' => 'nullable|array',
        ]);

        if (isset($validated['email']) && $validated['email'] !== $affiliate->email) {
            $exists = Affiliate::where('organization_id', $affiliate->organization_id)
                ->where('email', $validated['email'])
                ->where('id', '!=', $affiliate->id)
                ->exists();

            if ($exists) {
                return $this->error('An affiliate with this email already exists.', [], 422);
            }
        }

        $affiliate->update($validated);

        return $this->success($affiliate);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $affiliate = Affiliate::findOrFail($id);

        if ($response = $this->ensureScope($request, $affiliate)) {
            return $response;
        }

        $affiliate->update(['status' => 'inactive']);

        return $this->success(['message' => 'Affiliate deactivated.']);
    }

    public function sendMagicLink(Request $request, int $id): JsonResponse
    {
        $affiliate = Affiliate::findOrFail($id);

        if ($response = $this->ensureScope($request, $affiliate)) {
            return $response;
        }

        $magicUrl = $this->affiliateService->generateMagicLink($affiliate);
        $organization = MailContext::resolveOrganization($affiliate->organization_id);
        MailContext::sendMailable($affiliate->email, new AffiliateMagicLink($affiliate, $magicUrl, $organization));

        return $this->success(['message' => 'Magic link sent to ' . $affiliate->email]);
    }

    public function conversions(Request $request): JsonResponse
    {
        $query = AffiliateConversion::query()->with(['affiliate', 'trackingLink', 'application']);
        $this->applyOrgScope($request, $query);

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($affiliateId = $request->input('affiliate_id')) {
            $query->where('affiliate_id', $affiliateId);
        }

        $query->orderBy('created_at', 'desc');
        $paginator = $query->paginate($request->integer('per_page', 15));

        return $this->paginated($paginator, $paginator->items());
    }

    public function manualAttribution(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'application_id' => 'required|string|exists:applications,application_id',
            'affiliate_id' => 'required|integer|exists:affiliates,id',
        ]);

        $orgId = $this->resolveOrgId($request);

        $conversion = $this->affiliateService->manualAttribution(
            $validated['application_id'],
            $validated['affiliate_id'],
            $orgId
        );

        return $this->success($conversion, [], 201);
    }

    public function payouts(Request $request, int $affiliateId): JsonResponse
    {
        $affiliate = Affiliate::findOrFail($affiliateId);

        if ($response = $this->ensureScope($request, $affiliate)) {
            return $response;
        }

        $payouts = $affiliate->payouts()->orderBy('paid_at', 'desc')->paginate($request->integer('per_page', 15));

        return $this->paginated($payouts, $payouts->items());
    }

    public function recordPayout(Request $request, int $affiliateId): JsonResponse
    {
        $affiliate = Affiliate::findOrFail($affiliateId);

        if ($response = $this->ensureScope($request, $affiliate)) {
            return $response;
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => 'nullable|string|max:50',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'paid_at' => 'nullable|date',
            'mark_conversions_paid' => 'boolean',
        ]);

        $payout = $this->affiliateService->recordPayout($affiliate, $validated, $request->user());

        // Notify affiliate
        $organization = MailContext::resolveOrganization($affiliate->organization_id);
        MailContext::sendMailable(
            $affiliate->email,
            new AffiliatePayoutNotification($affiliate, $payout, $organization)
        );

        return $this->success($payout, [], 201);
    }

    public function settings(Request $request): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        $org = Organization::findOrFail($orgId);

        return $this->success([
            'enabled' => $org->featureEnabled('affiliates.enabled'),
            'commission_rate' => $org->getSetting('affiliates.commission_rate', 10),
            'open_signup' => $org->getSetting('affiliates.open_signup', false),
            'cookie_duration_days' => $org->getSetting('affiliates.cookie_duration_days', 30),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => 'sometimes|boolean',
            'commission_rate' => 'sometimes|numeric|min:0|max:100',
            'open_signup' => 'sometimes|boolean',
            'cookie_duration_days' => 'sometimes|integer|min:1|max:365',
        ]);

        $orgId = $this->resolveOrgId($request);
        $org = Organization::findOrFail($orgId);

        if (isset($validated['enabled'])) {
            $org->setFeature('affiliates.enabled', $validated['enabled']);
        }

        foreach (['commission_rate', 'open_signup', 'cookie_duration_days'] as $key) {
            if (isset($validated[$key])) {
                $org->setSetting("affiliates.{$key}", $validated[$key]);
            }
        }

        return $this->success(['message' => 'Affiliate settings updated.']);
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
