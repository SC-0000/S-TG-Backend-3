<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Api\ApiController;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandingController extends ApiController
{
    public function byDomain(Request $request): JsonResponse
    {
        $host = $this->normalizeHost(
            $request->query('host') ?? $request->header('X-Org-Host')
        );

        if (!$host) {
            return $this->error('host is required.', [], 422);
        }

        $organization = Organization::query()
            ->where(function ($query) use ($host) {
                $query->where('portal_domain', $host)
                    ->orWhere('public_domain', $host)
                    ->orWhere('portal_domain', 'like', $host . ':%')
                    ->orWhere('public_domain', 'like', $host . ':%');
            })
            ->first();

        if (!$organization) {
            return $this->error('Organization not found.', [], 404);
        }

        return $this->success([
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'portal_domain' => $organization->portal_domain,
                'public_domain' => $organization->public_domain,
            ],
            'branding' => $this->buildBranding($organization),
        ]);
    }

    public function show(Request $request, Organization $organization): JsonResponse
    {
        return $this->success([
            'branding' => $this->buildBranding($organization),
        ]);
    }

    private function buildBranding(Organization $organization): array
    {
        return [
            'name' => $organization->getSetting('branding.organization_name', $organization->name),
            'logo_url' => $organization->getSetting('branding.logo_url'),
            'logo_dark_url' => $organization->getSetting('branding.logo_dark_url'),
            'favicon_url' => $organization->getSetting('branding.favicon_url'),
            'tagline' => $organization->getSetting('branding.tagline'),
            'description' => $organization->getSetting('branding.description'),
            'contact' => [
                'phone' => $organization->getSetting('contact.phone'),
                'email' => $organization->getSetting('contact.email'),
                'address' => [
                    'line1' => $organization->getSetting('contact.address.line1'),
                    'city' => $organization->getSetting('contact.address.city'),
                    'country' => $organization->getSetting('contact.address.country'),
                    'postal_code' => $organization->getSetting('contact.address.postal_code'),
                ],
                'business_hours' => $organization->getSetting('contact.business_hours'),
            ],
            'social' => [
                'facebook' => $organization->getSetting('social_media.facebook'),
                'twitter' => $organization->getSetting('social_media.twitter'),
                'instagram' => $organization->getSetting('social_media.instagram'),
                'linkedin' => $organization->getSetting('social_media.linkedin'),
                'youtube' => $organization->getSetting('social_media.youtube'),
            ],
            'colors' => $organization->getSetting('theme.colors', []),
            'custom_css' => $organization->getSetting('theme.custom_css'),
        ];
    }

    private function normalizeHost(?string $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim(strtolower($value));
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            $host = parse_url($value, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return $host;
            }
        }

        $value = preg_replace('#/.*$#', '', $value) ?? $value;
        $value = preg_replace('/:\d+$/', '', $value) ?? $value;

        return $value ?: null;
    }
}
