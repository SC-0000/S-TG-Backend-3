<?php

namespace App\Http\Resources;

class OrganizationResource extends ApiResource
{
    public function toArray($request): array
    {
        $normalizeUrl = static function ($value) {
            if (!is_string($value) || $value == '') {
                return $value;
            }
            if (preg_match('#^https?://#i', $value)) {
                return $value;
            }
            if (str_starts_with($value, '/')) {
                return rtrim(config('app.url'), '/') . $value;
            }
            return $value;
        };

        $logoUrl = $this->getSetting('branding.logo_url') ?? $this->getSetting('branding.logo');
        $logoDarkUrl = $this->getSetting('branding.logo_dark_url');
        $faviconUrl = $this->getSetting('branding.favicon_url') ?? $this->getSetting('branding.favicon');

        $brandingName = $this->getSetting('branding.organization_name')
            ?? $this->getSetting('branding.name')
            ?? $this->name;

        $themeColors = $this->getSetting('theme.colors');
        $brandingColors = $this->getSetting('branding.colors');
        $resolvedColors = $themeColors ?: ($brandingColors ?: [
            'primary' => '#111827',
            'primary_50' => '#F9FAFB',
            'primary_100' => '#F3F4F6',
            'primary_200' => '#E5E7EB',
            'primary_300' => '#D1D5DB',
            'primary_400' => '#9CA3AF',
            'primary_500' => '#6B7280',
            'primary_600' => '#4B5563',
            'primary_700' => '#374151',
            'primary_800' => '#1F2937',
            'primary_900' => '#111827',
            'primary_950' => '#030712',
            'accent' => '#4B5563',
            'accent_50' => '#F9FAFB',
            'accent_100' => '#F3F4F6',
            'accent_200' => '#E5E7EB',
            'accent_300' => '#D1D5DB',
            'accent_400' => '#9CA3AF',
            'accent_500' => '#6B7280',
            'accent_600' => '#4B5563',
            'accent_700' => '#374151',
            'accent_800' => '#1F2937',
            'accent_900' => '#111827',
            'accent_950' => '#030712',
            'accent_soft' => '#9CA3AF',
            'accent_soft_50' => '#F9FAFB',
            'accent_soft_100' => '#F3F4F6',
            'accent_soft_200' => '#E5E7EB',
            'accent_soft_300' => '#D1D5DB',
            'accent_soft_400' => '#9CA3AF',
            'accent_soft_500' => '#6B7280',
            'accent_soft_600' => '#4B5563',
            'accent_soft_700' => '#374151',
            'accent_soft_800' => '#1F2937',
            'accent_soft_900' => '#111827',
            'secondary' => '#6B7280',
            'heavy' => '#111827',
        ]);

        $contactSettings = $this->getSetting('contact', []);
        $brandingContact = $this->getSetting('branding.contact', []);
        $resolvedContact = array_replace_recursive($brandingContact ?: [], $contactSettings ?: []);

        $socialSettings = $this->getSetting('social_media', []);
        $brandingSocial = $this->getSetting('branding.social', [])
            ?: $this->getSetting('branding.social_media', []);
        $resolvedSocial = array_replace_recursive($brandingSocial ?: [], $socialSettings ?: []);

        $branding = [
            'name' => $brandingName,
            'tagline' => $this->getSetting('branding.tagline'),
            'description' => $this->getSetting('branding.description'),
            'year_groups' => $this->getSetting('branding.year_groups', []),
            'logo_url' => $normalizeUrl($logoUrl),
            'logo_dark_url' => $normalizeUrl($logoDarkUrl),
            'favicon_url' => $normalizeUrl($faviconUrl),            'colors' => $resolvedColors,            'contact' => [
                'phone' => data_get($resolvedContact, 'phone'),
                'email' => data_get($resolvedContact, 'email'),
                'address' => data_get($resolvedContact, 'address'),
                'business_hours' => data_get($resolvedContact, 'business_hours'),
            ],
            'social' => $resolvedSocial,
            'custom_css' => $this->getSetting('theme.custom_css'),
        ];

        $apiKeys = $this->getSetting('api_keys', []);
        $billingKey = data_get($apiKeys, 'billing') ?? data_get($apiKeys, 'stripe');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'public_domain' => $this->public_domain,
            'portal_domain' => $this->portal_domain,
            'status' => $this->status,
            'settings' => [
                'branding' => $branding,
                'email' => $this->getSetting('email', []),
                'features' => $this->getSetting('features', []),
                'api_keys' => $request->user()?->isSuperAdmin()
                    ? [
                        'openai' => data_get($apiKeys, 'openai'),
                        'billing' => $billingKey,
                    ]
                    : null,
            ],
        ];
    }
}
