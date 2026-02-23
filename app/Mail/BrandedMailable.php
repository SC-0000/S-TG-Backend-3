<?php

namespace App\Mail;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Mail\Mailable;

class BrandedMailable extends Mailable
{
    protected ?Organization $organization = null;
    protected bool $emailSettingsApplied = false;

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
        $this->applyEmailSettingsFromOrg($org);
        $portalBaseUrl = $this->portalBaseUrl($org);

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
            'portalBaseUrl' => $portalBaseUrl,
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

    protected function portalBaseUrl(?Organization $org = null): ?string
    {
        $organization = $org ?? $this->organization;
        $value = $organization?->portal_domain;
        if (! $value || ! is_string($value)) {
            return null;
        }

        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        $scheme = null;
        $host = null;
        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            $parsed = parse_url($raw);
            $scheme = $parsed['scheme'] ?? null;
            $host = $parsed['host'] ?? null;
        } else {
            $host = preg_replace('#/.*$#', '', $raw);
        }

        if (! $host) {
            return null;
        }

        if (! $scheme) {
            $isLocal = str_starts_with($host, 'localhost') || str_starts_with($host, '127.0.0.1');
            $scheme = $isLocal ? 'http' : 'https';
        }

        return $scheme . '://' . $host;
    }

    protected function portalUrl(string $path, ?Organization $org = null): ?string
    {
        $base = $this->portalBaseUrl($org);
        if (! $base) {
            return null;
        }
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    protected function applyEmailSettingsFromOrg(?Organization $org): void
    {
        if ($this->emailSettingsApplied) {
            return;
        }
        $this->emailSettingsApplied = true;

        if (! $org) {
            return;
        }

        $fromEmail = $org->getSetting('email.from_email');
        $fromName = $org->getSetting('email.from_name');
        $replyTo = $org->getSetting('email.reply_to_email');
        $mailer = strtolower((string) $org->getSetting('email.mailer'));

        if ($fromEmail) {
            $this->from($fromEmail, $fromName ?: null);
        }

        if ($replyTo) {
            $this->replyTo($replyTo);
        }

        if (! in_array($mailer, ['smtp', 'mailgun', 'postmark', 'ses'], true)) {
            return;
        }

        $mailerName = 'org_dynamic';

        // Ensure we don't reuse a previously-resolved mailer transport.
        try {
            app('mail.manager')->forgetMailers();
        } catch (\Throwable $e) {
            // Ignore cache reset failures.
        }

        if ($mailer === 'smtp') {
            $smtpPassword = $org->getSetting('email.smtp_password');
            if (is_string($smtpPassword)) {
                $smtpPassword = preg_replace('/\s+/', '', $smtpPassword);
            }
            config([
                "mail.mailers.{$mailerName}" => [
                    'transport' => 'smtp',
                    'host' => $org->getSetting('email.smtp_host'),
                    'port' => (int) ($org->getSetting('email.smtp_port') ?: 587),
                    'encryption' => $org->getSetting('email.smtp_encryption') ?: 'tls',
                    'username' => $org->getSetting('email.smtp_username'),
                    'password' => $smtpPassword,
                    'timeout' => null,
                    'auth_mode' => null,
                ],
            ]);
        }

        if ($mailer === 'mailgun') {
            config([
                'services.mailgun' => [
                    'domain' => $org->getSetting('email.mailgun_domain'),
                    'secret' => $org->getSetting('email.mailgun_secret'),
                    'endpoint' => $org->getSetting('email.mailgun_endpoint') ?: 'api.mailgun.net',
                ],
                "mail.mailers.{$mailerName}" => [
                    'transport' => 'mailgun',
                ],
            ]);
        }

        if ($mailer === 'postmark') {
            config([
                'services.postmark' => [
                    'token' => $org->getSetting('email.postmark_token'),
                ],
                "mail.mailers.{$mailerName}" => [
                    'transport' => 'postmark',
                ],
            ]);
        }

        if ($mailer === 'ses') {
            config([
                'services.ses' => [
                    'key' => $org->getSetting('email.ses_key'),
                    'secret' => $org->getSetting('email.ses_secret'),
                    'region' => $org->getSetting('email.ses_region'),
                ],
                "mail.mailers.{$mailerName}" => [
                    'transport' => 'ses',
                ],
            ]);
        }

        config(['mail.default' => $mailerName]);
        $this->mailer($mailerName);

    }
}
