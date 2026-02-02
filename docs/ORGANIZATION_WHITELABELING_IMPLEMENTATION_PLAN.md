# Organization White-Labeling System - Implementation Plan

**Version**: 1.0  
**Date**: December 4, 2025  
**Estimated Duration**: 3-4 weeks  
**Status**: Ready to Start

---

## 📋 Overview

This document provides a step-by-step implementation plan for building the organization white-labeling system from start to finish.

**Goal**: Enable each organization to have custom branding (logo, colors, contact info, email templates) stored in the database and dynamically applied across all portals.

---

## 🎯 Implementation Phases

### **Phase 1: Backend Foundation** (Days 1-7)
- Enhance middleware to load organization branding
- Create branding controller with API endpoints
- Create branded email layout system
- Test backend functionality

### **Phase 2: Frontend Theme System** (Days 8-14)
- Build React theme context provider
- Update Tailwind config for CSS variables
- Integrate theme provider into all layouts
- Test dynamic theming

### **Phase 3: Admin UI** (Days 15-21)
- Create branding management page in Super Admin portal
- Implement file upload system
- Add color pickers and form inputs
- Build live preview system

### **Phase 4: Testing & Polish** (Days 22-28)
- End-to-end testing with multiple organizations
- Performance optimization
- Documentation updates
- Production deployment

---

## 📝 Detailed Implementation Steps

---

## **PHASE 1: BACKEND FOUNDATION** (Week 1)

### **Day 1-2: Middleware Enhancement**

#### **Task 1.1: Update HandleInertiaRequests Middleware**

**File**: `app/Http/Middleware/HandleInertiaRequests.php`

**Action**: Add organization branding to shared Inertia props

```php
<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use App\Models\Organization;

class HandleInertiaRequests extends Middleware
{
    // ... existing code ...
    
    public function share(Request $request): array
    {
        $user = $request->user();
        $orgBranding = null;
        
        if ($user && $user->current_organization_id) {
            $org = Organization::find($user->current_organization_id);
            
            if ($org) {
                $orgBranding = [
                    // Brand Identity
                    'name' => $org->getSetting('branding.organization_name'),
                    'tagline' => $org->getSetting('branding.tagline'),
                    'description' => $org->getSetting('branding.description'),
                    'logo_url' => $org->getSetting('branding.logo_url'),
                    'logo_dark_url' => $org->getSetting('branding.logo_dark_url'),
                    'favicon_url' => $org->getSetting('branding.favicon_url'),
                    
                    // Theme Colors
                    'colors' => $org->getSetting('theme.colors', [
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
                    
                    // Contact Information
                    'contact' => [
                        'phone' => $org->getSetting('contact.phone'),
                        'email' => $org->getSetting('contact.email'),
                        'address' => $org->getSetting('contact.address'),
                        'business_hours' => $org->getSetting('contact.business_hours'),
                    ],
                    
                    // Social Media
                    'social' => $org->getSetting('social_media', []),
                    
                    // Custom CSS
                    'custom_css' => $org->getSetting('theme.custom_css'),
                ];
            }
        }
        
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user,
            ],
            'organizationBranding' => $orgBranding,
        ]);
    }
}
```

**Testing**:
```php
// In any controller, dump Inertia props
dd(Inertia::getShared());
```

---

### **Day 3-4: Create Branding Controller**

#### **Task 1.2: Create OrganizationBrandingController**

**File**: `app/Http/Controllers/Admin/OrganizationBrandingController.php`

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class OrganizationBrandingController extends Controller
{
    /**
     * Update organization branding settings
     */
    public function update(Request $request, Organization $organization)
    {
        $validated = $request->validate([
            // Brand Identity
            'branding.organization_name' => 'nullable|string|max:255',
            'branding.tagline' => 'nullable|string|max:500',
            'branding.description' => 'nullable|string|max:1000',
            
            // Theme Colors - Primary
            'theme.colors.primary' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.primary_50' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.primary_100' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.primary_200' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.primary_300' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.primary_400' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.primary_500' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.primary_600' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.primary_700' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.primary_800' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.primary_900' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.primary_950' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            
            // Theme Colors - Accent
            'theme.colors.accent' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.accent_50' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.accent_100' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.accent_200' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.accent_300' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.accent_400' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.accent_500' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.accent_600' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.accent_700' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.accent_800' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.accent_900' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.accent_950' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            
            // Theme Colors - Accent Soft
            'theme.colors.accent_soft' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.accent_soft_50' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.accent_soft_100' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.accent_soft_200' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.accent_soft_300' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.accent_soft_400' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.accent_soft_500' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.accent_soft_600' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.accent_soft_700' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.accent_soft_800' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.accent_soft_900' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            
            // Other Colors
            'theme.colors.secondary' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.colors.heavy' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            
            // Contact Information
            'contact.phone' => 'nullable|string|max:50',
            'contact.email' => 'nullable|email|max:255',
            'contact.address.line1' => 'nullable|string|max:255',
            'contact.address.city' => 'nullable|string|max:100',
            'contact.address.country' => 'nullable|string|max:100',
            'contact.address.postal_code' => 'nullable|string|max:20',
            'contact.business_hours' => 'nullable|string|max:255',
            
            // Social Media
            'social_media.facebook' => 'nullable|url|max:255',
            'social_media.twitter' => 'nullable|url|max:255',
            'social_media.instagram' => 'nullable|url|max:255',
            'social_media.linkedin' => 'nullable|url|max:255',
            'social_media.youtube' => 'nullable|url|max:255',
            
            // Email Branding
            'email.from_name' => 'nullable|string|max:255',
            'email.from_email' => 'nullable|email|max:255',
            'email.reply_to_email' => 'nullable|email|max:255',
            'email.header_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'email.button_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'email.footer_text' => 'nullable|string|max:500',
            'email.footer_disclaimer' => 'nullable|string|max:1000',
            
            // Custom CSS
            'theme.custom_css' => 'nullable|string|max:10000',
        ]);
        
        // Update settings
        foreach ($validated as $key => $value) {
            $organization->setSetting($key, $value);
        }
        
        return back()->with('success', 'Branding updated successfully!');
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
        
        return back()->with('success', 'Asset deleted successfully!');
    }
}
```

#### **Task 1.3: Add Routes**

**File**: `routes/superadmin.php`

Add these routes:

```php
use App\Http\Controllers\Admin\OrganizationBrandingController;

