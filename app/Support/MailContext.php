<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MailContext
{
    private static function normalizeHost(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $parsed = parse_url($value);
        if (is_array($parsed) && isset($parsed['host'])) {
            $host = $parsed['host'];
            $port = $parsed['port'] ?? null;
            return Str::lower($port ? "{$host}:{$port}" : $host);
        }

        $value = preg_replace('#^https?://#i', '', $value);
        $value = preg_replace('#/.*$#', '', $value);

        return Str::lower($value);
    }

    public static function resolveOrganization(
        ?int $organizationId = null,
        ?User $user = null,
        $model = null,
        ?Request $request = null
    ): ?Organization {
        if ($organizationId) {
            return Organization::find($organizationId);
        }

        if ($model && isset($model->organization_id) && $model->organization_id) {
            return Organization::find((int) $model->organization_id);
        }

        if ($user) {
            $orgId = $user->current_organization_id;
            if (! $orgId && method_exists($user, 'children')) {
                $orgId = $user->children()
                    ->whereNotNull('organization_id')
                    ->value('organization_id');
            }
            if ($orgId) {
                return Organization::find((int) $orgId);
            }
        }

        if ($request) {
            $orgId = $request->attributes->get('organization_id')
                ?? $request->header('X-Organization-Id')
                ?? $request->query('organization_id')
                ?? $request->input('organization_id');

            if ($orgId) {
                return Organization::find((int) $orgId);
            }

            $candidates = [];
            $origin = self::normalizeHost($request->header('Origin'));
            $referer = self::normalizeHost($request->header('Referer'));
            $forwardedHost = self::normalizeHost($request->header('X-Forwarded-Host'));

            if ($origin) {
                $candidates[] = $origin;
            }
            if ($referer) {
                $candidates[] = $referer;
            }
            if ($forwardedHost) {
                $candidates[] = $forwardedHost;
            }

            $host = self::normalizeHost($request->getHost());
            if ($host) {
                $port = $request->getPort();
                if ($port && ! in_array($port, [80, 443], true)) {
                    $candidates[] = "{$host}:{$port}";
                }
                $candidates[] = $host;
            }

            $candidates = array_values(array_unique(array_filter($candidates)));
            if (count($candidates) > 0) {
                $org = Organization::whereIn('public_domain', $candidates)
                    ->orWhereIn('portal_domain', $candidates)
                    ->first();
                if ($org) {
                    return $org;
                }
            }
        }

        return null;
    }
}
