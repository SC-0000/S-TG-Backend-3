# Subscription Admin UI - Complete Implementation

## Overview

The subscription system now has a **complete admin UI** for managing year-group based subscriptions. Admins can create and edit subscription plans with fine-grained content access control through the admin portal.

---

## Architecture Summary

### Database Layer
- `subscriptions` table with `content_filters` JSON column
- Supports year-group filtering via JSON structure:
  ```json
  {
    "type": "year_group",
    "year_groups": ["Year 5", "Year 6"]
  }
  ```

### Backend (Laravel)
- `SubscriptionController` handles CRUD operations
- Validates `content_filters` field (nullable|array)
- `YearGroupSubscriptionService` grants access based on filters

### Frontend (React + Inertia.js)
- Admin subscription management UI
- Visual year-group selector with checkboxes
- Real-time JSON preview for debugging

---

## Admin Workflow

### Creating a Year-Group Subscription

**Navigation:** Admin Portal → Subscriptions → Create New Plan

#### Step 1: Basic Information
- **Plan Name:** "Year 5 Access"
- **Slug:** Auto-generated (`year-5-access`)

#### Step 2: Plan Features
Add feature flags (boolean):
- `ai_analysis: true`
- `year_group_courses: true`
- `premium_support: false`

#### Step 3: Content Access Rules (NEW!)
1. **Access Type:** Select "Year Group Based"
2. **Allowed Year Groups:** Check boxes:
   - ✅ Year 5
   - ✅ Year 6
   - ⬜ Year 7
   - ... etc

The UI provides instant feedback:
- ⚠️ Warning if no year groups selected
- ✓ Success message showing which year groups will get access
- JSON preview for debugging

#### Step 4: Submit
Click "Create Plan" → Subscription saved with:
```json
{
  "name": "Year 5 Access",
  "slug": "year-5-access",
  "features": {
    "ai_analysis": true,
    "year_group_courses": true
  },
  "content_filters": {
    "type": "year_group",
    "year_groups": ["Year 5", "Year 6"]
  }
}
```

---

## Component Architecture

### 1. ContentFilterBuilder Component
**Location:** `resources/js/admin/components/ContentFilterBuilder.jsx`

**Features:**
- Dropdown to select filter type (None or Year Group)
- Grid of year group checkboxes (Year 5-11)
- Real-time validation and feedback
- JSON preview (collapsible)
- Auto-generates correct JSON structure

**Props:**
```jsx
<ContentFilterBuilder
  value={data.content_filters}  // Current filters object
  onChange={(val) => setData('content_filters', val)}  // Update callback
/>
```

**Example Output:**
```json
{
  "type": "year_group",
  "year_groups": ["Year 5", "Year 6", "Year 7"]
}
```

### 2. Updated Subscription Form
**Location:** `resources/js/admin/Pages/Subscriptions/Create.jsx`

**Changes:**
- Import `ContentFilterBuilder`
- Add `content_filters` to form data
- New section: "Content Access Rules"
- Handles both create and edit modes

### 3. Updated Backend Controller
**Location:** `app/Http/Controllers/Admin/SubscriptionController.php`

**Changes:**
- Validate `content_filters` field in `store()` and `update()`
- Allow nullable array for flexible access control

---

## How Access Granting Works

### User Gets Subscription
When a user subscribes (via billing webhook or admin):

```php
// app/Services/YearGroupSubscriptionService.php
$service = new YearGroupSubscriptionService();
$service->grantAccessForSubscription($user, $subscription);
```

### Access Logic
1. Check if `content_filters` exists and has `type: 'year_group'`
2. If yes, only grant access to content matching user's year groups
3. Query all courses/assessments where:
   - `year_group` IN `content_filters.year_groups`
4. Create `Access` records for each matching item

### Example Query
```php
$courses = Course::whereIn('year_group', $subscription->content_filters['year_groups'])->get();

foreach ($courses as $course) {
    Access::create([
        'user_id' => $user->id,
        'accessible_type' => Course::class,
        'accessible_id' => $course->id,
        'source' => 'subscription',
        'source_id' => $subscription->id,
    ]);
}
```

---

## Testing the System

### Admin UI Testing

1. **Create Full Access Subscription:**
   - Access Type: "None (Full Access)"
   - Result: Users get access to ALL content

2. **Create Year-Group Subscription:**
   - Access Type: "Year Group Based"
   - Select: Year 5, Year 6
   - Result: Users get access ONLY to Year 5 & 6 content

3. **Edit Existing Subscription:**
   - Should pre-populate with existing filters
   - Can add/remove year groups
   - Changes apply to new subscriptions (existing users keep their access)

### Backend Testing

**Test Access Granting:**
```php
// Create a user with year_group
$user = User::factory()->create(['year_group' => 'Year 5']);

// Create a subscription
$subscription = Subscription::create([
    'name' => 'Year 5 Access',
    'slug' => 'year-5-access',
    'features' => ['ai_analysis' => true],
    'content_filters' => [
        'type' => 'year_group',
        'year_groups' => ['Year 5', 'Year 6'],
    ],
]);

// Grant access
$service = new YearGroupSubscriptionService();
$service->grantAccessForSubscription($user, $subscription);

// Verify access
$user->refresh();
$courseCount = $user->accesses()->where('accessible_type', Course::class)->count();
// Should equal number of Year 5 + Year 6 courses
```

