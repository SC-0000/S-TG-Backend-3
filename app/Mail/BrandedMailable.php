<?php

namespace App\Mail;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Mail\Mailable;

class BrandedMailable extends Mailable
{
    protected ?Organization $organization = null;

    public function setOrganization(?Organization $organization): static
    {
        $this->organization = $organization;

        return $this;
    }

    protected function resolveOrganization(?int $organizationId = null, ?User $user = null): ?Organization
    {
        if ($organizationId) {
            return Organization::find($organizationId);
        }

        if ($user) {
            $orgId = $user->current_organization_id;
            if (! $orgId) {
                $orgId = $user->children()
                    ->whereNotNull('organization_id')
                    ->value('organization_id');
            }

            if ($orgId) {
                return Organization::find($orgId);
            }
        }

        try {
            $request = app('request');
            if ($request) {
                $orgId = $request->attributes->get('organization_id')
                    ?? $request->header('X-Organization-Id')
                    ?? $request->query('organization_id');

                if ($orgId) {
                    return Organization::find((int) $orgId);
                }
            }
        } catch (\Throwable $e) {
            // Ignore request context errors for queued mails.
        }

        return null;
    }

    protected function brandingData(array $extra = []): array
    {
        $org = $this->organization;

        $brandName = $org?->getSetting('branding.organization_name') ?? config('app.name');
        $brandTagline = $org?->getSetting('branding.tagline');
        $brandDescription = $org?->getSetting('branding.description');
        $brandLogoUrl = $org?->getSetting('branding.logo_url');
        $brandLogoDarkUrl = $org?->getSetting('branding.logo_dark_url');
        $brandFaviconUrl = $org?->getSetting('branding.favicon_url');

        $contactEmail = $org?->getSetting('contact.email') ?? config('mail.from.address');
        $contactPhone = $org?->getSetting('contact.phone');
        $contactWebsite = $org?->getSetting('contact.website') ?? config('app.url');
        $contactAddress = $org?->getSetting('contact.address');
        $contactHours = $org?->getSetting('contact.business_hours');

        $emailHeaderColor = $org?->getSetting('email.header_color') ?? '#2563eb';
        $emailHeaderColorSecondary = $org?->getSetting('email.header_color_secondary') ?? '#1d4ed8';
        $emailButtonColor = $org?->getSetting('email.button_color') ?? $emailHeaderColor;
        $emailButtonColorSecondary = $org?->getSetting('email.button_color_secondary') ?? $emailHeaderColorSecondary;

        $footerText = $org?->getSetting('email.footer_text')
            ?? ('© ' . date('Y') . ' ' . $brandName . '. All rights reserved.');
        $footerDisclaimer = $org?->getSetting('email.footer_disclaimer');

        $socialLinks = $org?->getSetting('social_media') ?? $org?->getSetting('branding.social');

        return array_merge([
            'organization' => $org,
            'brandName' => $brandName,
            'brandTagline' => $brandTagline,
            'brandDescription' => $brandDescription,
            'brandLogoUrl' => $brandLogoUrl,
            'brandLogoDarkUrl' => $brandLogoDarkUrl,
            'brandFaviconUrl' => $brandFaviconUrl,
            'contactEmail' => $contactEmail,
            'contactPhone' => $contactPhone,
            'contactWebsite' => $contactWebsite,
            'contactAddress' => $contactAddress,
            'contactHours' => $contactHours,
            'emailHeaderColor' => $emailHeaderColor,
            'emailHeaderColorSecondary' => $emailHeaderColorSecondary,
            'emailButtonColor' => $emailButtonColor,
            'emailButtonColorSecondary' => $emailButtonColorSecondary,
            'footerText' => $footerText,
            'footerDisclaimer' => $footerDisclaimer,
            'socialLinks' => $socialLinks,
            'supportEmail' => $contactEmail,
            'supportWebsite' => $contactWebsite,
        ], $extra);
    }
}