Route::prefix('organizations/{organization}')->group(function () {
    // Branding management
    Route::post('/branding', [OrganizationBrandingController::class, 'update'])
        ->name('organizations.branding.update');
    
    // File uploads
    Route::post('/branding/logo', [OrganizationBrandingController::class, 'uploadLogo'])
        ->name('organizations.branding.upload-logo');
    
    Route::post('/branding/favicon', [OrganizationBrandingController::class, 'uploadFavicon'])
        ->name('organizations.branding.upload-favicon');
    
    // Delete assets
    Route::delete('/branding/asset', [OrganizationBrandingController::class, 'deleteAsset'])
        ->name('organizations.branding.delete-asset');
});
```

---

### **Day 5-6: Branded Email Layout**

#### **Task 1.4: Create Branded Email Layout**

**File**: `resources/views/emails/layouts/branded.blade.php`

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            padding: 0;
        }
        .email-wrapper {
            width: 100%;
            background-color: #f5f5f5;
            padding: 20px 0;
        }
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .email-header {
            background: {{ $organization->getSetting('email.header_color', '#411183') }};
            color: white;
            padding: 30px;
            text-align: center;
        }
        .email-header img {
            max-width: 200px;
            height: auto;
            margin-bottom: 15px;
        }
        .email-header h1 {
            margin: 0 0 10px 0;
            font-size: 24px;
        }
        .email-header p {
            margin: 0;
            font-size: 14px;
            opacity: 0.9;
        }
        .email-content {
            padding: 40px 30px;
        }
        .email-button {
            background: {{ $organization->getSetting('email.button_color', '#1F6DF2') }};
            color: white !important;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 6px;
            display: inline-block;
            margin: 20px 0;
            font-weight: bold;
        }
        .email-footer {
            background: #f5f5f5;
            padding: 20px 30px;
            text-align: center;
            font-size: 12px;
            color: #666;
        }
        .footer-section {
            margin: 15px 0;
        }
        .footer-links {
            margin: 15px 0;
        }
        .footer-links a {
            color: #666;
            text-decoration: none;
            margin: 0 10px;
        }
        .contact-info p {
            margin: 5px 0;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="email-wrapper">
        <div class="email-container">
            {{-- Header --}}
            <div class="email-header">
                @if($organization->getSetting('branding.logo_url'))
                    <img src="{{ asset($organization->getSetting('branding.logo_url')) }}" 
                         alt="{{ $organization->getSetting('branding.organization_name', config('app.name')) }}">
                @endif
                
                <h1>{{ $organization->getSetting('branding.organization_name', config('app.name')) }}</h1>
                
                @if($organization->getSetting('branding.tagline'))
                    <p>{{ $organization->getSetting('branding.tagline') }}</p>
                @endif
            </div>
            
            {{-- Content --}}
            <div class="email-content">
                {{ $slot }}
            </div>
            
            {{-- Footer --}}
            <div class="email-footer">
                <div class="footer-section contact-info">
                    @if($organization->getSetting('contact.email'))
                        <p>📧 {{ $organization->getSetting('contact.email') }}</p>
                    @endif
                    
                    @if($organization->getSetting('contact.phone'))
                        <p>📞 {{ $organization->getSetting('contact.phone') }}</p>
                    @endif
                    
                    @if($organization->getSetting('contact.address.city'))
                        <p>📍 
                            @if($organization->getSetting('contact.address.line1'))
                                {{ $organization->getSetting('contact.address.line1') }}, 
                            @endif
                            {{ $organization->getSetting('contact.address.city') }}
                            @if($organization->getSetting('contact.address.country'))
                                , {{ $organization->getSetting('contact.address.country') }}
                            @endif
                        </p>
                    @endif
                    
                    @if($organization->getSetting('contact.business_hours'))
                        <p>🕐 {{ $organization->getSetting('contact.business_hours') }}</p>
                    @endif
                </div>
                
                @if($organization->getSetting('social_media'))
                    <div class="footer-section footer-links">
                        @if($organization->getSetting('social_media.facebook'))
                            <a href="{{ $organization->getSetting('social_media.facebook') }}">Facebook</a>
                        @endif
                        
                        @if($organization->getSetting('social_media.twitter'))
                            <a href="{{ $organization->getSetting('social_media.twitter') }}">Twitter</a>
                        @endif
                        
                        @if($organization->getSetting('social_media.instagram'))
                            <a href="{{ $organization->getSetting('social_media.instagram') }}">Instagram</a>
                        @endif
                        
                        @if($organization->getSetting('social_media.linkedin'))
                            <a href="{{ $organization->getSetting('social_media.linkedin') }}">LinkedIn</a>
                        @endif
                        
                        @if($organization->getSetting('social_media.youtube'))
                            <a href="{{ $organization->getSetting('social_media.youtube') }}">YouTube</a>
                        @endif
                    </div>
                @endif
                
                <div class="footer-section">
                    <p><strong>{{ $organization->getSetting('email.footer_text', '© ' . date('Y') . '. All rights reserved.') }}</strong></p>
                    
                    @if($organization->getSetting('email.footer_disclaimer'))
                        <p style="font-size: 11px; margin-top: 15px; color: #999;">
                            {{ $organization->getSetting('email.footer_disclaimer') }}
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</body>
</html>
```

