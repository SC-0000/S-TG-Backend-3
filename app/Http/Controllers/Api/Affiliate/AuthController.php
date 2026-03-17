<?php

namespace App\Http\Controllers\Api\Affiliate;

use App\Http\Controllers\Api\ApiController;
use App\Mail\AffiliateMagicLink;
use App\Models\Affiliate;
use App\Models\Organization;
use App\Services\AffiliateService;
use App\Support\MailContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends ApiController
{
    public function __construct(protected AffiliateService $affiliateService)
    {
    }

    public function requestMagicLink(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
        ]);

        // Always return success to prevent email enumeration
        $affiliate = Affiliate::where('email', $validated['email'])
            ->where('status', 'active')
            ->first();

        if ($affiliate) {
            $magicUrl = $this->affiliateService->generateMagicLink($affiliate);
            $organization = MailContext::resolveOrganization($affiliate->organization_id);
            MailContext::sendMailable($affiliate->email, new AffiliateMagicLink($affiliate, $magicUrl, $organization));
        }

        return $this->success([
            'message' => 'If an account exists with this email, a login link has been sent.',
        ]);
    }

    public function verifyMagicLink(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string|size:64',
        ]);

        $result = $this->affiliateService->verifyMagicToken($validated['token']);

        if (!$result) {
            return $this->error('Invalid or expired login link.', [], 401);
        }

        return $this->success([
            'session_token' => $result['session_token'],
            'affiliate' => $result['affiliate'],
        ]);
    }

    public function signup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:50',
            'organization_id' => 'required|integer|exists:organizations,id',
        ]);

        $org = Organization::findOrFail($validated['organization_id']);

        // Check if open signup is enabled
        if (!$org->getSetting('affiliates.open_signup', false)) {
            return $this->error('Affiliate signup is currently by invitation only.', [], 403);
        }

        // Check if feature is enabled
        if (!$org->featureEnabled('affiliates.enabled')) {
            return $this->error('Affiliate program is not available.', [], 403);
        }

        // Check uniqueness
        $exists = Affiliate::where('organization_id', $org->id)
            ->where('email', $validated['email'])
            ->exists();

        if ($exists) {
            return $this->error('An account with this email already exists.', [], 422);
        }

        $affiliate = Affiliate::create([
            'organization_id' => $org->id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'status' => 'active',
        ]);

        // Send magic link
        $magicUrl = $this->affiliateService->generateMagicLink($affiliate);
        $organization = MailContext::resolveOrganization($org->id);
        MailContext::sendMailable($affiliate->email, new AffiliateMagicLink($affiliate, $magicUrl, $organization));

        return $this->success([
            'message' => 'Account created. Check your email for a login link.',
        ], [], 201);
    }
}
