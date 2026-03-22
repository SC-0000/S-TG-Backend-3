<?php

namespace App\Http\Controllers\Api\Admin;

use App\Constants\AdminSettingsFields;
use App\Http\Controllers\Api\ApiController;
use App\Models\Organization;
use App\Models\OrganizationPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class WorkspaceSettingsController extends ApiController
{
    /**
     * Resolve the current organization from the request context.
     */
    private function resolveOrganization(Request $request): ?Organization
    {
        $orgId = $request->attributes->get('organization_id')
            ?: $request->user()?->current_organization_id;

        if (!$orgId) {
            $orgId = $request->user()?->organizations()->first()?->id;
        }

        return $orgId ? Organization::find($orgId) : null;
    }

    /**
     * GET /api/v1/admin/workspace-settings
     *
     * Returns all admin-safe organisation settings in a structured format.
     */
    public function index(Request $request): JsonResponse
    {
        $org = $this->resolveOrganization($request);

        if (!$org) {
            return $this->error('Organization context required', [], 400);
        }

        $settings = $org->settings ?? [];

        // Extract safe groups only
        $safeSettings = [
            'branding' => [
                'organization_name' => data_get($settings, 'branding.organization_name'),
                'tagline' => data_get($settings, 'branding.tagline'),
                'description' => data_get($settings, 'branding.description'),
                'logo_url' => data_get($settings, 'branding.logo_url'),
                'logo_dark_url' => data_get($settings, 'branding.logo_dark_url'),
                'favicon_url' => data_get($settings, 'branding.favicon_url'),
                'year_groups' => data_get($settings, 'branding.year_groups', []),
            ],
            'theme' => [
                'colors' => data_get($settings, 'theme.colors', []),
                'custom_css' => data_get($settings, 'theme.custom_css'),
            ],
            'contact' => [
                'phone' => data_get($settings, 'contact.phone'),
                'email' => data_get($settings, 'contact.email'),
                'address' => data_get($settings, 'contact.address', []),
                'business_hours' => data_get($settings, 'contact.business_hours'),
            ],
            'social_media' => [
                'facebook' => data_get($settings, 'social_media.facebook'),
                'twitter' => data_get($settings, 'social_media.twitter'),
                'instagram' => data_get($settings, 'social_media.instagram'),
                'linkedin' => data_get($settings, 'social_media.linkedin'),
                'youtube' => data_get($settings, 'social_media.youtube'),
            ],
            'email' => [
                'from_name' => data_get($settings, 'email.from_name'),
                'from_email' => data_get($settings, 'email.from_email'),
                'reply_to_email' => data_get($settings, 'email.reply_to_email'),
                'header_color' => data_get($settings, 'email.header_color'),
                'button_color' => data_get($settings, 'email.button_color'),
                'footer_text' => data_get($settings, 'email.footer_text'),
                'footer_disclaimer' => data_get($settings, 'email.footer_disclaimer'),
                'admin_task_notifications' => data_get($settings, 'email.admin_task_notifications', []),
            ],
            'features' => data_get($settings, 'features', []),
            'feature_overrides' => $this->getFeatureOverrides(),
        ];

        // Attach lightweight plan summary
        $safeSettings['plan_summary'] = $this->getPlanSummary($org);

        return $this->success($safeSettings);
    }

    /**
     * PUT /api/v1/admin/workspace-settings
     *
     * Update organisation settings. Accepts flat dot-notation key-value pairs.
     */
    public function update(Request $request): JsonResponse
    {
        $org = $this->resolveOrganization($request);

        if (!$org) {
            return $this->error('Organization context required', [], 400);
        }

        $allowedFields = AdminSettingsFields::ALLOWED_FIELDS;
        $allData = $request->all();
        $updated = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $allData)) {
                $value = $allData[$field];

                if ($this->validateField($field, $value)) {
                    $org->setSetting($field, $value);
                    $updated[$field] = $value;
                }
            }
        }

        if (empty($updated)) {
            return $this->error('No valid fields provided', [], 422);
        }

        return $this->success([
            'updated_fields' => array_keys($updated),
            'count' => count($updated),
        ]);
    }

    /**
     * POST /api/v1/admin/workspace-settings/upload-logo
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $org = $this->resolveOrganization($request);

        if (!$org) {
            return $this->error('Organization context required', [], 400);
        }

        $request->validate([
            'logo' => 'required|image|mimes:png,svg,webp,jpg|max:2048',
            'type' => 'required|in:light,dark',
        ]);

        $file = $request->file('logo');

        // Delete old logo if exists
        $oldLogoKey = $request->type === 'dark' ? 'branding.logo_dark_url' : 'branding.logo_url';
        $oldLogo = $org->getSetting($oldLogoKey);
        if ($oldLogo && Storage::disk('public')->exists(str_replace('/storage/', '', $oldLogo))) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $oldLogo));
        }

        // Store new logo
        $path = $file->store("organizations/{$org->id}", 'public');
        $settingKey = $request->type === 'dark' ? 'branding.logo_dark_url' : 'branding.logo_url';
        $org->setSetting($settingKey, "/storage/{$path}");

        return $this->success([
            'url' => "/storage/{$path}",
            'type' => $request->type,
        ]);
    }

    /**
     * POST /api/v1/admin/workspace-settings/upload-favicon
     */
    public function uploadFavicon(Request $request): JsonResponse
    {
        $org = $this->resolveOrganization($request);

        if (!$org) {
            return $this->error('Organization context required', [], 400);
        }

        $request->validate([
            'favicon' => 'required|mimes:ico,png|max:100',
        ]);

        $file = $request->file('favicon');

        // Delete old favicon if exists
        $oldFavicon = $org->getSetting('branding.favicon_url');
        if ($oldFavicon && Storage::disk('public')->exists(str_replace('/storage/', '', $oldFavicon))) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $oldFavicon));
        }

        // Store new favicon
        $path = $file->storeAs(
            "organizations/{$org->id}",
            'favicon.' . $file->extension(),
            'public'
        );

        $org->setSetting('branding.favicon_url', "/storage/{$path}");

        return $this->success([
            'url' => "/storage/{$path}",
        ]);
    }

    /**
     * DELETE /api/v1/admin/workspace-settings/delete-asset
     */
    public function deleteAsset(Request $request): JsonResponse
    {
        $org = $this->resolveOrganization($request);

        if (!$org) {
            return $this->error('Organization context required', [], 400);
        }

        $request->validate([
            'asset_type' => 'required|in:logo,logo_dark,favicon',
        ]);

        $settingKey = [
            'logo' => 'branding.logo_url',
            'logo_dark' => 'branding.logo_dark_url',
            'favicon' => 'branding.favicon_url',
        ][$request->asset_type];

        $assetUrl = $org->getSetting($settingKey);

        if ($assetUrl && Storage::disk('public')->exists(str_replace('/storage/', '', $assetUrl))) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $assetUrl));
        }

        $org->setSetting($settingKey, null);

        return $this->success(['deleted' => $request->asset_type]);
    }

    /**
     * Validate an individual field value.
     */
    private function validateField(string $field, $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_bool($value) || $value === 0 || $value === 1) {
            return true;
        }

        // Array fields (e.g. year_groups)
        if ($field === 'branding.year_groups') {
            return is_array($value);
        }

        // Color validation (hex)
        if (strpos($field, 'color') !== false) {
            return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1;
        }

        // URL validation for social media
        if (strpos($field, 'social_media') !== false) {
            return $value === '' || filter_var($value, FILTER_VALIDATE_URL) !== false;
        }

        // Email validation for email address fields
        if (in_array($field, ['email.from_email', 'email.reply_to_email', 'contact.email'])) {
            return $value === '' || filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
        }

        return is_string($value) || is_null($value);
    }

    /**
     * Get feature overrides from system settings and config.
     */
    private function getFeatureOverrides(): array
    {
        $overrides = config('features.overrides', []);
        $systemOverrides = \App\Models\SystemSetting::getValue('feature_overrides', []);

        return array_replace_recursive($systemOverrides, $overrides);
    }

    /**
     * Get a lightweight plan summary for the organisation.
     */
    private function getPlanSummary(Organization $org): array
    {
        $activePlans = OrganizationPlan::where('organization_id', $org->id)
            ->where('status', 'active')
            ->get();

        if ($activePlans->isEmpty()) {
            return [
                'has_plan' => false,
                'plans' => [],
            ];
        }

        $plans = $activePlans->map(function ($plan) {
            return [
                'category' => $plan->category,
                'item_key' => $plan->item_key,
                'status' => $plan->status,
                'quantity' => $plan->quantity,
                'ai_actions_limit' => $plan->ai_actions_limit,
                'ai_actions_used' => $plan->ai_actions_used,
                'expires_at' => $plan->expires_at?->toIso8601String(),
            ];
        });

        return [
            'has_plan' => true,
            'plans' => $plans,
        ];
    }
}