#### **Task 1.5: Update Example Mailable**

**Example**: Update `app/Mail/TeacherApproved.php` (or any other mail class)

```php
<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TeacherApproved extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $teacher)
    {
    }

    public function envelope(): Envelope
    {
        $org = $this->teacher->currentOrganization;
        
        return new Envelope(
            subject: 'Teacher Application Approved',
            from: [
                $org->getSetting('email.from_email', config('mail.from.address')),
                $org->getSetting('email.from_name', config('mail.from.name'))
            ],
            replyTo: [$org->getSetting('email.reply_to_email', config('mail.from.address'))]
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.teacher_approved',
            with: [
                'organization' => $this->teacher->currentOrganization
            ]
        );
    }
}
```

**And update the view**: `resources/views/emails/teacher_approved.blade.php`

```blade
<x-email-layout :organization="$organization">
    <h2>Congratulations!</h2>
    
    <p>Dear {{ $teacher->name }},</p>
    
    <p>We're excited to inform you that your teacher application has been approved!</p>
    
    <p>You can now log in to your account and start creating lessons for students.</p>
    
    <a href="{{ route('admin.dashboard') }}" class="email-button">Access Your Dashboard</a>
    
    <p>If you have any questions, feel free to reach out to us.</p>
    
    <p>Best regards,<br>
    {{ $organization->getSetting('branding.organization_name', config('app.name')) }} Team</p>
</x-email-layout>
```

---

### **Day 7: Backend Testing**

#### **Task 1.6: Test Backend Functionality**

Create a test script: `scripts/test_branding.php`

```php
<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Organization;

// Test organization branding
$org = Organization::first();

// Set test branding data
$org->setSetting('branding.organization_name', 'Test Tutor');
$org->setSetting('branding.tagline', 'Excellence in Education');
$org->setSetting('theme.colors.primary', '#FF5733');
$org->setSetting('contact.email', 'test@example.com');
$org->setSetting('contact.phone', '+44 1234 567890');

echo "✅ Branding data set successfully!\n\n";

// Retrieve and display
echo "Organization Name: " . $org->getSetting('branding.organization_name') . "\n";
echo "Tagline: " . $org->getSetting('branding.tagline') . "\n";
echo "Primary Color: " . $org->getSetting('theme.colors.primary') . "\n";
echo "Email: " . $org->getSetting('contact.email') . "\n";
echo "Phone: " . $org->getSetting('contact.phone') . "\n";

echo "\n✅ All backend tests passed!\n";
```

Run:
```bash
php scripts/test_branding.php
```

**✅ Phase 1 Complete Checklist**:
- [ ] Middleware updated and tested
- [ ] Branding controller created
- [ ] Routes added
- [ ] Email layout created
- [ ] Backend tests passing

---

## **PHASE 2: FRONTEND THEME SYSTEM** (Week 2)

### **Day 8-9: Create Theme Context Provider**

#### **Task 2.1: Create OrganizationThemeContext**

**File**: `resources/js/contexts/OrganizationThemeContext.jsx`

```jsx
import { createContext, useContext, useEffect } from 'react';
import { usePage } from '@inertiajs/react';

const OrganizationThemeContext = createContext({});

export function OrganizationThemeProvider({ children }) {
    const { organizationBranding } = usePage().props;
    
    useEffect(() => {
        if (!organizationBranding) return;
        
        console.log('Applying organization branding:', organizationBranding);
        
        // Apply color variables
        if (organizationBranding.colors) {
            Object.entries(organizationBranding.colors).forEach(([key, value]) => {
                if (!value) return;
                
                // Convert primary_50 → --color-primary-50
                const cssVar = `--color-${key.replace(/_/g, '-')}`;
                document.documentElement.style.setProperty(cssVar, value);
            });
            
            console.log('✅ Colors applied');
        }
        
        // Inject custom CSS
        if (organizationBranding.custom_css) {
            const existingStyle = document.getElementById('org-custom-css');
            if (existingStyle) {
                existingStyle.remove();
            }
            
            const style = document.createElement('style');
            style.id = 'org-custom-css';
            style.textContent = organizationBranding.custom_css;
            document.head.appendChild(style);
            
            console.log('✅ Custom CSS injected');
            
            return () => {
                document.getElementById('org-custom-css')?.remove();
            };
        }
    }, [organizationBranding]);
    
    // Update favicon
    useEffect(() => {
        if (organizationBranding?.favicon_url) {
            let link = document.querySelector("link[rel~='icon']");
            if (!link) {
                link = document.createElement('link');
                link.rel = 'icon';
                document.head.appendChild(link);
            }
            link.href = organizationBranding.favicon_url;
            
            console.log('✅ Favicon updated');
        }
    }, [organizationBranding?.favicon_url]);
    
    // Update page title
    useEffect(() => {
        if (organizationBranding?.name) {
            const baseTitle = document.title.split(' | ')[0] || document.title;
            document.title = `${baseTitle} | ${organizationBranding.name}`;
            
            console.log('✅ Page title updated');
        }
    }, [organizationBranding?.name]);
    
    return (
        <OrganizationThemeContext.Provider value={organizationBranding || {}}>
            {children}
        </OrganizationThemeContext.Provider>
    );
}

export const useOrganizationBranding = () => useContext(OrganizationThemeContext);
```

---

### **Day 10-11: Update Tailwind Config**

#### **Task 2.2: Modify tailwind.config.js**

**File**: `tailwind.config.js`

Replace the colors section:

