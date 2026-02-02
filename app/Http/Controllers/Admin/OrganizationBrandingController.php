<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class OrganizationBrandingController extends Controller
{
    /**
     * Update organization branding settings
     */
    public function update(Request $request, Organization $organization)
    {
        // Log the update attempt with raw request data
        // Log::info('Organization branding update initiated', [
        //     'user_id' => auth()->id(),
        //     'user_name' => auth()->user()->name,
        //     'organization_id' => $organization->id,
        //     'organization_name' => $organization->name,
        //     'ip_address' => $request->ip(),
        //     'request_data' => $request->all(), // Log all request data
        // ]);
        
        // Define allowed fields for security (whitelist)
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
        
        // Get only allowed fields from request (flat keys with dots)
        // Use $request->all() because $request->input() treats dots as nested arrays
        $allData = $request->all();
        $dataToUpdate = [];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $allData)) {
                $value = $allData[$field];
                
                // Validate the value based on field type
                if ($this->validateField($field, $value)) {
                    $dataToUpdate[$field] = $value;
                }
            }
        }
        
        // Log what we're about to update
        // Log::info('Processing branding update', [
        //     'data_to_update' => $dataToUpdate,
        //     'field_count' => count($dataToUpdate),
        // ]);
        
        // Update settings using flat keys (setSetting handles dot notation)
        foreach ($dataToUpdate as $key => $value) {
            $organization->setSetting($key, $value);
        }
        
        // Log successful update with details
        // Log::info('Organization branding updated successfully', [
        //     'user_id' => auth()->id(),
        //     'user_name' => auth()->user()->name,
        //     'organization_id' => $organization->id,
        //     'organization_name' => $organization->name,
        //     'updated_fields' => array_keys($dataToUpdate),
        //     'field_count' => count($dataToUpdate),
        // ]);
        
        return back()->with('success', 'Branding updated successfully!');
    }
    
    /**
     * Validate individual field
     */
    private function validateField(string $field, $value): bool
    {
        // If null, it's valid (nullable)
        if ($value === null) {
            return true;
        }
        
        // Color validation (hex)
        if (strpos($field, 'color') !== false && $value !== null) {
            return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1;
        }
        
        // URL validation
        if (strpos($field, 'social_media') !== false && $value !== null) {
            return filter_var($value, FILTER_VALIDATE_URL) !== false;
        }
        
        // Email validation
        if (strpos($field, 'email') !== false && $value !== null) {
            return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
        }
        
        // String validation (max lengths handled by database)
        return is_string($value) || is_null($value);
    }
    
    /**
     * Upload organization logo
     */
    public function uploadLogo(Request $request, Organization $organization)
    {
        $request->validate([
            'logo' => 'required|image|mimes:png,svg,webp,jpg|max:2048',
            'type' => 'required|in:light,dark',
        ]);
        
        // Log logo upload attempt
        // Log::info('Organization logo upload initiated', [
        //     'user_id' => auth()->id(),
        //     'user_name' => auth()->user()->name,
        //     'organization_id' => $organization->id,
        //     'organization_name' => $organization->name,
        //     'logo_type' => $request->type,
        //     'ip_address' => $request->ip(),
        // ]);
        
        $file = $request->file('logo');
        
        // Delete old logo if exists
        $oldLogoKey = $request->type === 'dark' ? 'branding.logo_dark_url' : 'branding.logo_url';
        $oldLogo = $organization->getSetting($oldLogoKey);
        if ($oldLogo && Storage::disk('public')->exists(str_replace('/storage/', '', $oldLogo))) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $oldLogo));
        }
        
        // Store new logo
        $path = $file->store("organizations/{$organization->id}", 'public');
        
        $field = $request->type === 'dark' ? 'branding.logo_dark_url' : 'branding.logo_url';
        $organization->setSetting($field, "/storage/{$path}");
        
        // Log successful logo upload
        // Log::info('Organization logo uploaded successfully', [
        //     'user_id' => auth()->id(),
        //     'user_name' => auth()->user()->name,
        //     'organization_id' => $organization->id,
        //     'organization_name' => $organization->name,
        //     'logo_type' => $request->type,
        //     'logo_path' => $path,
        //     'logo_url' => "/storage/{$path}",
        // ]);
        
        return back()->with('success', 'Logo uploaded successfully!');
    }
    
    /**
     * Upload organization favicon
     */
    public function uploadFavicon(Request $request, Organization $organization)
    {
        $request->validate([
            'favicon' => 'required|mimes:ico,png|max:100',
        ]);
        
        // Log favicon upload attempt
        // Log::info('Organization favicon upload initiated', [
        //     'user_id' => auth()->id(),
        //     'user_name' => auth()->user()->name,
        //     'organization_id' => $organization->id,
        //     'organization_name' => $organization->name,
        //     'ip_address' => $request->ip(),
        // ]);
        
        $file = $request->file('favicon');
        
        // Delete old favicon if exists
        $oldFavicon = $organization->getSetting('branding.favicon_url');
        if ($oldFavicon && Storage::disk('public')->exists(str_replace('/storage/', '', $oldFavicon))) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $oldFavicon));
        }
        
        // Store new favicon
        $path = $file->storeAs(
            "organizations/{$organization->id}",
            'favicon.' . $file->extension(),
            'public'
        );
        
        $organization->setSetting('branding.favicon_url', "/storage/{$path}");
        
        // Log successful favicon upload
        // Log::info('Organization favicon uploaded successfully', [
        //     'user_id' => auth()->id(),
        //     'user_name' => auth()->user()->name,
        //     'organization_id' => $organization->id,
        //     'organization_name' => $organization->name,
        //     'favicon_path' => $path,
        //     'favicon_url' => "/storage/{$path}",
        // ]);
        
        return back()->with('success', 'Favicon uploaded successfully!');
    }
    
    /**
     * Delete uploaded asset
     */
    public function deleteAsset(Request $request, Organization $organization)
    {
        $request->validate([
            'asset_type' => 'required|in:logo,logo_dark,favicon',
        ]);
        
        // Log asset deletion attempt
        // Log::info('Organization asset deletion initiated', [
        //     'user_id' => auth()->id(),
        //     'user_name' => auth()->user()->name,
        //     'organization_id' => $organization->id,
        //     'organization_name' => $organization->name,
        //     'asset_type' => $request->asset_type,
        //     'ip_address' => $request->ip(),
        // ]);
        
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
        
        // Log successful asset deletion
        // Log::info('Organization asset deleted successfully', [
        //     'user_id' => auth()->id(),
        //     'user_name' => auth()->user()->name,
        //     'organization_id' => $organization->id,
        //     'organization_name' => $organization->name,
        //     'asset_type' => $request->asset_type,
        //     'deleted_url' => $assetUrl,
        // ]);
        
        return back()->with('success', 'Asset deleted successfully!');
    }
}
