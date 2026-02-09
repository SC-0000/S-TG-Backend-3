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

        $branding = [
            'name' => $this->getSetting('branding.organization_name'),
            'tagline' => $this->getSetting('branding.tagline'),
            'description' => $this->getSetting('branding.description'),
            'logo_url' => $normalizeUrl($logoUrl),
            'logo_dark_url' => $normalizeUrl($logoDarkUrl),
            'favicon_url' => $normalizeUrl($faviconUrl),
            'colors' => $this->getSetting('theme.colors', [
                'primary' => '#411183',
                'primary_50' => '#F8F6FF',
                'primary_100' => '#F0EBFF',
                'primary_200' => '#E1D6FF',
                'primary_300' => '#C9B8FF',
                'primary_400' => '#A688FF',
                'primary_500' => '#8B5CF6',
                'primary_600' => '#7C3AED',
                'primary_700' => '#6D28D9',
                'primary_800' => '#5B21B6',
                'primary_900' => '#411183',
                'primary_950' => '#2E0F5C',
                'accent' => '#1F6DF2',
                'accent_50' => '#EFF6FF',
                'accent_100' => '#DBEAFE',
                'accent_200' => '#BFDBFE',
                'accent_300' => '#93C5FD',
                'accent_400' => '#60A5FA',
                'accent_500' => '#3B82F6',
                'accent_600' => '#2563EB',
                'accent_700' => '#1D4ED8',
                'accent_800' => '#1E40AF',
                'accent_900' => '#1F6DF2',
                'accent_950' => '#172554',
                'accent_soft' => '#f77052',
                'accent_soft_50' => '#FFF7F5',
                'accent_soft_100' => '#FFEDE8',
                'accent_soft_200' => '#FFD9D0',
                'accent_soft_300' => '#FFBAA8',
                'accent_soft_400' => '#FF9580',
                'accent_soft_500' => '#FFA996',
                'accent_soft_600' => '#FF6B47',
                'accent_soft_700' => '#F04A23',
                'accent_soft_800' => '#C73E1D',
                'accent_soft_900' => '#A3341A',
                'secondary' => '#B4C8E8',
                'heavy' => '#1F6DF2',
            ]),
            'contact' => [
                'phone' => $this->getSetting('contact.phone'),
                'email' => $this->getSetting('contact.email'),
                'address' => $this->getSetting('contact.address'),
                'business_hours' => $this->getSetting('contact.business_hours'),
            ],
            'social' => $this->getSetting('social_media', []),
            'custom_css' => $this->getSetting('theme.custom_css'),
        ];

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status,
            'settings' => [
                'branding' => $branding,
                'features' => $this->getSetting('features', []),
            ],
        ];
    }
}