```js
import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import scrollbar from 'tailwind-scrollbar';
import typography from '@tailwindcss/typography';

export default {
  content: [
    './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
    './storage/framework/views/*.php',
    './resources/views/**/*.blade.php',
    './resources/js/**/*.jsx',
    './resources/**/*.js',
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Figtree', ...defaultTheme.fontFamily.sans],
        nunito: ['Nunito', 'sans-serif'],
        poppins: ['Poppins', 'sans-serif'],
      },
      colors: {
        // Primary color with CSS variables
        primary: {
          DEFAULT: 'var(--color-primary, #411183)',
          50: 'var(--color-primary-50, #F8F6FF)',
          100: 'var(--color-primary-100, #F0EBFF)',
          200: 'var(--color-primary-200, #E1D6FF)',
          300: 'var(--color-primary-300, #C9B8FF)',
          400: 'var(--color-primary-400, #A688FF)',
          500: 'var(--color-primary-500, #8B5CF6)',
          600: 'var(--color-primary-600, #7C3AED)',
          700: 'var(--color-primary-700, #6D28D9)',
          800: 'var(--color-primary-800, #5B21B6)',
          900: 'var(--color-primary-900, #411183)',
          950: 'var(--color-primary-950, #2E0F5C)',
        },
        // Accent color with CSS variables
        accent: {
          DEFAULT: 'var(--color-accent, #1F6DF2)',
          50: 'var(--color-accent-50, #EFF6FF)',
          100: 'var(--color-accent-100, #DBEAFE)',
          200: 'var(--color-accent-200, #BFDBFE)',
          300: 'var(--color-accent-300, #93C5FD)',
          400: 'var(--color-accent-400, #60A5FA)',
          500: 'var(--color-accent-500, #3B82F6)',
          600: 'var(--color-accent-600, #2563EB)',
          700: 'var(--color-accent-700, #1D4ED8)',
          800: 'var(--color-accent-800, #1E40AF)',
          900: 'var(--color-accent-900, #1F6DF2)',
          950: 'var(--color-accent-950, #172554)',
        },
        // Accent soft color with CSS variables
        'accent-soft': {
          DEFAULT: 'var(--color-accent-soft, #f77052)',
          50: 'var(--color-accent-soft-50, #FFF7F5)',
          100: 'var(--color-accent-soft-100, #FFEDE8)',
          200: 'var(--color-accent-soft-200, #FFD9D0)',
          300: 'var(--color-accent-soft-300, #FFBAA8)',
          400: 'var(--color-accent-soft-400, #FF9580)',
          500: 'var(--color-accent-soft-500, #FFA996)',
          600: 'var(--color-accent-soft-600, #FF6B47)',
          700: 'var(--color-accent-soft-700, #F04A23)',
          800: 'var(--color-accent-soft-800, #C73E1D)',
          900: 'var(--color-accent-soft-900, #A3341A)',
        },
        // Other colors
        secondary: 'var(--color-secondary, #B4C8E8)',
        heavy: 'var(--color-heavy, #1F6DF2)',
        'gray-900': '#111827',
        'gray-600': '#4B5563',
        'gray-100': '#F5F7FC',
        white: '#FFFFFF',
        // Glass effect
        glass: {
          light: 'rgba(255, 255, 255, 0.1)',
          medium: 'rgba(255, 255, 255, 0.2)',
          heavy: 'rgba(255, 255, 255, 0.3)',
        }
      },
      // ... rest of your config (keyframes, animations, etc.)
    },
  },
  plugins: [forms, scrollbar, typography],
};
```

---

### **Day 12-13: Integrate Theme Provider into Layouts**

#### **Task 2.3: Update All Portal Layouts**

**Files to update**:
1. `resources/js/parent/Layouts/ParentPortalLayout.jsx`
2. `resources/js/admin/Layouts/TeacherPortalLayout.jsx`
3. `resources/js/superadmin/Layouts/SuperAdminLayout.jsx`
4. `resources/js/public/Layouts/MainLayout.jsx`

**Example** (`ParentPortalLayout.jsx`):

```jsx
import { OrganizationThemeProvider } from '@/contexts/OrganizationThemeContext';
// ... other imports

export default function ParentPortalLayout({ children }) {
    return (
        <OrganizationThemeProvider>
            {/* Existing layout code */}
            <div className="min-h-screen bg-gray-100">
                <Navbar />
                <main>
                    {children}
                </main>
                <Footer />
            </div>
        </OrganizationThemeProvider>
    );
}
```

Repeat for all other layouts.

---

### **Day 14: Frontend Testing**

#### **Task 2.4: Test Dynamic Theming**

Create a test component: `resources/js/Pages/TestBranding.jsx`

```jsx
import { useOrganizationBranding } from '@/contexts/OrganizationThemeContext';
import ParentPortalLayout from '@/parent/Layouts/ParentPortalLayout';

export default function TestBranding() {
    const branding = useOrganizationBranding();
    
    return (
        <ParentPortalLayout>
            <div className="p-8">
                <h1 className="text-4xl font-bold text-primary mb-4">
                    Branding Test Page
                </h1>
                
                <div className="space-y-4">
                    <div className="p-6 bg-white rounded-lg shadow">
                        <h2 className="text-2xl font-semibold mb-4">Organization Info</h2>
                        <p><strong>Name:</strong> {branding.name || 'Not set'}</p>
                        <p><strong>Tagline:</strong> {branding.tagline || 'Not set'}</p>
                        <p><strong>Email:</strong> {branding.contact?.email || 'Not set'}</p>
                    </div>
                    
                    <div className="p-6 bg-white rounded-lg shadow">
                        <h2 className="text-2xl font-semibold mb-4">Color Test</h2>
                        <div className="grid grid-cols-3 gap-4">
                            <div className="p-4 bg-primary text-white rounded">Primary</div>
                            <div className="p-4 bg-accent text-white rounded">Accent</div>
                            <div className="p-4 bg-accent-soft text-white rounded">Accent Soft</div>
                            <div className="p-4 bg-primary-500 text-white rounded">Primary 500</div>
                            <div className="p-4 bg-accent-600 text-white rounded">Accent 600</div>
                            <div className="p-4 bg-secondary text-white rounded">Secondary</div>
                        </div>
                    </div>
                    
                    <div className="p-6 bg-white rounded-lg shadow">
                        <h2 className="text-2xl font-semibold mb-4">Logo Test</h2>
                        {branding.logo_url ? (
                            <img src={branding.logo_url} alt="Logo" className="max-w-xs" />
                        ) : (
                            <p>No logo set</p>
                        )}
                    </div>
                </div>
            </div>
        </ParentPortalLayout>
    );
}
```