---

## Database Seeding

To quickly populate test subscriptions, use the seeder:

```bash
php artisan db:seed --class=SubscriptionSeeder
```

**Creates:**
- "Year 5 Premium" (Year 5 only)
- "Year 6 Premium" (Year 6 only)
- "Year 5-6 Bundle" (Year 5 + Year 6)
- "Full Access" (All year groups)

---

## API Endpoints

### List Subscriptions
```
GET /@admin/subscriptions
```

### Create Subscription Form
```
GET /@admin/subscriptions/create
```

### Store Subscription
```
POST /@admin/subscriptions
Body:
{
  "name": "Year 5 Access",
  "slug": "year-5-access",
  "features": { "ai_analysis": true },
  "content_filters": {
    "type": "year_group",
    "year_groups": ["Year 5"]
  }
}
```

### Edit Subscription Form
```
GET /@admin/subscriptions/{id}/edit
```

### Update Subscription
```
PUT /@admin/subscriptions/{id}
Body: (same as store)
```

### Delete Subscription
```
DELETE /@admin/subscriptions/{id}
```

---

## Future Enhancements

### 1. Multiple Filter Types
Add more filter types beyond year groups:
- Subject-based filtering
- Difficulty level filtering
- Content type filtering (courses only, assessments only)

**Implementation:**
```jsx
<select value={filterType}>
  <option value="">None (Full Access)</option>
  <option value="year_group">Year Group Based</option>
  <option value="subject">Subject Based</option>
  <option value="difficulty">Difficulty Based</option>
</select>
```

### 2. Preview Before Save
Show which specific courses/assessments will be accessible:

```jsx
<div className="preview">
  <h3>Content Preview</h3>
  <p>Users will get access to:</p>
  <ul>
    <li>15 Year 5 Courses</li>
    <li>23 Year 5 Assessments</li>
    <li>12 Year 6 Courses</li>
    <li>19 Year 6 Assessments</li>
  </ul>
  <p className="text-bold">Total: 69 items</p>
</div>
```

### 3. Bulk Edit Subscriptions
Allow changing multiple subscriptions at once:
- Select multiple plans
- Apply same filter to all
- Update in batch

### 4. Subscription Templates
Pre-configured templates for common use cases:
- "Single Year Group" (pick one year)
- "Key Stage Bundle" (Year 5-6 or Year 7-9)
- "Full Secondary" (Year 7-11)

---

## Troubleshooting

### Issue: Subscription created but no access granted
**Cause:** Missing webhook or manual subscription assignment

**Fix:**
```php
// Manually grant access
$service = new YearGroupSubscriptionService();
$service->grantAccessForSubscription($user, $subscription);
```

### Issue: Wrong year groups displayed
**Cause:** Year groups in database don't match constants

**Fix:** Ensure year groups in content match `ContentFilterBuilder` constants:
```php
const YEAR_GROUPS = ['Year 5', 'Year 6', 'Year 7', ...];
```

### Issue: User has wrong year_group
**Cause:** User's `year_group` field is null or incorrect

**Fix:**
```php
$user->update(['year_group' => 'Year 5']);
```

---

## Complete File Reference

### Backend Files
- `app/Http/Controllers/Admin/SubscriptionController.php` - CRUD operations
- `app/Services/YearGroupSubscriptionService.php` - Access granting logic
- `app/Models/Subscription.php` - Model with relationships
- `database/migrations/2025_11_18_011026_add_year_group_support_for_subscriptions.php` - Schema

### Frontend Files
- `resources/js/admin/Pages/Subscriptions/Create.jsx` - Form (Create/Edit)
- `resources/js/admin/Pages/Subscriptions/Index.jsx` - List view
- `resources/js/admin/components/ContentFilterBuilder.jsx` - Filter UI
- `resources/js/admin/components/FeatureBuilder.jsx` - Feature flags UI

### Routes
- `routes/admin.php` - Admin subscription routes

### Documentation
- `docs/YEAR_GROUP_SUBSCRIPTION_PHASE1_COMPLETE.md` - Database & models
- `docs/YEAR_GROUP_SUBSCRIPTION_PHASE2_COMPLETE.md` - Access service
- `docs/YEAR_GROUP_SUBSCRIPTION_PHASE3_COMPLETE.md` - User management
- `docs/SUBSCRIPTION_SYSTEM_WITH_THIRD_PARTY_BILLING_REPORT.md` - Overall system
- `docs/SUBSCRIPTION_ADMIN_UI_COMPLETE.md` - This document

---

## Summary

The subscription system is now **feature-complete** with:

✅ **Database schema** supporting flexible content filters  
✅ **Backend validation** for content_filters field  
✅ **Access granting service** that respects year-group filters  
✅ **Admin UI** with visual year-group selector  
✅ **Real-time feedback** and JSON preview  
✅ **Create and edit** functionality  

Admins can now create year-group subscriptions through the UI without touching code or database directly. The system automatically grants access to the correct content based on the configured filters.

**Next Step:** Integrate with third-party billing platform (e.g., Stripe, Paddle) to handle payments and webhook processing.
