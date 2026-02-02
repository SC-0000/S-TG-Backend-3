# Organization White-Labeling System - Complete Implementation Plan

**Version**: 1.0  
**Date**: December 4, 2025  
**Status**: Ready for Implementation

---

## 📋 Table of Contents

1. [Overview](#overview)
2. [Data Storage Structure](#data-storage-structure)
3. [Complete Branding Schema](#complete-branding-schema)
4. [Color System Mapping](#color-system-mapping)
5. [File Storage Structure](#file-storage-structure)
6. [Implementation Components](#implementation-components)
7. [Data Flow Architecture](#data-flow-architecture)
8. [API Endpoints](#api-endpoints)
9. [Frontend Integration](#frontend-integration)
10. [Email Template System](#email-template-system)

---

## 1. Overview

This system allows each organization to have its own:
- Brand identity (logo, name, tagline)
- Color scheme (all Tailwind colors customizable)
- Contact information (email, phone, address, social media)
- Email branding (sender name, footer, styling)
- Custom CSS overrides

**Key Principle**: All data stored in **database** (`organizations.settings` JSON field), NOT in frontend config files.

---

## 2. Data Storage Structure

### **Database Table**: `organizations`

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `name` | varchar(255) | Organization name |
| `slug` | varchar(255) | URL-friendly identifier |
| `owner_id` | bigint | User who owns org |
| `settings` | json | **ALL branding data stored here** |
| `status` | enum | active/inactive/suspended |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

### **File Storage**: `storage/app/public/organizations/{org_id}/`

```
storage/app/public/organizations/
├── 1/
│   ├── logo.png
│   ├── logo-dark.png
│   ├── favicon.ico
│   └── favicon-32x32.png
├── 2/
│   ├── logo.svg
│   └── favicon.ico
└── ...
```

---

## 3. Complete Branding Schema

### **JSON Structure in `organizations.settings` field**

```json
{
  "branding": {
    "organization_name": "Eleven Plus Tutor",
    "tagline": "11+ Excellence Since 2012",
    "description": "Empowering students to achieve their 11+ goals through personalized tutoring, expert guidance, and proven teaching methods.",
    "logo_url": "/storage/organizations/1/logo.png",
    "logo_dark_url": "/storage/organizations/1/logo-dark.png",
    "favicon_url": "/storage/organizations/1/favicon.ico",
    "favicon_32_url": "/storage/organizations/1/favicon-32x32.png"
  },
  
  "theme": {
    "colors": {
      "primary": "#411183",
      "primary_50": "#F8F6FF",
      "primary_100": "#F0EBFF",
      "primary_200": "#E1D6FF",
      "primary_300": "#C9B8FF",
      "primary_400": "#A688FF",
      "primary_500": "#8B5CF6",
      "primary_600": "#7C3AED",
      "primary_700": "#6D28D9",
      "primary_800": "#5B21B6",
      "primary_900": "#411183",
      "primary_950": "#2E0F5C",
      
      "accent": "#1F6DF2",
      "accent_50": "#EFF6FF",
      "accent_100": "#DBEAFE",
      "accent_200": "#BFDBFE",
      "accent_300": "#93C5FD",
      "accent_400": "#60A5FA",
      "accent_500": "#3B82F6",
      "accent_600": "#2563EB",
      "accent_700": "#1D4ED8",
      "accent_800": "#1E40AF",
      "accent_900": "#1F6DF2",
      "accent_950": "#172554",
      
      "accent_soft": "#f77052",
      "accent_soft_50": "#FFF7F5",
      "accent_soft_100": "#FFEDE8",
      "accent_soft_200": "#FFD9D0",
      "accent_soft_300": "#FFBAA8",
      "accent_soft_400": "#FF9580",
      "accent_soft_500": "#FFA996",
      "accent_soft_600": "#FF6B47",
      "accent_soft_700": "#F04A23",
      "accent_soft_800": "#C73E1D",
      "accent_soft_900": "#A3341A",
      
      "secondary": "#B4C8E8",
      "heavy": "#1F6DF2",
      
      "gray_900": "#111827",
      "gray_600": "#4B5563",
      "gray_100": "#F5F7FC"
    },
    "custom_css": ".custom-button { border-radius: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }"
  },
  
  "contact": {
    "phone": "+44 1234 567890",
    "email": "ept@pa.team",
    "address": {
      "line1": "123 Education Street",
      "city": "Kent",
      "country": "United Kingdom",
      "postal_code": "ME1 1AA"
    },
    "business_hours": "Mon-Fri: 9AM-6PM, Sat: 10AM-4PM"
  },
  
  "social_media": {
    "facebook": "https://facebook.com/elevenplustutor",
    "twitter": "https://twitter.com/elevenplustutor",
    "instagram": "https://instagram.com/elevenplustutor",
    "linkedin": "https://linkedin.com/company/elevenplustutor",
    "youtube": "https://youtube.com/@elevenplustutor"
  },
  
  "email": {
    "from_name": "Eleven Plus Tutor",
    "from_email": "noreply@elevenplustutor.com",
    "reply_to_email": "ept@pa.team",
    "header_color": "#411183",
    "button_color": "#1F6DF2",
    "footer_text": "© 2025 Eleven Plus Tutor. All rights reserved.",
    "footer_disclaimer": "This email was sent to you because you registered with Eleven Plus Tutor. If you no longer wish to receive these emails, please unsubscribe."
  },
  
  "seo": {
    "meta_title_suffix": "| Eleven Plus Tutor",
    "meta_description": "Expert 11+ tutoring services to help your child succeed.",
    "og_image_url": "/storage/organizations/1/og-image.png"
  }
}
```

### **Example Data for "Eleven Plus Tutor"**

Based on your provided information:

```json
{
  "branding": {
    "organization_name": "Eleven Plus Tutor",
    "tagline": "11+ Excellence Since 2012",
    "description": "Empowering students to achieve their 11+ goals through personalized tutoring, expert guidance, and proven teaching methods."
  },
  "contact": {
    "phone": "+44 1234 567890",
    "email": "ept@pa.team",
    "address": {
      "city": "Kent",
      "country": "United Kingdom"
    },
    "business_hours": "Mon-Fri: 9AM-6PM, Sat: 10AM-4PM"
  },
  "email": {
    "footer_text": "© 2025 Eleven Plus Tutor. All rights reserved."
  }
}
```

---

## 4. Color System Mapping

### **Tailwind Config Colors → Database Mapping**

Your current `tailwind.config.js` colors will be mapped as follows:

| Tailwind Class | CSS Variable | DB Field | Default Value |
|---------------|--------------|----------|---------------|
| `bg-primary` | `--color-primary` | `theme.colors.primary` | `#411183` |
| `text-primary-50` | `--color-primary-50` | `theme.colors.primary_50` | `#F8F6FF` |
| `bg-accent` | `--color-accent` | `theme.colors.accent` | `#1F6DF2` |
| `bg-accent-soft` | `--color-accent-soft` | `theme.colors.accent_soft` | `#f77052` |
| `text-secondary` | `--color-secondary` | `theme.colors.secondary` | `#B4C8E8` |
| `bg-heavy` | `--color-heavy` | `theme.colors.heavy` | `#1F6DF2` |

### **Complete Color Palette**

All shades (50-950) for each color:

```json
{
  "theme": {
    "colors": {
      "primary": "#411183",
      "primary_50": "#F8F6FF",
      "primary_100": "#F0EBFF",
      "primary_200": "#E1D6FF",
      "primary_300": "#C9B8FF",
      "primary_400": "#A688FF",
      "primary_500": "#8B5CF6",
      "primary_600": "#7C3AED",
      "primary_700": "#6D28D9",
      "primary_800": "#5B21B6",
      "primary_900": "#411183",
      "primary_950": "#2E0F5C"
    }
  }
}
```

*Repeat for `accent`, `accent_soft`*

---

## 5. File Storage Structure

### **Upload Paths**

```php
// Logo
storage/app/public/organizations/{org_id}/logo.png
// Accessible via: /storage/organizations/{org_id}/logo.png

// Dark mode logo
storage/app/public/organizations/{org_id}/logo-dark.png

// Favicon
storage/app/public/organizations/{org_id}/favicon.ico

// OG Image (social media)
storage/app/public/organizations/{org_id}/og-image.png
```

### **Supported File Types**

| Asset Type | Formats | Max Size | Recommended Size |
|-----------|---------|----------|------------------|
| Logo | PNG, SVG, WEBP | 2MB | 400x100px |
| Logo (Dark) | PNG, SVG, WEBP | 2MB | 400x100px |
| Favicon | ICO, PNG | 100KB | 32x32px, 16x16px |
| OG Image | PNG, JPG | 1MB | 1200x630px |

---

## 6. Implementation Components

### **Backend Components**

#### **6.1 Middleware Enhancement**

**File**: `app/Http/Middleware/HandleInertiaRequests.php`

```php
public function share(Request $request): array
{
    $user = $request->user();
    $orgBranding = null;
    
    if ($user && $user->current_organization_id) {
        $org = Organization::find($user->current_organization_id);
        
        $orgBranding = [
            // Brand
            'name' => $org->getSetting('branding.organization_name'),
            'tagline' => $org->getSetting('branding.tagline'),
            'description' => $org->getSetting('branding.description'),
            'logo_url' => $org->getSetting('branding.logo_url'),
            'logo_dark_url' => $org->getSetting('branding.logo_dark_url'),
            'favicon_url' => $org->getSetting('branding.favicon_url'),
            
            // Colors (all shades)
            'colors' => $org->getSetting('theme.colors', [
                'primary' => '#411183',
                'accent' => '#1F6DF2',
                'accent_soft' => '#f77052',
                // ... all color shades
            ]),
            
            // Contact
            'contact' => [
                'phone' => $org->getSetting('contact.phone'),
                'email' => $org->getSetting('contact.email'),
                'address' => $org->getSetting('contact.address'),
                'business_hours' => $org->getSetting('contact.business_hours'),
            ],
            
            // Social
            'social' => $org->getSetting('social_media', []),
            
            // Custom CSS
            'custom_css' => $org->getSetting('theme.custom_css'),
        ];
    }
    
    return array_merge(parent::share($request), [
        'auth' => ['user' => $user],
        'organizationBranding' => $orgBranding,
    ]);
}
```

#### **6.2 Branding Controller**

**File**: `app/Http/Controllers/Admin/OrganizationBrandingController.php`

```php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;

class OrganizationBrandingController extends Controller
{
    public function update(Request $request, Organization $organization)
    {
        $validated = $request->validate([
            // Brand
            'branding.organization_name' => 'nullable|string|max:255',
            'branding.tagline' => 'nullable|string|max:500',
            'branding.description' => 'nullable|string|max:1000',
            
            // Theme colors
            'theme.colors.primary' => 'nullable|string|size:7',
            'theme.colors.accent' => 'nullable|string|size:7',
            'theme.colors.accent_soft' => 'nullable|string|size:7',
            // ... validate all color fields
            
            // Contact
            'contact.phone' => 'nullable|string|max:50',
            'contact.email' => 'nullable|email|max:255',
            'contact.address.line1' => 'nullable|string|max:255',
            'contact.address.city' => 'nullable|string|max:100',
            'contact.address.country' => 'nullable|string|max:100',
            'contact.business_hours' => 'nullable|string|max:255',
            
            // Social
            'social_media.facebook' => 'nullable|url',
            'social_media.twitter' => 'nullable|url',
            'social_media.instagram' => 'nullable|url',
            'social_media.linkedin' => 'nullable|url',
            
            // Email
            'email.from_name' => 'nullable|string|max:255',
            'email.from_email' => 'nullable|email',
            'email.footer_text' => 'nullable|string|max:500',
            
            // Custom CSS
            'theme.custom_css' => 'nullable|string|max:10000',
        ]);
        
        foreach ($validated as $key => $value) {
            $organization->setSetting($key, $value);
        }
        
        return back()->with('success', 'Branding updated successfully!');
    }
    
    public function uploadLogo(Request $request, Organization $organization)
    {
        $request->validate([
            'logo' => 'required|image|mimes:png,svg,webp|max:2048',
            'type' => 'required|in:light,dark',
        ]);
        
        $file = $request->file('logo');
        $path = $file->store("organizations/{$organization->id}", 'public');
        
        $field = $request->type === 'dark' 
            ? 'branding.logo_dark_url' 
            : 'branding.logo_url';
            
        $organization->setSetting($field, "/storage/{$path}");
        
        return back()->with('success', 'Logo uploaded successfully!');
    }
    
    public function uploadFavicon(Request $request, Organization $organization)
    {
        $request->validate([
            'favicon' => 'required|mimes:ico,png|max:100',
        ]);
        
        $file = $request->file('favicon');
        $path = $file->storeAs(
            "organizations/{$organization->id}", 
            'favicon.' . $file->extension(), 
            'public'
        );
        
        $organization->setSetting('branding.favicon_url', "/storage/{$path}");
        
        return back()->with('success', 'Favicon uploaded successfully!');
    }
}
```

---

## 7. Data Flow Architecture

### **Request Lifecycle**

```
1. User logs in
   ↓
2. User->current_organization_id is set (e.g., 3)
   ↓
3. HandleInertiaRequests middleware runs on EVERY request
   ↓
4. Middleware loads: Organization::find(3)
   ↓
5. Extracts branding from settings JSON
   ↓
6. Formats as organizationBranding object
   ↓
7. Inertia shares with ALL pages
   ↓
8. Frontend receives via usePage().props.organizationBranding
   ↓
9. OrganizationThemeProvider injects CSS variables
   ↓
10. Page renders with organization's colors/logo
```

### **Data Transformation**

```php
// Database (JSON)
{
  "theme": {
    "colors": {
      "primary": "#411183"
    }
  }
}

↓ Middleware extracts ↓

// PHP Array
$orgBranding = [
  'colors' => [
    'primary' => '#411183'
  ]
]

↓ Inertia serializes ↓

// JavaScript Object
organizationBranding: {
  colors: {
    primary: "#411183"
  }
}

↓ Theme Provider applies ↓

// DOM CSS Variables
<html style="--color-primary: #411183">
```

---

## 8. API Endpoints

### **Routes to Add**

**File**: `routes/superadmin.php`

```php
Route::prefix('organizations/{organization}')->group(function () {
    // Branding management
    Route::post('/branding', [OrganizationBrandingController::class, 'update'])
        ->name('organizations.branding.update');
    
    // File uploads
    Route::post('/branding/logo', [OrganizationBrandingController::class, 'uploadLogo'])
        ->name('organizations.branding.upload-logo');
    
    Route::post('/branding/favicon', [OrganizationBrandingController::class, 'uploadFavicon'])
        ->name('organizations.branding.upload-favicon');
    
    // Preview
    Route::get('/branding/preview', [OrganizationBrandingController::class, 'preview'])
        ->name('organizations.branding.preview');
});
```

---

## 9. Frontend Integration

### **9.1 Theme Context Provider**

**File**: `resources/js/contexts/OrganizationThemeContext.jsx`

```jsx
import { createContext, useContext, useEffect } from 'react';
import { usePage } from '@inertiajs/react';

const OrganizationThemeContext = createContext({});

export function OrganizationThemeProvider({ children }) {
    const { organizationBranding } = usePage().props;
    
    useEffect(() => {
        if (!organizationBranding) return;
        
        // Apply color variables
        if (organizationBranding.colors) {
            Object.entries(organizationBranding.colors).forEach(([key, value]) => {
                // Convert primary_50 → --color-primary-50
                const cssVar = `--color-${key.replace(/_/g, '-')}`;
                document.documentElement.style.setProperty(cssVar, value);
            });
        }
        
        // Inject custom CSS
        if (organizationBranding.custom_css) {
            const style = document.createElement('style');
            style.id = 'org-custom-css';
            style.textContent = organizationBranding.custom_css;
            document.head.appendChild(style);
            
            return () => {
                document.getElementById('org-custom-css')?.remove();
            };
        }
        
        // Update favicon
        if (organizationBranding.favicon_url) {
            const link = document.querySelector("link[rel~='icon']") || 
                        document.createElement('link');
            link.rel = 'icon';
            link.href = organizationBranding.favicon_url;
            document.head.appendChild(link);
        }
        
        // Update page title
        if (organizationBranding.name) {
            const baseTitle = document.title.split(' | ')[0];
            document.title = `${baseTitle} | ${organizationBranding.name}`;
        }
    }, [organizationBranding]);
    
    return (
        <OrganizationThemeContext.Provider value={organizationBranding || {}}>
            {children}
        </OrganizationThemeContext.Provider>
    );
}

export const useOrganizationBranding = () => useContext(OrganizationThemeContext);
```

### **9.2 Update Tailwind Config**

**File**: `tailwind.config.js`

```js
colors: {
    primary: {
        DEFAULT: 'var(--color-primary, #411183)',
        50: 'var(--color-primary-50, #F8F6FF)',
        100: 'var(--color-primary-100, #F0EBFF)',
        // ... all shades use CSS variables with fallbacks
    },
    accent: {
        DEFAULT: 'var(--color-accent, #1F6DF2)',
        // ...
    },
    // ...
}
```

### **9.3 Wrap Layouts**

```jsx
// In ParentPortalLayout.jsx, AdminLayout.jsx, etc.
import { OrganizationThemeProvider } from '@/contexts/OrganizationThemeContext';

export default function Layout({ children }) {
    return (
        <OrganizationThemeProvider>
            {/* existing layout code */}
        </OrganizationThemeProvider>
    );
}
```

---

## 10. Email Template System

### **10.1 Branded Email Component**

**File**: `resources/views/emails/layouts/branded.blade.php`

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 20px auto;
            background: white;
            border-radius: 8px;
            overflow: hidden;
        }
        .header {
            background: {{ $organization->getSetting('email.header_color', '#411183') }};
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header img {
            max-width: 200px;
            height: auto;
            margin-bottom: 15px;
        }
        .content {
            padding: 40px 30px;
        }
        .button {
            background: {{ $organization->getSetting('email.button_color', '#1F6DF2') }};
            color: white;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 6px;
            display: inline-block;
            margin: 20px 0;
        }
        .footer {
            background: #f5f5f5;
            padding: 20px;
            text-align: center;
            font-size: 12px;
            color: #666;
        }
        .footer-links {
            margin: 15px 0;
        }
        .footer-links a {
            color: #666;
            margin: 0 10px;
            text-decoration: none;
        }
        .contact-info {
            margin: 15px 0;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            @if($organization->getSetting('branding.logo_url'))
                <img src="{{ asset($organization->getSetting('branding.logo_url')) }}" 
                     alt="{{ $organization->getSetting('branding.organization_name') }}">
            @endif
            <h1>{{ $organization->getSetting('branding.organization_name') }}</h1>
            @if($organization->getSetting('branding.tagline'))
                <p>{{ $organization->getSetting('branding.tagline') }}</p>
            @endif
        </div>
        
        <div class="content">
            {{ $slot }}
        </div>
        
        <div class="footer">
            <div class="contact-info">
                @if($organization->getSetting('contact.email'))
                    <p>📧 {{ $organization->getSetting('contact.email') }}</p>
                @endif
                @if($organization->getSetting('contact.phone'))
                    <p>📞 {{ $organization->getSetting('contact.phone') }}</p>
                @endif
                @if($organization->getSetting('contact.address.city'))
                    <p>📍 {{ $organization->getSetting('contact.address.city') }}, 
                       {{ $organization->getSetting('contact.address.country') }}</p>
                @endif
                @if($organization->getSetting('contact.business_hours'))
                    <p>🕐 {{ $organization->getSetting('contact.business_hours') }}</p>
                @endif
            </div>
            
            <div class="footer-links">
                @if($organization->getSetting('social_media.facebook'))
                    <a href="{{ $organization->getSetting('social_media.facebook') }}">Facebook</a>
                @endif
                @if($organization->getSetting('social_media.twitter'))
                    <a href="{{ $organization->getSetting('social_media.twitter') }}">Twitter</a>
                @endif
                @if($organization->getSetting('social_media.instagram'))
                    <a href="{{ $organization->getSetting('social_media.instagram') }}">Instagram</a>
                @endif
            </div>
            
            <p>{{ $organization->getSetting('email.footer_text', '© 2025. All rights reserved.') }}</p>
            
            @if($organization->getSetting('email.footer_disclaimer'))
                <p style="font-size: 11px; margin-top: 15px;">
                    {{ $organization->getSetting('email.footer_disclaimer') }}
                </p>
            @endif
        </div>
    </div>
</body>
</html>
```

### **10.2 Use in Mailable**

```php
// Example: app/Mail/WelcomeEmail.php
public function build()
{
    $org = $this->user->currentOrganization;
    
    return $this
        ->from(
            $org->getSetting('email.from_email', config('mail.from.address')),
            $org->getSetting('email.from_name', config('mail.from.name'))
        )
        ->replyTo($org->getSetting('email.reply_to_email'))
        ->view('emails.welcome')
        ->layout('emails.layouts.branded', ['organization' => $org]);
}
```

---

## 11. Implementation Checklist

### **Phase 1: Backend** (Week 1)
- [ ] Create `OrganizationBrandingController`
- [ ] Add routes in `routes/superadmin.php`
- [ ] Enhance `HandleInertiaRequests` middleware
- [ ] Create branded email layout
- [ ] Test file upload system

### **Phase 2: Frontend** (Week 2)
- [ ] Create `OrganizationThemeContext.jsx`
- [ ] Update `tailwind.config.js` to use CSS variables
- [ ] Wrap all portal layouts with theme provider
- [ ] Test color injection

### **Phase 3: Admin UI** (Week 3)
- [ ] Create branding management page
- [ ] Add color pickers
- [ ] Implement logo/favicon upload
- [ ] Add live preview
- [ ] Test on all portals

### **Phase 4: Testing & Polish** (Week 4)
- [ ] Test with multiple organizations
- [ ] Verify email templates
- [ ] Performance testing
- [ ] Documentation
- [ ] Deploy to staging

---

## 12. Performance Considerations

### **Query Optimization**
```php
// Eager load organization with user
$user = $request->user()->load('currentOrganization');
```

### **Caching Strategy** (Optional)
```php
$orgBranding = Cache::remember(
    "org.{$user->current_organization_id}.branding",
    3600,
    fn() => Organization::find($user->current_organization_id)->getAllBranding()
);
```

### **Asset Optimization**
- Use CDN for uploaded images
- Generate multiple sizes for logos (thumbnail, medium, full)
- Compress images on upload
- Use WebP format where supported

---

## 13. Security Considerations

1. **File Upload Validation**
   - Whitelist MIME types
   - Max file size enforcement
   - Virus scanning (optional)
   - Sanitize file names

2. **Custom CSS Sanitization**
   - Strip dangerous rules (`@import`, `url()` with external domains)
   - Validate color codes
   - Limit CSS length

3. **Access Control**
   - Only org owners/admins can edit branding
   - Super admins can edit any org
   - Audit log for branding changes

---

## 14. Example Database Entry

```sql
INSERT INTO organizations (name, slug, owner_id, settings, status, created_at, updated_at)
VALUES (
    'Eleven Plus Tutor',
    'eleven-plus-tutor',
    1,
    '{
        "branding": {
            "organization_name": "Eleven Plus Tutor",
            "tagline": "11+ Excellence Since 2012",
            "description": "Empowering students to achieve their 11+ goals through personalized tutoring, expert guidance, and proven teaching methods.",
            "logo_url": "/storage/organizations/1/logo.png",
            "favicon_url": "/storage/organizations/1/favicon.ico"
        },
        "theme": {
            "colors": {
                "primary": "#411183",
                "accent": "#1F6DF2",
                "accent_soft": "#f77052"
            }
        },
        "contact": {
            "phone": "+44 1234 567890",
            "email": "ept@pa.team",
            "address": {
                "city": "Kent",
                "country": "United Kingdom"
            },
            "business_hours": "Mon-Fri: 9AM-6PM, Sat: 10AM-4PM"
        },
        "email": {
            "from_name": "Eleven Plus Tutor",
            "from_email": "noreply@elevenplustutor.com",
            "footer_text": "© 2025 Eleven Plus Tutor. All rights reserved."
        }
    }',
    'active',
    NOW(),
    NOW()
);
```

---

## 15. Next Steps

1. **Review this plan** with your team
2. **Approve the structure** of the JSON schema
3. **Toggle to Act Mode** to begin implementation
4. Start with **Phase 1: Backend** components
5. Progress through phases sequentially

---

**Document Version**: 1.0  
**Last Updated**: December 4, 2025  
**Status**: ✅ Ready for Implementation