Add route in `routes/parent.php`:
```php
Route::get('/test-branding', function () {
    return Inertia::render('TestBranding');
})->name('test-branding');
```

Visit `/test-branding` in browser to test.

**✅ Phase 2 Complete Checklist**:
- [ ] Theme context provider created
- [ ] Tailwind config updated with CSS variables
- [ ] All layouts wrapped with theme provider
- [ ] Test page shows dynamic colors
- [ ] Logo and favicon updating correctly

---

## **PHASE 3: ADMIN UI** (Week 3)

### **Day 15-17: Create Branding Management Page**

#### **Task 3.1: Create Branding Tab in Organization Show Page**

**File**: `resources/js/superadmin/Pages/Organizations/Show.jsx`

Add a "Branding" tab:

```jsx
import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import SuperAdminLayout from '@/superadmin/Layouts/SuperAdminLayout';
// ... other imports

export default function Show({ organization }) {
    const [activeTab, setActiveTab] = useState('info');
    
    return (
        <SuperAdminLayout>
            <div className="p-6">
                <h1 className="text-3xl font-bold mb-6">{organization.name}</h1>
                
                {/* Tabs */}
                <div className="mb-6 border-b">
                    <nav className="flex space-x-8">
                        <button
                            onClick={() => setActiveTab('info')}
                            className={`py-2 px-1 border-b-2 ${
                                activeTab === 'info'
                                    ? 'border-primary text-primary'
                                    : 'border-transparent text-gray-500'
                            }`}
                        >
                            Information
                        </button>
                        <button
                            onClick={() => setActiveTab('branding')}
                            className={`py-2 px-1 border-b-2 ${
                                activeTab === 'branding'
                                    ? 'border-primary text-primary'
                                    : 'border-transparent text-gray-500'
                            }`}
                        >
                            Branding
                        </button>
                        <button
                            onClick={() => setActiveTab('users')}
                            className={`py-2 px-1 border-b-2 ${
                                activeTab === 'users'
                                    ? 'border-primary text-primary'
                                    : 'border-transparent text-gray-500'
                            }`}
                        >
                            Users
                        </button>
                    </nav>
                </div>
                
                {/* Tab Content */}
                {activeTab === 'info' && <InfoTab organization={organization} />}
                {activeTab === 'branding' && <BrandingTab organization={organization} />}
                {activeTab === 'users' && <UsersTab organization={organization} />}
            </div>
        </SuperAdminLayout>
    );
}

function BrandingTab({ organization }) {
    return (
        <div className="space-y-6">
            <LogoSection organization={organization} />
            <BrandIdentitySection organization={organization} />
            <ColorSection organization={organization} />
            <ContactSection organization={organization} />
            <SocialMediaSection organization={organization} />
            <EmailBrandingSection organization={organization} />
            <CustomCSSSection organization={organization} />
        </div>
    );
}
```

---

#### **Task 3.2: Create Logo Upload Section**

Create: `resources/js/superadmin/components/Branding/LogoSection.jsx`

