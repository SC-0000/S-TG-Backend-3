<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Api\ApiController;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandingController extends ApiController
{
    public function show(Request $request, Organization $organization): JsonResponse
    {
        $branding = [
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

        return $this->success([
            'branding' => $branding,
        ]);
    }
}
