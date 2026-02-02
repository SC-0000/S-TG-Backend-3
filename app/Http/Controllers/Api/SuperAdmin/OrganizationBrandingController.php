<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class OrganizationBrandingController extends ApiController
{
    public function update(Request $request, Organization $organization): JsonResponse
    {
        $allowedFields = [
            'branding.organization_name',
            'branding.tagline',
            'branding.description',
            'theme.colors.primary',
            'theme.colors.primary_50',
            'theme.colors.primary_100',
            'theme.colors.primary_200',
            'theme.colors.primary_300',
            'theme.colors.primary_400',
            'theme.colors.primary_500',
            'theme.colors.primary_600',
            'theme.colors.primary_700',
            'theme.colors.primary_800',
            'theme.colors.primary_900',
            'theme.colors.primary_950',
            'theme.colors.accent',
            'theme.colors.accent_50',
            'theme.colors.accent_100',
            'theme.colors.accent_200',
            'theme.colors.accent_300',
            'theme.colors.accent_400',
            'theme.colors.accent_500',
            'theme.colors.accent_600',
            'theme.colors.accent_700',
            'theme.colors.accent_800',
            'theme.colors.accent_900',
            'theme.colors.accent_950',
            'theme.colors.accent_soft',
            'theme.colors.accent_soft_50',
            'theme.colors.accent_soft_100',
            'theme.colors.accent_soft_200',
            'theme.colors.accent_soft_300',
            'theme.colors.accent_soft_400',
            'theme.colors.accent_soft_500',
            'theme.colors.accent_soft_600',
            'theme.colors.accent_soft_700',
            'theme.colors.accent_soft_800',
            'theme.colors.accent_soft_900',
            'theme.colors.secondary',
            'theme.colors.heavy',
            'contact.phone',
            'contact.email',
            'contact.address.line1',
            'contact.address.city',
            'contact.address.country',
            'contact.address.postal_code',
            'contact.business_hours',
            'social_media.facebook',
            'social_media.twitter',
            'social_media.instagram',
            'social_media.linkedin',
            'social_media.youtube',
            'email.from_name',
            'email.from_email',
            'email.reply_to_email',
            'email.header_color',
            'email.button_color',
            'email.footer_text',
            'email.footer_disclaimer',
            'theme.custom_css',
        ];

        $allData = $request->all();
        $dataToUpdate = [];

        foreach ($allowedFields as $field) {
            if (!array_key_exists($field, $allData)) {
                continue;
            }

            $value = $allData[$field];
            if ($this->validateField($field, $value)) {
                $dataToUpdate[$field] = $value;
            }
        }

        foreach ($dataToUpdate as $key => $value) {
            $organization->setSetting($key, $value);
        }

        return $this->success([
            'message' => 'Branding updated successfully.',
            'updated_fields' => array_keys($dataToUpdate),
        ]);
    }

    public function uploadLogo(Request $request, Organization $organization): JsonResponse
    {
        $request->validate([
            'logo' => 'required|image|mimes:png,svg,webp,jpg|max:2048',
            'type' => 'required|in:light,dark',
        ]);

        $file = $request->file('logo');

        $oldLogoKey = $request->type === 'dark' ? 'branding.logo_dark_url' : 'branding.logo_url';
        $oldLogo = $organization->getSetting($oldLogoKey);
        if ($oldLogo && Storage::disk('public')->exists(str_replace('/storage/', '', $oldLogo))) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $oldLogo));
        }

        $path = $file->store("organizations/{$organization->id}", 'public');

        $field = $request->type === 'dark' ? 'branding.logo_dark_url' : 'branding.logo_url';
        $organization->setSetting($field, "/storage/{$path}");

        return $this->success([
            'message' => 'Logo uploaded successfully.',
            'logo_url' => "/storage/{$path}",
            'type' => $request->type,
        ]);
    }

    public function uploadFavicon(Request $request, Organization $organization): JsonResponse
    {
        $request->validate([
            'favicon' => 'required|mimes:ico,png|max:100',
        ]);

        $file = $request->file('favicon');

        $oldFavicon = $organization->getSetting('branding.favicon_url');
        if ($oldFavicon && Storage::disk('public')->exists(str_replace('/storage/', '', $oldFavicon))) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $oldFavicon));
        }

        $path = $file->storeAs(
            "organizations/{$organization->id}",
            'favicon.' . $file->extension(),
            'public'
        );

        $organization->setSetting('branding.favicon_url', "/storage/{$path}");

        return $this->success([
            'message' => 'Favicon uploaded successfully.',
            'favicon_url' => "/storage/{$path}",
        ]);
    }

    public function deleteAsset(Request $request, Organization $organization): JsonResponse
    {
        $request->validate([
            'asset_type' => 'required|in:logo,logo_dark,favicon',
        ]);

        $settingKey = [
            'logo' => 'branding.logo_url',
            'logo_dark' => 'branding.logo_dark_url',
            'favicon' => 'branding.favicon_url',
        ][$request->asset_type];

        $assetUrl = $organization->getSetting($settingKey);

        if ($assetUrl && Storage::disk('public')->exists(str_replace('/storage/', '', $assetUrl))) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $assetUrl));
        }

        $organization->setSetting($settingKey, null);

        return $this->success([
            'message' => 'Asset deleted successfully.',
            'asset_type' => $request->asset_type,
        ]);
    }

    private function validateField(string $field, $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (str_contains($field, 'color')) {
            return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1;
        }

        if (str_contains($field, 'social_media')) {
            return filter_var($value, FILTER_VALIDATE_URL) !== false;
        }

        if (str_contains($field, 'email')) {
            return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
        }

        return is_string($value);
    }
}
