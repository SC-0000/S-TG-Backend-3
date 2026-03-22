<?php

namespace App\Mail;

use App\Models\CommunicationTemplate;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;

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

        $contactEmail = $org?->getSetting('contact.email')
            ?? $org?->getSetting('email.reply_to_email')
            ?? $org?->getSetting('email.from_email')
            ?? config('mail.from.address');
        $contactPhone = $org?->getSetting('contact.phone');
        $contactWebsite = $this->normalizeWebsite(
            $org?->public_domain
                ?? $org?->portal_domain
                ?? config('app.url')
        );
        $contactAddress = $org?->getSetting('contact.address');
        $contactHours = $org?->getSetting('contact.business_hours');

        $themePrimary = $org?->getSetting('theme.colors.primary');
        $themeAccent = $org?->getSetting('theme.colors.accent');
        $emailHeaderColor = $org?->getSetting('email.header_color') ?? $themePrimary ?? '#2563eb';
        $emailHeaderColorSecondary = $org?->getSetting('email.header_color_secondary') ?? $themeAccent ?? '#1d4ed8';
        $emailButtonColor = $org?->getSetting('email.button_color') ?? $themeAccent ?? $emailHeaderColor;
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
            $scheme = 'https';
        }

        return $scheme . '://' . $host;
    }

    protected function normalizeWebsite(?string $value): ?string
    {
        if (! $value || ! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return $trimmed;
        }

        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
            return $trimmed;
        }

        return 'https://' . $trimmed;
    }

    protected function portalUrl(string $path, ?Organization $org = null): ?string
    {
        $base = $this->portalBaseUrl($org);
        if (! $base) {
            return null;
        }
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Resolve a CommunicationTemplate by system key, preferring org-specific over platform defaults.
     * Returns null if no matching template is found.
     */
    protected function resolveSystemTemplate(string $systemKey, array $data): ?array
    {
        $orgId = $this->organization?->id;

        $template = CommunicationTemplate::query()
            ->forSystemKey($systemKey)
            ->active()
            ->where(function ($q) use ($orgId) {
                if ($orgId) {
                    $q->where('organization_id', $orgId)->orWhereNull('organization_id');
                } else {
                    $q->whereNull('organization_id');
                }
            })
            ->orderByDesc('organization_id') // org-specific takes priority over platform
            ->first();

        if (!$template) {
            return null;
        }

        return [
            'subject'   => $template->subject ? str_replace(
                array_map(fn ($k) => '{{' . $k . '}}', array_keys($data)),
                array_values($data),
                $template->subject
            ) : null,
            'body_html' => $template->render($data, 'html'),
            'body_text' => $template->render($data, 'text'),
        ];
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

        $fromEmail = $org->getSetting('email.from_email')
            ?? $org->getSetting('contact.email')
            ?? config('mail.from.address');
        $fromName = $org->getSetting('email.from_name')
            ?? $org->getSetting('branding.organization_name')
            ?? config('mail.from.name');
        $replyTo = $org->getSetting('email.reply_to_email') ?? $org->getSetting('contact.email');
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

        $fallbackMailer = env('MAIL_MAILER', 'smtp');
        if ($fallbackMailer === 'smtp' && !config('mail.mailers.smtp.host')) {
            $fallbackMailer = 'log';
        }

        $configured = false;

        if ($mailer === 'smtp') {
            $smtpHost = $org->getSetting('email.smtp_host');
            if (!$smtpHost) {
                Log::warning('BrandedMailable: SMTP host missing, falling back to default mailer.', [
                    'organization_id' => $org->id,
                ]);
            } else {
                $smtpPassword = $org->getSetting('email.smtp_password');
                if (is_string($smtpPassword)) {
                    $smtpPassword = preg_replace('/\s+/', '', $smtpPassword);
                }
                config([
                    "mail.mailers.{$mailerName}" => [
                        'transport' => 'smtp',
                        'host' => $smtpHost,
                        'port' => (int) ($org->getSetting('email.smtp_port') ?: 587),
                        'encryption' => $org->getSetting('email.smtp_encryption') ?: 'tls',
                        'username' => $org->getSetting('email.smtp_username'),
                        'password' => $smtpPassword,
                        'timeout' => null,
                        'auth_mode' => null,
                    ],
                ]);
                $configured = true;
            }
        }

        if ($mailer === 'mailgun') {
            if (!$org->getSetting('email.mailgun_domain') || !$org->getSetting('email.mailgun_secret')) {
                Log::warning('BrandedMailable: Mailgun config missing, falling back to default mailer.', [
                    'organization_id' => $org->id,
                ]);
            } else {
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
                $configured = true;
            }
        }

        if ($mailer === 'postmark') {
            if (!$org->getSetting('email.postmark_token')) {
                Log::warning('BrandedMailable: Postmark token missing, falling back to default mailer.', [
                    'organization_id' => $org->id,
                ]);
            } else {
                config([
                    'services.postmark' => [
                        'token' => $org->getSetting('email.postmark_token'),
                    ],
                    "mail.mailers.{$mailerName}" => [
                        'transport' => 'postmark',
                    ],
                ]);
                $configured = true;
            }
        }

        if ($mailer === 'ses') {
            if (
                !$org->getSetting('email.ses_key')
                || !$org->getSetting('email.ses_secret')
                || !$org->getSetting('email.ses_region')
            ) {
                Log::warning('BrandedMailable: SES config missing, falling back to default mailer.', [
                    'organization_id' => $org->id,
                ]);
            } else {
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
                $configured = true;
            }
        }

        if ($configured) {
            config(['mail.default' => $mailerName]);
            $this->mailer($mailerName);
        } else {
            config(['mail.default' => $fallbackMailer]);
            $this->mailer($fallbackMailer);
        }

    }
}
