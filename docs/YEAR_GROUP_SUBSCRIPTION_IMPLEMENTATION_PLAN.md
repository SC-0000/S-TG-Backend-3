# Year-Group Subscription System Implementation Plan

**Status:** Planning Phase  
**Created:** 2025-11-18  
**Last Updated:** 2025-11-18

---

## Overview

This document outlines the implementation plan for a **year-group based subscription system** that grants users access to courses, assessments, and content based on their child's year group (Year 5, Year 6, etc.).

### Key Features

- ✅ Users purchase subscriptions via third-party billing platform (billings.systems)
- ✅ Subscriptions grant access to **all courses** for a specific year group
- ✅ Includes **all content within courses**: content lessons, live sessions, assessments
- ✅ Includes **standalone assessments** for that year group
- ❌ **Excludes** standalone live sessions (must purchase separately)

---

## Architecture Overview

```
┌─────────────────────────────────────────┐
│   Billing Platform (billings.systems)  │
│   - Create subscription plans           │
│   - Process payments via Stripe         │
│   - Manage customer subscriptions       │
└─────────────────┬───────────────────────┘
                  │ API
                  ▼
┌─────────────────────────────────────────┐
│   Laravel Application                   │
│   - Sync active subscriptions           │
│   - Grant year-group based access       │
│   - Manage local access records         │
└─────────────────────────────────────────┘
```

---

## Database Schema Changes

### 1. Add `year_group` Field to Content Tables

**Migration:** `add_year_group_support_for_subscriptions.php`

**Tables to modify:**
- `assessments`
- `courses`
- `live_sessions`
- `new_lessons` (content lessons)
- `live_lesson_sessions`

**Purpose:** Enable filtering content by year group (e.g., "Year 5", "Year 6")

### 2. Add `content_filters` to Subscriptions Table

**Table:** `subscriptions`

**Purpose:** Store subscription filtering rules as JSON

**Example:**
```json
{
  "type": "year_group",
  "year_groups": ["Year 5"],
  "includes": ["courses", "course_content", "standalone_assessments"],
  "excludes": ["standalone_live_sessions"]
}
```

---

## Implementation Phases

### Phase 1: Database Schema Setup

**Files:**
- `database/migrations/YYYY_MM_DD_add_year_group_support_for_subscriptions.php`

**Tasks:**
1. Create migration file
2. Add `year_group` column to all content tables
3. Add `content_filters` JSON column to `subscriptions` table
4. Add indexes on `year_group` columns
5. Run migration

**Commands:**
```bash
php artisan make:migration add_year_group_support_for_subscriptions
php artisan migrate
```

---

### Phase 2: Local Subscription Configuration

**Files:**
- `database/seeders/YearGroupSubscriptionSeeder.php`

**Subscription Plans:**

| Plan Name | Slug | Year Groups | Price | Features |
|-----------|------|-------------|-------|----------|
| Year 5 Access | `year_5_access` | Year 5 | £39.99/mo | Courses, Assessments, AI |
| Year 6 Access | `year_6_access` | Year 6 | £39.99/mo | Courses, Assessments, AI |
| Year 5-6 Access | `year_5_6_access` | Year 5, Year 6 | £59.99/mo | Both + Priority Support |

**Critical:** Plan names MUST match exactly with billing platform plan names.

**Commands:**
```bash
php artisan make:seeder YearGroupSubscriptionSeeder
php artisan db:seed --class=YearGroupSubscriptionSeeder
```

---

### Phase 3: Access Granting Service

**Files:**
- `app/Services/YearGroupSubscriptionService.php`

**Methods:**

1. **`grantAccess(User $user, Subscription $subscription, Child $child)`**
   - Validates child's year group matches subscription
   - Collects all course IDs for that year group
   - Uses existing `Course` helper methods:
     - `getAllLessonIds()` → ContentLesson IDs
     - `getAllLiveSessionIds()` → LiveLessonSession IDs
     - `getAllAssessmentIds()` → Assessment IDs
     - `getAllModuleIds()` → Module IDs
   - Finds standalone assessments
   - Creates Access record matching existing pattern

2. **`revokeAccess(Child $child)`**
   - Deletes Access records with type `year_group_subscription`

3. **`hasAccessTo(Child $child, string $contentType, int $contentId)`**
   - Checks if child has access to specific content via subscription

**Access Record Structure:**

Matches existing `CourseAccessService` pattern:

```php
[
    'child_id' => $child->id,
    'access_type' => 'year_group_subscription',
    
    // Top-level fields
    'course_ids' => [1, 2, 3],
    'lesson_id' => null,
    'lesson_ids' => [],
    'content_lesson_id' => 10,
    'assessment_id' => 7,
    'assessment_ids' => [7, 8, 9],
    'module_ids' => [4, 5, 6],
    
    'transaction_id' => null,
    'invoice_id' => null,
    'access' => true,
    'purchase_date' => now(),
    'payment_status' => 'subscription',
    
    // Metadata
    'metadata' => [
        'granted_via' => 'year_group_subscription',
        'subscription_name' => 'Year 5 Access',
        'year_group' => 'Year 5',
        'content_lesson_ids' => [10, 11, 12],
        'live_lesson_session_ids' => [20, 21, 22],
        'granted_at' => '2025-11-18T00:00:00+05:00',
    ],
]
```

