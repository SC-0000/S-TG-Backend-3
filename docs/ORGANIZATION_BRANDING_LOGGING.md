# Organization Branding Logging System

## Overview
Comprehensive logging has been added to the `OrganizationBrandingController` to track all branding changes made to organizations. This provides an audit trail for security, debugging, and compliance purposes.

## Logged Information

### All Operations Log:
- **User ID**: The ID of the user making the change
- **User Name**: The name of the user making the change  
- **Organization ID**: The ID of the organization being modified
- **Organization Name**: The name of the organization being modified
- **IP Address**: The IP address from which the request originated
- **Timestamp**: Automatically logged by Laravel's logging system

## Operations Tracked

### 1. **Branding Settings Update** (`update()`)

**Log Entry Points:**
- **Initiation**: Logged when the update request starts
- **Success**: Logged when the update completes successfully

**Additional Information Logged:**
- `updated_fields`: Array of field names that were updated
- `field_count`: Number of fields updated in the request

**Example Log Output:**
```
[2025-05-12 11:00:00] local.INFO: Organization branding update initiated
{
  "user_id": 42,
  "user_name": "John Admin",
  "organization_id": 1,
  "organization_name": "ABC School",
  "ip_address": "192.168.1.100"
}

[2025-05-12 11:00:01] local.INFO: Organization branding updated successfully
{
  "user_id": 42,
  "user_name": "John Admin",
  "organization_id": 1,
  "organization_name": "ABC School",
  "updated_fields": ["branding.organization_name", "theme.colors.primary", "email.from_name"],
  "field_count": 3
}
```

---

### 2. **Logo Upload** (`uploadLogo()`)

**Log Entry Points:**
- **Initiation**: Logged when logo upload starts
- **Success**: Logged when logo upload completes successfully

**Additional Information Logged:**
- `logo_type`: Type of logo being uploaded ("light" or "dark")
- `logo_path`: Internal storage path of the uploaded logo
- `logo_url`: Public URL of the uploaded logo

**Example Log Output:**
```
[2025-05-12 11:05:00] local.INFO: Organization logo upload initiated
{
  "user_id": 42,
  "user_name": "John Admin",
  "organization_id": 1,
  "organization_name": "ABC School",
  "logo_type": "light",
  "ip_address": "192.168.1.100"
}

[2025-05-12 11:05:02] local.INFO: Organization logo uploaded successfully
{
  "user_id": 42,
  "user_name": "John Admin",
  "organization_id": 1,
  "organization_name": "ABC School",
  "logo_type": "light",
  "logo_path": "organizations/1/abc123.png",
  "logo_url": "/storage/organizations/1/abc123.png"
}
```

---

### 3. **Favicon Upload** (`uploadFavicon()`)

**Log Entry Points:**
- **Initiation**: Logged when favicon upload starts
- **Success**: Logged when favicon upload completes successfully

**Additional Information Logged:**
- `favicon_path`: Internal storage path of the uploaded favicon
- `favicon_url`: Public URL of the uploaded favicon

**Example Log Output:**
```
[2025-05-12 11:10:00] local.INFO: Organization favicon upload initiated
{
  "user_id": 42,
  "user_name": "John Admin",
  "organization_id": 1,
  "organization_name": "ABC School",
  "ip_address": "192.168.1.100"
}

[2025-05-12 11:10:01] local.INFO: Organization favicon uploaded successfully
{
  "user_id": 42,
  "user_name": "John Admin",
  "organization_id": 1,
  "organization_name": "ABC School",
  "favicon_path": "organizations/1/favicon.ico",
  "favicon_url": "/storage/organizations/1/favicon.ico"
}
```

---

### 4. **Asset Deletion** (`deleteAsset()`)

**Log Entry Points:**
- **Initiation**: Logged when asset deletion starts
- **Success**: Logged when asset deletion completes successfully

**Additional Information Logged:**
- `asset_type`: Type of asset being deleted ("logo", "logo_dark", or "favicon")
- `deleted_url`: The URL of the deleted asset