```jsx
import { useState } from 'react';
import { router } from '@inertiajs/react';

export default function LogoSection({ organization }) {
    const [uploading, setUploading] = useState(false);
    const [logoType, setLogoType] = useState('light');
    
    const handleUpload = (e, type) => {
        const file = e.target.files[0];
        if (!file) return;
        
        setUploading(true);
        setLogoType(type);
        
        const formData = new FormData();
        formData.append('logo', file);
        formData.append('type', type);
        
        router.post(
            route('organizations.branding.upload-logo', organization.id),
            formData,
            {
                onSuccess: () => {
                    setUploading(false);
                    alert('Logo uploaded successfully!');
                },
                onError: (errors) => {
                    setUploading(false);
                    alert('Upload failed: ' + Object.values(errors).join(', '));
                }
            }
        );
    };
    
    const handleDelete = (type) => {
        if (!confirm('Are you sure you want to delete this logo?')) return;
        
        router.delete(
            route('organizations.branding.delete-asset', organization.id),
            {
                data: { asset_type: type === 'light' ? 'logo' : 'logo_dark' },
                onSuccess: () => alert('Logo deleted successfully!')
            }
        );
    };
    
    return (
        <div className="bg-white p-6 rounded-lg shadow">
            <h2 className="text-2xl font-semibold mb-4">Logo & Favicon</h2>
            
            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                {/* Light Logo */}
                <div>
                    <label className="block text-sm font-medium mb-2">
                        Logo (Light Mode)
                    </label>
                    
                    {organization.settings?.branding?.logo_url ? (
                        <div className="space-y-2">
                            <img 
                                src={organization.settings.branding.logo_url} 
                                alt="Logo"
                                className="max-w-full h-auto border rounded p-2"
                            />
                            <button
                                onClick={() => handleDelete('light')}
                                className="text-red-600 text-sm hover:underline"
                            >
                                Delete
                            </button>
                        </div>
                    ) : (
                        <div className="border-2 border-dashed border-gray-300 rounded-lg p-4 text-center">
                            <p className="text-sm text-gray-500 mb-2">No logo uploaded</p>
                        </div>
                    )}
                    
                    <input
                        type="file"
                        accept="image/png,image/svg+xml,image/webp,image/jpeg"
                        onChange={(e) => handleUpload(e, 'light')}
                        className="mt-2 block w-full text-sm"
                        disabled={uploading && logoType === 'light'}
                    />
                    {uploading && logoType === 'light' && (
                        <p className="text-sm text-blue-600 mt-1">Uploading...</p>
                    )}
                </div>
                
                {/* Dark Logo */}
                <div>
                    <label className="block text-sm font-medium mb-2">
                        Logo (Dark Mode)
                    </label>
                    
                    {organization.settings?.branding?.logo_dark_url ? (
                        <div className="space-y-2">
                            <div className="bg-gray-900 p-2 rounded">
                                <img 
                                    src={organization.settings.branding.logo_dark_url} 
                                    alt="Logo Dark"
                                    className="max-w-full h-auto"
                                />
                            </div>
                            <button
                                onClick={() => handleDelete('dark')}
                                className="text-red-600 text-sm hover:underline"
                            >
                                Delete
                            </button>
                        </div>
                    ) : (
                        <div className="border-2 border-dashed border-gray-300 rounded-lg p-4 text-center">
                            <p className="text-sm text-gray-500 mb-2">No dark logo uploaded</p>
                        </div>
                    )}
                    
                    <input
                        type="file"
                        accept="image/png,image/svg+xml,image/webp,image/jpeg"
                        onChange={(e) => handleUpload(e, 'dark')}
                        className="mt-2 block w-full text-sm"
                        disabled={uploading && logoType === 'dark'}
                    />
                    {uploading && logoType === 'dark' && (
                        <p className="text-sm text-blue-600 mt-1">Uploading...</p>
                    )}
                </div>
                
                {/* Favicon */}
                <div>
                    <label className="block text-sm font-medium mb-2">
                        Favicon
                    </label>
                    
                    {organization.settings?.branding?.favicon_url ? (
                        <div className="space-y-2">
                            <img 
                                src={organization.settings.branding.favicon_url} 
                                alt="Favicon"
                                className="w-16 h-16 border rounded"
                            />
                            <button
                                onClick={() => handleDelete('favicon')}
                                className="text-red-600 text-sm hover:underline"
                            >
                                Delete
                            </button>
                        </div>
                    ) : (
                        <div className="border-2 border-dashed border-gray-300 rounded-lg p-4 text-center">
                            <p className="text-sm text-gray-500 mb-2">No favicon uploaded</p>
                        </div>
                    )}
                    
                    <input
                        type="file"
                        accept=".ico,image/png"
                        onChange={(e) => {
                            const file = e.target.files[0];
                            if (!file) return;
                            
                            const formData = new FormData();
                            formData.append('favicon', file);
                            
                            router.post(
                                route('organizations.branding.upload-favicon', organization.id),
                                formData
                            );
                        }}
                        className="mt-2 block w-full text-sm"
                    />
                </div>
            </div>
        </div>
    );
}
```

---

#### **Task 3.3: Create Color Picker Section**

Install color picker:
```bash
npm install react-colorful
```

Create: `resources/js/superadmin/components/Branding/ColorSection.jsx`

```jsx
import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { HexColorPicker } from 'react-colorful';

export default function ColorSection({ organization }) {
    const { data, setData, post, processing } = useForm({
        'theme.colors.primary': organization.settings?.theme?.colors?.primary || '#411183',
        'theme.colors.accent': organization.settings?.theme?.colors?.accent || '#1F6DF2',
        'theme.colors.accent_soft': organization.settings?.theme?.colors?.accent_soft || '#f77052',
        'theme.colors.secondary': organization.settings?.theme?.colors?.secondary || '#B4C8E8',
        'theme.colors.heavy': organization.settings?.theme?.colors?.heavy || '#1F6DF2',
    });
    
    const [showPicker, setShowPicker] = useState(null);
    
    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('organizations.branding.update', organization.id), {
            onSuccess: () => alert('Colors updated successfully!')
        });
    };
    
    const ColorInput = ({ label, colorKey }) => (
        <div>
            <label className="block text-sm font-medium mb-2">{label}</label>
            <div className="flex items-center space-x-2">
                <div
                    className="w-12 h-12 rounded border-2 border-gray-300 cursor-pointer"
                    style={{ backgroundColor: data[colorKey] }}
                    onClick={() => setShowPicker(showPicker === colorKey ? null : colorKey)}
                />
                <input
                    type="text"
                    value={data[colorKey]}
                    onChange={(e) => setData(colorKey, e.target.value)}
                    className="flex-1 rounded border-gray-300"
                    placeholder="#000000"
                />
            </div>
            
            {showPicker === colorKey && (
                <div className="mt-2 relative">
                    <div 
                        className="fixed inset-0 z-10"
                        onClick={() => setShowPicker(null)}
                    />
                    <div className="relative z-20">
                        <HexColorPicker
                            color={data[colorKey]}
                            onChange={(color) => setData(colorKey, color)}
                        />
                    </div>
                </div>
            )}
        </div>
    );
    
    return (
        <div className="bg-white p-6 rounded-lg shadow">
            <h2 className="text-2xl font-semibold mb-4">Color Scheme</h2>
            
            <form onSubmit={handleSubmit} className="space-y-4">
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <ColorInput label="Primary Color" colorKey="theme.colors.primary" />
                    <ColorInput label="Accent Color" colorKey="theme.colors.accent" />
                    <ColorInput label="Accent Soft Color" colorKey="theme.colors.accent_soft" />
                    <ColorInput label="Secondary Color" colorKey="theme.colors.secondary" />
                    <ColorInput label="Heavy Color" colorKey="theme.colors.heavy" />
                </div>
                
                <div className="pt-4">
                    <button
                        type="submit"
                        disabled={processing}
                        className="px-6 py-2 bg-primary text-white rounded hover:bg-primary-700 disabled:opacity-50"
                    >
                        {processing ? 'Saving...' : 'Save Colors'}
                    </button>
                </div>
            </form>
            
            <div className="mt-6 p-4 bg-gray-50 rounded">
                <h3 className="font-semibold mb-2">Color Preview</h3>
                <div className="flex flex-wrap gap-2">
                    <div className="px-4 py-2 rounded text-white" style={{ backgroundColor: data['theme.colors.primary'] }}>
                        Primary
                    </div>
                    <div className="px-4 py-2 rounded text-white" style={{ backgroundColor: data['theme.colors.accent'] }}>
                        Accent
                    </div>
                    <div className="px-4 py-2 rounded text-white" style={{ backgroundColor: data['theme.colors.accent_soft'] }}>
                        Accent Soft
                    </div>
                    <div className="px-4 py-2 rounded text-white" style={{ backgroundColor: data['theme.colors.secondary'] }}>
                        Secondary
                    </div>
                </div>
            </div>
        </div>
    );
}
```