**Commands:**
```bash
php artisan make:service YearGroupSubscriptionService
```

---

### Phase 4: Portal Integration

**Files:**
- `app/Http/Controllers/PortalController.php`

**Modifications to `PortalController::index()`:**

**Location:** After existing subscription sync logic

**Add:**
1. Check if subscription has `year_group_courses` feature
2. If yes, call `YearGroupSubscriptionService::grantAccess()` for all user's children
3. Add cancellation handling to revoke access when subscription ends

**Pseudo-code:**
```php
// After syncing subscriptions from billing API
foreach ($activeSubs as $remoteSub) {
    $subscription = Subscription::where('name', $remoteSub['name'])->first();
    
    if ($subscription && ($subscription->features['year_group_courses'] ?? false)) {
        $yearGroupService = app(YearGroupSubscriptionService::class);
        foreach ($user->children as $child) {
            $yearGroupService->grantAccess($user, $subscription, $child);
        }
    }
}

// Revoke access for canceled subscriptions
foreach ($userLocalSubs as $localSub) {
    if (!in_array($localSub->id, $activeSubscriptionIds)) {
        if ($localSub->features['year_group_courses'] ?? false) {
            $yearGroupService = app(YearGroupSubscriptionService::class);
            foreach ($user->children as $child) {
                $yearGroupService->revokeAccess($child);
            }
        }
    }
}
```

---

### Phase 5: User Model Helper Methods

**Files:**
- `app/Models/User.php`

**Add Methods:**

```php
/**
 * Check if user has a specific feature via subscription
 */
public function hasFeature(string $feature): bool
{
    return $this->subscriptions()
        ->wherePivot('status', 'active')
        ->get()
        ->some(fn($sub) => $sub->features[$feature] ?? false);
}

/**
 * Get user's active year-group subscriptions
 */
public function activeYearGroupSubscriptions()
{
    return $this->subscriptions()
        ->wherePivot('status', 'active')
        ->get()
        ->filter(fn($sub) => $sub->features['year_group_courses'] ?? false);
}
```

---

### Phase 6: Billing Platform Setup

**Platform:** https://billings.systems

**Steps:**

1. Log into admin dashboard
2. Create subscription plans with **exact** names:
   - "Year 5 Access"
   - "Year 6 Access"
   - "Year 5-6 Access"
3. Configure pricing:
   - Year 5: £39.99/month (or $39.99)
   - Year 6: £39.99/month
   - Year 5-6: £59.99/month
4. Set billing interval: Monthly
5. Enable trial period: 14 days (optional)
6. Set up Stripe payment integration
7. Add features list:
   ```json
   ["year_group_courses", "year_group_assessments", "ai_analysis", "enhanced_reports"]
   ```

---

### Phase 7: Frontend Updates

**Files:**
- `resources/js/parent/Pages/Courses/Browse.jsx`
- `resources/js/public/Pages/Billing/SubscriptionPlans.jsx`
- `resources/js/parent/Pages/Main/Home.jsx`

**Changes:**

1. **Course Browse Page:**
   - Show "✓ Included in Your Subscription" badge for accessible courses
   - Hide purchase button if subscription grants access

2. **Subscription Plans Page:**
   - Display available plans using `SubscriptionWidget`
   - Handle success callback to redirect to portal

3. **Home/Portal Page:**
   - Show active subscription badge
   - Display year groups covered

---

## Complete User Flow

### Purchase Flow

```
1. User visits Subscription Plans page
   ↓
2. User sees available plans (Year 5, Year 6, etc.)
   ↓
3. User selects "Year 5 Access" plan
   ↓
4. SubscriptionWidget displays payment form (Stripe)
   ↓
5. User enters payment details and subscribes
   ↓
6. Billing platform:
   - Charges payment method
   - Creates subscription record
   - Returns success
   ↓
7. User redirected to portal
   ↓
8. PortalController::index() runs:
   - Fetches active subscriptions from billing API
   - Finds "Year 5 Access" subscription
   - Matches to local Subscription model
   - Calls YearGroupSubscriptionService::grantAccess()
   ↓
9. YearGroupSubscriptionService:
   - Checks child's year group (Year 5)
   - Finds all Year 5 courses
   - Collects all content IDs using Course helpers
   - Finds standalone Year 5 assessments
   - Creates Access record
   ↓
10. Child can now access:
    ✅ All Year 5 courses
    ✅ All content lessons in those courses
    ✅ All live sessions in those courses
    ✅ All assessments in those courses
    ✅ Standalone Year 5 assessments
    ❌ Standalone live sessions (must purchase)
```

### Cancellation Flow