**Example Log Output:**
```
[2025-05-12 11:15:00] local.INFO: Organization asset deletion initiated
{
  "user_id": 42,
  "user_name": "John Admin",
  "organization_id": 1,
  "organization_name": "ABC School",
  "asset_type": "logo",
  "ip_address": "192.168.1.100"
}

[2025-05-12 11:15:01] local.INFO: Organization asset deleted successfully
{
  "user_id": 42,
  "user_name": "John Admin",
  "organization_id": 1,
  "organization_name": "ABC School",
  "asset_type": "logo",
  "deleted_url": "/storage/organizations/1/old-logo.png"
}
```

---

## Viewing Logs

### Default Location
Logs are stored in `storage/logs/laravel.log` by default.

### Via Artisan Command
```bash
# View last 50 lines of log
tail -n 50 storage/logs/laravel.log

# Follow logs in real-time
tail -f storage/logs/laravel.log

# Search for organization branding logs
grep "Organization branding" storage/logs/laravel.log
grep "Organization logo" storage/logs/laravel.log
grep "Organization favicon" storage/logs/laravel.log
grep "Organization asset" storage/logs/laravel.log
```

### Filtering by Organization
```bash
# Find all branding changes for organization ID 1
grep "organization_id.*1" storage/logs/laravel.log | grep "Organization"

# Find all changes by a specific user
grep "user_id.*42" storage/logs/laravel.log | grep "Organization"
```

---

## Use Cases

### 1. **Security Audit**
Track who made changes to organization branding and when:
```bash
grep "Organization branding" storage/logs/laravel.log | grep "2025-05-12"
```

### 2. **Debugging**
If an organization's branding appears incorrect, check the logs to see what was changed:
```bash
grep "organization_id.*1" storage/logs/laravel.log | grep "Organization branding"
```

### 3. **Compliance**
Maintain an audit trail of all branding modifications for compliance purposes.

### 4. **Rollback Information**
If you need to rollback changes, the logs show exactly what fields were modified and when.

---

## Database Storage

All branding data is stored in the `organizations` table in the `settings` JSON column:

```php
// Example of what gets stored
$organization->settings = [
    'branding' => [
        'organization_name' => 'ABC School',
        'logo_url' => '/storage/organizations/1/logo.png',
        'logo_dark_url' => '/storage/organizations/1/logo-dark.png',
        'favicon_url' => '/storage/organizations/1/favicon.ico',
        'tagline' => 'Excellence in Education',
        'description' => 'Providing quality education since 1950',
    ],
    'theme' => [
        'colors' => [
            'primary' => '#3B82F6',
            'accent' => '#10B981',
            // ... more colors
        ],
        'custom_css' => '/* Custom styles */',
    ],
    'contact' => [
        'phone' => '+1234567890',
        'email' => 'info@abc-school.com',
        // ... more contact info
    ],
    'social_media' => [
        'facebook' => 'https://facebook.com/abc-school',
        // ... more social media
    ],
    'email' => [
        'from_name' => 'ABC School',
        'from_email' => 'noreply@abc-school.com',
        // ... more email settings
    ],
];
```

---

## Best Practices

1. **Regular Log Review**: Review logs periodically to detect unauthorized changes
2. **Log Rotation**: Configure log rotation to prevent logs from consuming too much disk space
3. **Backup Logs**: Include logs in your backup strategy for compliance
4. **Monitor for Suspicious Activity**: Set up alerts for unusual patterns (e.g., rapid changes)

---

## Future Enhancements

Potential improvements to the logging system:

1. **Separate Log Channel**: Create a dedicated log channel for branding changes
2. **Database Logging**: Store logs in the database for easier querying
3. **Change History UI**: Build an admin UI to view branding change history
4. **Rollback Feature**: Implement the ability to rollback to previous branding configurations
5. **Email Notifications**: Send email alerts for critical branding changes

---

## Related Documentation

- [Organization White-labeling System](./ORGANIZATION_WHITELABELING_SYSTEM.md)
- [Organization White-labeling Implementation Plan](./ORGANIZATION_WHITELABELING_IMPLEMENTATION_PLAN.md)
- [Organization White-labeling Phase 1 Complete](./ORGANIZATION_WHITELABELING_PHASE1_COMPLETE.md)

---

## Technical Details

**Controller**: `app/Http/Controllers/Admin/OrganizationBrandingController.php`  
**Log Facade**: `Illuminate\Support\Facades\Log`  
**Log Level**: `INFO`  
**Date Implemented**: December 5, 2025