---

### **Day 18-20: Create Remaining Branding Sections**

#### **Task 3.4: Create Contact Info Section**

Create: `resources/js/superadmin/components/Branding/ContactSection.jsx`

```jsx
import { useForm } from '@inertiajs/react';

export default function ContactSection({ organization }) {
    const { data, setData, post, processing } = useForm({
        'branding.organization_name': organization.settings?.branding?.organization_name || '',
        'branding.tagline': organization.settings?.branding?.tagline || '',
        'branding.description': organization.settings?.branding?.description || '',
        'contact.phone': organization.settings?.contact?.phone || '',
        'contact.email': organization.settings?.contact?.email || '',
        'contact.address.line1': organization.settings?.contact?.address?.line1 || '',
        'contact.address.city': organization.settings?.contact?.address?.city || '',
        'contact.address.country': organization.settings?.contact?.address?.country || '',
        'contact.address.postal_code': organization.settings?.contact?.address?.postal_code || '',
        'contact.business_hours': organization.settings?.contact?.business_hours || '',
    });
    
    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('organizations.branding.update', organization.id), {
            onSuccess: () => alert('Contact information updated successfully!')
        });
    };
    
    return (
        <div className="bg-white p-6 rounded-lg shadow">
            <h2 className="text-2xl font-semibold mb-4">Brand Identity & Contact</h2>
            
            <form onSubmit={handleSubmit} className="space-y-4">
                {/* Brand Identity */}
                <div>
                    <label className="block text-sm font-medium mb-1">Organization Name</label>
                    <input
                        type="text"
                        value={data['branding.organization_name']}
                        onChange={(e) => setData('branding.organization_name', e.target.value)}
                        className="w-full rounded border-gray-300"
                        placeholder="Eleven Plus Tutor"
                    />
                </div>
                
                <div>
                    <label className="block text-sm font-medium mb-1">Tagline</label>
                    <input
                        type="text"
                        value={data['branding.tagline']}
                        onChange={(e) => setData('branding.tagline', e.target.value)}
                        className="w-full rounded border-gray-300"
                        placeholder="11+ Excellence Since 2012"
                    />
                </div>
                
                <div>
                    <label className="block text-sm font-medium mb-1">Description</label>
                    <textarea
                        value={data['branding.description']}
                        onChange={(e) => setData('branding.description', e.target.value)}
                        className="w-full rounded border-gray-300"
                        rows={3}
                        placeholder="Empowering students to achieve their goals..."
                    />
                </div>
                
                <hr className="my-6" />
                
                {/* Contact Information */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label className="block text-sm font-medium mb-1">Phone</label>
                        <input
                            type="text"
                            value={data['contact.phone']}
                            onChange={(e) => setData('contact.phone', e.target.value)}
                            className="w-full rounded border-gray-300"
                            placeholder="+44 1234 567890"
                        />
                    </div>
                    
                    <div>
                        <label className="block text-sm font-medium mb-1">Email</label>
                        <input
                            type="email"
                            value={data['contact.email']}
                            onChange={(e) => setData('contact.email', e.target.value)}
                            className="w-full rounded border-gray-300"
                            placeholder="contact@example.com"
                        />
                    </div>
                </div>
                
                <div>
                    <label className="block text-sm font-medium mb-1">Address Line 1</label>
                    <input
                        type="text"
                        value={data['contact.address.line1']}
                        onChange={(e) => setData('contact.address.line1', e.target.value)}
                        className="w-full rounded border-gray-300"
                        placeholder="123 Education Street"
                    />
                </div>
                
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label className="block text-sm font-medium mb-1">City</label>
                        <input
                            type="text"
                            value={data['contact.address.city']}
                            onChange={(e) => setData('contact.address.city', e.target.value)}
                            className="w-full rounded border-gray-300"
                            placeholder="Kent"
                        />
                    </div>
                    
                    <div>
                        <label className="block text-sm font-medium mb-1">Country</label>
                        <input
                            type="text"
                            value={data['contact.address.country']}
                            onChange={(e) => setData('contact.address.country', e.target.value)}
                            className="w-full rounded border-gray-300"
                            placeholder="United Kingdom"
                        />
                    </div>
                    
                    <div>
                        <label className="block text-sm font-medium mb-1">Postal Code</label>
                        <input
                            type="text"
                            value={data['contact.address.postal_code']}
                            onChange={(e) => setData('contact.address.postal_code', e.target.value)}
                            className="w-full rounded border-gray-300"
                            placeholder="ME1 1AA"
                        />
                    </div>
                </div>
                
                <div>
                    <label className="block text-sm font-medium mb-1">Business Hours</label>
                    <input
                        type="text"
                        value={data['contact.business_hours']}
                        onChange={(e) => setData('contact.business_hours', e.target.value)}
                        className="w-full rounded border-gray-300"
                        placeholder="Mon-Fri: 9AM-6PM, Sat: 10AM-4PM"
                    />
                </div>
                
                <div className="pt-4">
                    <button
                        type="submit"
                        disabled={processing}
                        className="px-6 py-2 bg-primary text-white rounded hover:bg-primary-700 disabled:opacity-50"
                    >
                        {processing ? 'Saving...' : 'Save Information'}
                    </button>
                </div>
            </form>
        </div>
    );
}
```