```
1. User cancels subscription in billing dashboard
   ↓
2. User logs into portal (or subscription expires)
   ↓
3. PortalController::index() runs:
   - Fetches active subscriptions from billing API
   - "Year 5 Access" no longer in active list
   ↓
4. System detects canceled subscription:
   - Updates user_subscriptions pivot status to 'canceled'
   - Calls YearGroupSubscriptionService::revokeAccess()
   ↓
5. YearGroupSubscriptionService:
   - Deletes Access record with type 'year_group_subscription'
   ↓
6. Child loses access to:
   ❌ Year 5 courses
   ❌ Content within those courses
   ❌ Standalone Year 5 assessments
```

---

## Testing Checklist

### Test Case 1: Purchase Year 5 Subscription

- [ ] Create test user with Year 5 child
- [ ] Purchase "Year 5 Access" subscription via widget
- [ ] Log into portal
- [ ] Verify `access` table has record with:
  - `access_type` = 'year_group_subscription'
  - `course_ids` contains all Year 5 courses
  - `metadata->content_lesson_ids` has Year 5 content lessons
  - `metadata->live_lesson_session_ids` has Year 5 live sessions
  - `assessment_ids` has Year 5 assessments
- [ ] Child can access Year 5 courses
- [ ] Child can access standalone Year 5 assessments
- [ ] Standalone live sessions show "Purchase Required"

### Test Case 2: Cancel Subscription

- [ ] Cancel subscription in billing dashboard
- [ ] User logs into portal
- [ ] Verify Access record removed from database
- [ ] Year 5 courses show "Purchase Required"
- [ ] Standalone assessments locked

### Test Case 3: Multiple Children

- [ ] User has Year 5 child + Year 6 child
- [ ] Purchase "Year 5 Access"
- [ ] Year 5 child gets access
- [ ] Year 6 child does NOT get access
- [ ] Purchase "Year 6 Access"
- [ ] Both children now have their respective access

### Test Case 4: Year 5-6 Combined Subscription

- [ ] Purchase "Year 5-6 Access"
- [ ] Both Year 5 and Year 6 children get access
- [ ] Year 5 child can access Year 5 content
- [ ] Year 6 child can access Year 6 content

---

## Implementation Commands Summary

```bash
# 1. Create migration
php artisan make:migration add_year_group_support_for_subscriptions

# 2. Run migration
php artisan migrate

# 3. Create subscription seeder
php artisan make:seeder YearGroupSubscriptionSeeder

# 4. Run seeder
php artisan db:seed --class=YearGroupSubscriptionSeeder

# 5. Create service
php artisan make:service YearGroupSubscriptionService

# 6. Test (after implementing all files)
# - Create test user
# - Purchase subscription via widget
# - Check access table
# - Verify course access
```

---

## File Checklist

- [ ] `database/migrations/YYYY_MM_DD_add_year_group_support_for_subscriptions.php`
- [ ] `database/seeders/YearGroupSubscriptionSeeder.php`
- [ ] `app/Services/YearGroupSubscriptionService.php`
- [ ] `app/Http/Controllers/PortalController.php` (modified)
- [ ] `app/Models/User.php` (modified)
- [ ] `resources/js/public/Pages/Billing/SubscriptionPlans.jsx`
- [ ] `resources/js/parent/Pages/Courses/Browse.jsx` (modified)

---

## Dependencies

### Existing Systems (Already Implemented)

- ✅ `CourseAccessService` - Pattern to match
- ✅ `Course` model with helper methods:
  - `getAllLessonIds()`
  - `getAllLiveSessionIds()`
  - `getAllAssessmentIds()`
  - `getAllModuleIds()`
- ✅ `Access` model and table
- ✅ `Subscription` model and table
- ✅ `user_subscriptions` pivot table
- ✅ `PortalController` subscription sync logic
- ✅ `SubscriptionWidget` component

### External Systems

- ⚠️ **billings.systems** - Third-party billing platform
  - Admin access required to create plans
  - API key required for widget
  - Stripe integration required for payments

---

## Critical Success Factors

1. **Name Matching:** Subscription plan names in billings.systems MUST exactly match local subscription names
2. **Year Group Data:** All courses must have `year_group` field populated
3. **Course Helpers:** Course model must have working `getAllLessonIds()` etc. methods
4. **Access Pattern:** Must follow existing `CourseAccessService` structure for consistency
5. **Sync Frequency:** User must log into portal for subscription sync to occur

---

## Known Limitations

1. **Manual Sync:** Access is granted when user logs into portal (not real-time)
2. **Standalone Live Sessions:** Not included in subscriptions (separate purchase)
3. **Year Group Mismatch:** If child's year group doesn't match subscription, no access granted
4. **External Dependency:** Relies on billings.systems API availability

---

## Next Steps

1. Review and approve this plan
2. Create migration file
3. Create seeder file
4. Create service file
5. Update PortalController
6. Update User model
7. Set up billing plans
8. Test complete flow
9. Deploy to staging
10. Test with real payments
11. Deploy to production

---

## Questions to Address

- [ ] Should we auto-backfill `year_group` for existing courses?
- [ ] What happens if billing API is down during login?
- [ ] Should we cache subscription status to reduce API calls?
- [ ] Do we need webhook support for instant subscription updates?
- [ ] Should we send email notifications when subscription grants access?

---

**End of Implementation Plan**