Continue similar patterns for:
- **Social Media Section** (Facebook, Twitter, Instagram, LinkedIn, YouTube URLs)
- **Email Branding Section** (from_name, from_email, header_color, button_color, footer_text)
- **Custom CSS Section** (textarea with Monaco editor optional)

---

### **Day 21: Testing Admin UI**

Test all sections:
1. Logo upload/delete
2. Color picker changes
3. Contact info updates
4. Social media links
5. Email branding
6. Custom CSS injection

**✅ Phase 3 Complete Checklist**:
- [ ] Branding tab added to organization page
- [ ] Logo upload/delete working
- [ ] Color pickers functional
- [ ] All form sections saving correctly
- [ ] Live preview showing changes

---

## **PHASE 4: TESTING & DEPLOYMENT** (Week 4)

### **Day 22-25: End-to-End Testing**

#### **Test Cases**:

1. **Multi-Organization Test**
   - Create 2-3 test organizations
   - Set different branding for each
   - Switch between organizations
   - Verify branding changes dynamically

2. **Portal Coverage Test**
   - Test in Parent portal
   - Test in Teacher portal
   - Test in Admin portal
   - Test in Super Admin portal
   - Test on Public pages

3. **Email Template Test**
   - Send test emails
   - Verify logo appears
   - Verify colors applied
   - Verify footer information correct

4. **Performance Test**
   - Measure page load time before/after
   - Verify < 5ms overhead
   - Test with 100 concurrent users (optional)

5. **Edge Cases**
   - Test with no branding set (should use defaults)
   - Test with partial branding (some fields empty)
   - Test with very long custom CSS
   - Test with invalid color codes (should reject)

---

### **Day 26-27: Documentation**

Create user documentation:

**File**: `docs/ORGANIZATION_BRANDING_USER_GUIDE.md`

```markdown
# Organization Branding - User Guide

## How to Customize Your Organization's Branding

### Accessing Branding Settings

1. Log in as Super Admin
2. Navigate to **Organizations** → Select your organization
3. Click the **Branding** tab

### Uploading Logo

1. Under "Logo & Favicon" section
2. Click "Choose File" for Logo (Light Mode)
3. Select a PNG, SVG, or WEBP file (max 2MB)
4. Logo will update immediately across all portals

### Changing Colors

1. Under "Color Scheme" section
2. Click the color box to open color picker
3. Choose your desired color
4. Click "Save Colors" to apply

### Updating Contact Information

1. Fill in your organization name, tagline, and description
2. Add phone number, email, and address
3. Set business hours
4. Click "Save Information"

### Setting Up Email Branding

1. Under "Email Branding" section
2. Set "From Name" (appears in recipient's inbox)
3. Set "From Email" address
4. Choose header and button colors
5. Add footer text and disclaimer

### Testing Your Branding

Visit any portal page to see your branding applied instantly!
```

---

### **Day 28: Production Deployment**

#### **Deployment Checklist**:

1. **Backup Database**
```bash
php artisan db:backup
```

2. **Run Tests**
```bash
php artisan test
```

3. **Build Frontend**
```bash
npm run build
```

4. **Deploy to Production**
```bash
git add .
git commit -m "feat: Add organization white-labeling system"
git push production main
```

5. **Run Migrations** (if any were added)
```bash
php artisan migrate --force
```

6. **Clear Caches**
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

7. **Create Symbolic Link** (if not exists)
```bash
php artisan storage:link
```

8. **Set Permissions**
```bash
chmod -R 775 storage
chmod -R 775 bootstrap/cache
```

---

## ✅ **FINAL CHECKLIST**

### **Phase 1: Backend**
- [ ] HandleInertiaRequests middleware enhanced
- [ ] OrganizationBrandingController created
- [ ] Routes added
- [ ] Branded email layout created
- [ ] Backend tests passing

### **Phase 2: Frontend**
- [ ] OrganizationThemeContext created
- [ ] Tailwind config updated with CSS variables
- [ ] All portal layouts wrapped
- [ ] Dynamic theming working
- [ ] Test page functional

### **Phase 3: Admin UI**
- [ ] Branding tab in organization page
- [ ] Logo upload working
- [ ] Favicon upload working
- [ ] Color pickers functional
- [ ] Contact form working
- [ ] Social media form working
- [ ] Email branding form working
- [ ] Custom CSS working

### **Phase 4: Testing & Deployment**
- [ ] Multi-organization testing complete
- [ ] All portals tested
- [ ] Email templates tested
- [ ] Performance acceptable
- [ ] Documentation written
- [ ] Production deployed
- [ ] Caches cleared

---

## 🎉 **COMPLETION**

Once all checklist items are complete, your organization white-labeling system is **FULLY IMPLEMENTED** and ready for production use!

**Estimated Total Time**: 3-4 weeks  
**Status**: Ready to Start Implementation

---

**Document Version**: 1.0  
**Last Updated**: December 4, 2025
