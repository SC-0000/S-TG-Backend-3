# Subscription Child Assignment System - Complete Documentation

## Overview

This document provides a complete technical overview of the subscription system with third-party billing integration, including the new child assignment feature for year-group subscriptions.

---

## System Architecture

### 1. Third-Party Billing Integration

**Provider**: The system integrates with an external billing API for subscription management.

**Key Components**:
- `app/Services/BillingService.php` - Main billing API wrapper
- `app/Http/Controllers/BillingWebhookController.php` - Handles billing webhooks
- `User.billing_customer_id` - Links users to remote billing accounts

**Flow**:
1. User purchases subscription via external billing platform
2. Webhook notifies system of subscription status changes
3. System syncs subscription status on each portal page load (PortalController)
4. Local subscription records are created/updated in `subscription_user` pivot table

---

## 2. Database Schema

### Subscriptions Table
```sql
CREATE TABLE subscriptions (
    id BIGINT PRIMARY KEY,
    name VARCHAR(255),           -- Subscription plan name
    description TEXT,
    features JSON,               -- Array of feature flags
    content_filters JSON,        -- Year-group filters
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### Subscription-User Pivot Table
```sql
CREATE TABLE subscription_user (
    id BIGINT PRIMARY KEY,
    user_id BIGINT,              -- Parent/user
    subscription_id BIGINT,
    child_id BIGINT NULL,        -- NEW: Assigned child (for year-group subs)
    status ENUM('active', 'canceled', 'expired'),
    starts_at TIMESTAMP,
    ends_at TIMESTAMP,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (subscription_id) REFERENCES subscriptions(id),
    FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE SET NULL
);
```

### Content Filters Schema
```json
{
    "type": "year_group",
    "year_groups": [6, 7, 8, 9]
}
```

---

## 3. Subscription Types

### Type 1: Feature-Based Subscriptions
**Example**: AI Analysis, Premium Support

**Characteristics**:
- Grant access to platform features
- Not tied to specific content
- No child assignment needed
- Features stored in `subscriptions.features` JSON field

**Usage**:
```php
// Check if user has feature
if ($user->subscriptions()->where('status', 'active')
    ->whereJsonContains('features', 'ai_analysis')->exists()) {
    // Grant AI access
}
```

### Type 2: Year-Group Content Subscriptions
**Example**: Year 7 Math, Year 9 Science

**Characteristics**:
- Grant access to content filtered by year group
- **Requires child assignment** (NEW)
- Content filters stored in `subscriptions.content_filters` JSON
- Uses `YearGroupSubscriptionService` for access management

**Content Filters Example**:
```json
{
    "type": "year_group",
    "year_groups": [7, 8]
}
```

**Flow**:
1. Parent purchases Year 7 subscription
2. Subscription synced to local system (status: active, child_id: NULL)
3. **Parent assigns subscription to specific child**
4. System grants access to Year 7 content for that child only

---

## 4. Subscription Purchase Flow

### Step 1: External Purchase
1. User visits billing platform (external)
2. Selects subscription plan
3. Completes payment
4. Billing platform creates subscription with status "active"

### Step 2: Webhook Notification
```php
// app/Http/Controllers/BillingWebhookController.php
public function handle(Request $request)
{
    $event = $request->input('event'); // 'subscription.created', 'subscription.canceled', etc.
    $data = $request->input('data');
    
    // Process subscription status changes
    // Update local subscription_user records
}
```

### Step 3: Local Sync (On Portal Load)
```php
// app/Http/Controllers/PortalController.php
public function index(Request $request)
{
    $user = Auth::user();
    $billingService = app(\App\Services\BillingService::class);
    
    // Fetch active subscriptions from remote billing API
    $subsResp = $billingService->getSubscriptions();
    $activeSubs = collect($subsResp['data']['data'])
        ->where('customer_id', $user->billing_customer_id)
        ->whereIn('status', ['active', 'paid']);
    
    foreach ($activeSubs as $remoteSub) {
        $subscriptionName = $remoteSub['name'];
        $subscription = \App\Models\Subscription::where('name', $subscriptionName)->first();
        
        if ($subscription) {
            // Check if user already has this subscription
            $existing = $user->subscriptions()
                ->where('subscriptions.id', $subscription->id)
                ->withPivot(['status', 'child_id'])
                ->first();
            
            if ($existing) {
                // Update existing (revive if canceled)
                $user->subscriptions()->updateExistingPivot($subscription->id, [
                    'status' => 'active',
                    'starts_at' => now(),
                    'ends_at' => now()->addDays(30),
                ]);
            } else {
                // Create new subscription (child_id = NULL initially)
                $user->subscriptions()->attach($subscription->id, [
                    'status' => 'active',
                    'starts_at' => now(),
                    'ends_at' => now()->addDays(30),
                ]);
            }
            
            // IMPORTANT: Year-group subscriptions grant access ONLY if child_id is assigned
            $contentFilters = $subscription->content_filters ?? [];
            if (($contentFilters['type'] ?? null) === 'year_group') {
                $assignedChildId = $existing ? $existing->pivot->child_id : null;
                
                if ($assignedChildId) {
                    // Grant access to assigned child
                    $yearGroupService = app(\App\Services\YearGroupSubscriptionService::class);
                    $child = $user->children()->find($assignedChildId);
                    $yearGroupService->grantAccess($user, $subscription, $child);
                } else {
                    // Subscription pending assignment - no access granted yet
                    Log::info('Subscription pending child assignment', [
                        'subscription_id' => $subscription->id,
                    ]);
                }
            }
        }
    }
}
```

---

## 5. Child Assignment System (NEW)

### Frontend: Unassigned Subscription Detection

**Middleware**: `ShareUnassignedSubscriptions`
```php
// app/Http/Middleware/ShareUnassignedSubscriptions.php
public function handle(Request $request, Closure $next): Response
{
    if (Auth::check() && Auth::user()->role === 'parent') {
        $user = Auth::user();
        
        // Find unassigned active subscriptions
        $unassigned = $user->subscriptions()
            ->wherePivot('status', 'active')
            ->wherePivotNull('child_id')  // No child assigned yet
            ->get()
            ->map(fn($sub) => [
                'id' => $sub->id,
                'name' => $sub->name,
                'description' => $sub->description,
                'content_filters' => $sub->content_filters,
            ]);
        
        // Share with all Inertia pages
        Inertia::share([
            'unassignedSubscriptions' => $unassigned->isEmpty() ? null : $unassigned,
            'allChildren' => $user->children->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->child_name,
                'year_group' => $c->year_group,
            ]),
        ]);
    }
    
    return $next($request);
}
```

**Registration**: `bootstrap/app.php`
```php
$middleware->web(append: [
    \App\Http\Middleware\HandleInertiaRequests::class,
    \App\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
    \App\Http\Middleware\ShareUnassignedSubscriptions::class,  // NEW
]);
```

### Backend: Assignment Controller

**Controller**: `app/Http/Controllers/Parent/SubscriptionController.php`
```php
public function assignChild(Request $request)
{
    $data = $request->validate([
        'subscription_id' => 'required|exists:subscriptions,id',
        'child_id' => 'required|exists:children,id',
    ]);
    
    $user = Auth::user();
    
    // Verify child belongs to user
    if (!$user->children()->where('id', $data['child_id'])->exists()) {
        return back()->with('error', 'Invalid child selection.');
    }
    
    // Update pivot: assign child to subscription
    $user->subscriptions()->updateExistingPivot(
        $data['subscription_id'],
        ['child_id' => $data['child_id']]
    );
    
    // Grant access immediately
    $subscription = \App\Models\Subscription::find($data['subscription_id']);
    $child = \App\Models\Child::find($data['child_id']);
    
    $yearGroupService = app(\App\Services\YearGroupSubscriptionService::class);
    $yearGroupService->grantAccess($user, $subscription, $child);
    
    return redirect()->back()->with('success', 'Subscription assigned successfully!');
}
```

**Route**: `routes/parent.php`
```php
Route::post('/subscriptions/assign', [App\Http\Controllers\Parent\SubscriptionController::class, 'assignChild'])
    ->name('parent.subscriptions.assign');
```

---

## 6. Access Granting Service

**Service**: `app/Services/YearGroupSubscriptionService.php`

### Grant Access Method
```php
public function grantAccess(User $user, Subscription $subscription, Child $child): void
{
    $contentFilters = $subscription->content_filters ?? [];
    
    if (($contentFilters['type'] ?? null) !== 'year_group') {
        return; // Not a year-group subscription
    }
    
    $allowedYearGroups = $contentFilters['year_groups'] ?? [];
    $childYearGroup = $this->extractYearGroup($child->year_group);
    
    // Verify child's year group matches subscription
    if (!in_array($childYearGroup, $allowedYearGroups)) {
        Log::warning('Child year group mismatch', [
            'child_year_group' => $childYearGroup,
            'subscription_year_groups' => $allowedYearGroups,
        ]);
        return;
    }
    
    // Grant access to matching content
    $this->grantContentLessonAccess($user, $child, $allowedYearGroups);
    $this->grantAssessmentAccess($user, $child, $allowedYearGroups);
    $this->grantCourseAccess($user, $child, $allowedYearGroups);
    $this->grantLiveSessionAccess($user, $child, $allowedYearGroups);
}
```

### Content Access Methods
```php
protected function grantContentLessonAccess(User $user, Child $child, array $yearGroups): void
{
    $lessons = ContentLesson::whereIn('year_group', $this->mapYearGroupsToGrades($yearGroups))
        ->get();
    
    foreach ($lessons as $lesson) {
        Access::updateOrCreate(
            [
                'user_id' => $user->id,
                'child_id' => $child->id,
                'content_lesson_id' => $lesson->id,
            ],
            [
                'access' => true,
                'payment_status' => 'paid',
                'granted_at' => now(),
            ]
        );
    }
}

// Similar methods for assessments, courses, live sessions...
```

### Revoke Access Method
```php
public function revokeAccess(Child $child): void
{
    Access::where('child_id', $child->id)
        ->where('payment_status', 'paid')
        ->update([
            'access' => false,
            'payment_status' => 'revoked',
        ]);
}
```

---

## 7. Year Group Naming Conventions

The system supports two naming formats:

### Database Format (Content)
- `"Grade 6"`, `"Grade 7"`, `"Grade 8"`, etc.
- Used in: `content_lessons.year_group`, `assessments.year_group`, etc.

### Display Format (Children/Filters)
- `"Year 6"`, `"Year 7"`, `"Year 8"`, etc.
- Used in: `children.year_group`, subscription filters

### Mapping Logic
```php
protected function mapYearGroupsToGrades(array $yearGroups): array
{
    return array_map(fn($y) => "Grade $y", $yearGroups);
}

protected function extractYearGroup(string $yearGroupString): int
{
    // "Year 7" -> 7
    // "Grade 7" -> 7
    return (int) preg_replace('/[^0-9]/', '', $yearGroupString);
}
```

**See**: `docs/YEAR_GROUP_NAMING_CONVENTION_FIX.md` for details.

---

## 8. Admin Management

### Admin Subscription Interface

**Route**: `/admin/subscriptions`

**Features**:
- Create/Edit/Delete subscription plans
- Configure content filters (year groups)
- Set feature flags
- View subscription analytics

**Controller**: `app/Http/Controllers/Admin/SubscriptionController.php`

**UI Component**: `resources/js/admin/Pages/Subscriptions/Index.jsx`

### Content Filter Builder

**Component**: `resources/js/admin/components/ContentFilterBuilder.jsx`

```jsx
const ContentFilterBuilder = ({ value, onChange }) => {
    const [filterType, setFilterType] = useState(value?.type || 'all_content');
    const [selectedYearGroups, setSelectedYearGroups] = useState(value?.year_groups || []);
    
    const handleYearGroupToggle = (yearGroup) => {
        const updated = selectedYearGroups.includes(yearGroup)
            ? selectedYearGroups.filter(y => y !== yearGroup)
            : [...selectedYearGroups, yearGroup];
        
        setSelectedYearGroups(updated);
        onChange({
            type: 'year_group',
            year_groups: updated,
        });
    };
    
    return (
        <div>
            <select value={filterType} onChange={(e) => setFilterType(e.target.value)}>
                <option value="all_content">All Content</option>
                <option value="year_group">Specific Year Groups</option>
            </select>
            
            {filterType === 'year_group' && (
                <div className="year-group-selector">
                    {[5, 6, 7, 8, 9, 10, 11].map(year => (
                        <label key={year}>
                            <input
                                type="checkbox"
                                checked={selectedYearGroups.includes(year)}
                                onChange={() => handleYearGroupToggle(year)}
                            />
                            Grade {year}
                        </label>
                    ))}
                </div>
            )}
        </div>
    );
};
```

---

## 9. User Subscription Management

### Admin View of User Subscriptions

**Route**: `/admin/users/{user}/subscriptions`

**Controller**: `app/Http/Controllers/Admin/UserSubscriptionController.php`

```php
public function index(User $user)
{
    $subscriptions = $user->subscriptions()
        ->withPivot(['status', 'child_id', 'starts_at', 'ends_at'])
        ->with('subscription')
        ->get()
        ->map(function ($subscription) use ($user) {
            $assignedChild = $subscription->pivot->child_id
                ? $user->children()->find($subscription->pivot->child_id)
                : null;
            
            return [
                'subscription_id' => $subscription->id,
                'name' => $subscription->name,
                'status' => $subscription->pivot->status,
                'assigned_child' => $assignedChild ? [
                    'id' => $assignedChild->id,
                    'name' => $assignedChild->child_name,
                    'year_group' => $assignedChild->year_group,
                ] : null,
                'starts_at' => $subscription->pivot->starts_at,
                'ends_at' => $subscription->pivot->ends_at,
            ];
        });
    
    return Inertia::render('Admin/Users/Subscriptions', [
        'user' => $user,
        'subscriptions' => $subscriptions,
    ]);
}
```

---

## 10. Frontend Implementation (TODO)

### Modal Component Structure
```jsx
// resources/js/parent/components/SubscriptionAssignmentModal.jsx

const SubscriptionAssignmentModal = ({ subscription, children, onClose }) => {
    const [selectedChildId, setSelectedChildId] = useState(null);
    const { post } = useForm();
    
    const handleAssign = () => {
        post(route('parent.subscriptions.assign'), {
            subscription_id: subscription.id,
            child_id: selectedChildId,
        }, {
            onSuccess: () => {
                toast.success('Subscription assigned successfully!');
                onClose();
            },
        });
    };
    
    return (
        <Modal show={true} onClose={onClose}>
            <h2>Assign Subscription: {subscription.name}</h2>
            <p>{subscription.description}</p>
            
            <div className="child-selector">
                <h3>Select Child:</h3>
                {children.map(child => (
                    <label key={child.id}>
                        <input
                            type="radio"
                            name="child"
                            value={child.id}
                            onChange={() => setSelectedChildId(child.id)}
                        />
                        {child.name} - {child.year_group}
                    </label>
                ))}
            </div>
            
            <button onClick={handleAssign} disabled={!selectedChildId}>
                Assign Subscription
            </button>
        </Modal>
    );
};
```

### Layout Integration
```jsx
// resources/js/parent/Layouts/ParentPortalLayout.jsx

const ParentPortalLayout = ({ children }) => {
    const { unassignedSubscriptions, allChildren } = usePage().props;
    const [showModal, setShowModal] = useState(false);
    
    useEffect(() => {
        if (unassignedSubscriptions && unassignedSubscriptions.length > 0) {
            setShowModal(true);
        }
    }, [unassignedSubscriptions]);
    
    return (
        <div>
            {/* Main layout content */}
            {children}
            
            {/* Assignment modal */}
            {showModal && unassignedSubscriptions && (
                <SubscriptionAssignmentModal
                    subscription={unassignedSubscriptions[0]}
                    children={allChildren}
                    onClose={() => setShowModal(false)}
                />
            )}
        </div>
    );
};
```

---

## 11. Complete Flow Example

### Scenario: Parent Purchases Year 7 Math Subscription

**Step 1**: External Purchase
- Parent visits billing platform
- Selects "Year 7 Mathematics" subscription (£29.99/month)
- Completes Stripe payment
- Billing platform creates subscription with `customer_id` and `status: 'active'`

**Step 2**: Webhook (Optional - Real-time Sync)
```json
POST /api/billing/webhook
{
    "event": "subscription.created",
    "data": {
        "customer_id": "cus_123abc",
        "subscription_id": "sub_789xyz",
        "plan_name": "Year 7 Mathematics",
        "status": "active"
    }
}
```

**Step 3**: Portal Load Sync
- Parent logs into portal → `PortalController@index`
- System fetches active subscriptions from billing API
- Finds "Year 7 Mathematics" subscription
- Creates local record:
  ```
  subscription_user: {
      user_id: 42,
      subscription_id: 5,
      child_id: NULL,  ← Not assigned yet
      status: 'active',
      starts_at: '2025-11-18 22:00:00',
      ends_at: '2025-12-18 22:00:00'
  }
  ```

**Step 4**: Assignment Prompt
- `ShareUnassignedSubscriptions` middleware detects unassigned subscription
- Modal appears: "Assign Year 7 Mathematics to a child"
- Parent selects child "Emma" (Year 7)
- Submits assignment

**Step 5**: Access Granting
```php
POST /subscriptions/assign
{
    subscription_id: 5,
    child_id: 12
}

// Controller updates pivot
subscription_user: {
    child_id: 12  ← NOW ASSIGNED
}

// YearGroupSubscriptionService grants access
Access records created for Emma:
- All Grade 7 content lessons
- All Grade 7 assessments
- All Grade 7 courses
- All Grade 7 live sessions
```

**Step 6**: Content Access
- Emma can now access Year 7 Math content
- Portal displays Year 7 lessons in her dashboard
- Progress tracking begins

---

## 12. Key Files Reference

### Backend
- `app/Models/Subscription.php` - Subscription model
- `app/Services/BillingService.php` - Billing API integration
- `app/Services/YearGroupSubscriptionService.php` - Access granting
- `app/Http/Controllers/PortalController.php` - Subscription sync logic
- `app/Http/Controllers/Parent/SubscriptionController.php` - Assignment controller
- `app/Http/Controllers/BillingWebhookController.php` - Webhook handler
- `app/Http/Middleware/ShareUnassignedSubscriptions.php` - Frontend data sharing
- `database/migrations/*_subscription_*.php` - Database schema

### Frontend (Existing)
- `resources/js/admin/Pages/Subscriptions/` - Admin management
- `resources/js/admin/components/ContentFilterBuilder.jsx` - Filter configuration
- `resources/js/public/Pages/Billing/SubscriptionPlansPage.jsx` - Public plans page
- `resources/js/public/components/SubscriptionWidget.jsx` - Subscription widget

### Frontend (TODO - Phase 3)
- `resources/js/parent/components/SubscriptionAssignmentModal.jsx` - Assignment UI
- `resources/js/parent/Layouts/ParentPortalLayout.jsx` - Modal integration

### Documentation
- `docs/SUBSCRIPTION_SYSTEM_WITH_THIRD_PARTY_BILLING_REPORT.md` - Original system overview
- `docs/YEAR_GROUP_SUBSCRIPTION_PHASE1_COMPLETE.md` - Database changes
- `docs/YEAR_GROUP_SUBSCRIPTION_PHASE2_COMPLETE.md` - Backend content filtering
- `docs/YEAR_GROUP_SUBSCRIPTION_PHASE3_COMPLETE.md` - Access granting service
- `docs/YEAR_GROUP_NAMING_CONVENTION_FIX.md` - Naming standards
- `docs/SUBSCRIPTION_CHILD_ASSIGNMENT_COMPLETE.md` - This document

---

## 13. Testing Checklist

### Manual Testing Steps

1. **Purchase Subscription**
   - [ ] Purchase via external billing platform
   - [ ] Verify webhook received (if implemented)
   - [ ] Log into portal and verify sync

2. **Child Assignment**
   - [ ] Verify unassigned modal appears
   - [ ] Select child and assign
   - [ ] Verify pivot table updated with child_id
   - [ ] Verify access records created

3. **Content Access**
   - [ ] Verify child can see year-appropriate content
   - [ ] Verify child cannot see content from other years
   - [ ] Test lesson player access
   - [ ] Test assessment access

4. **Subscription Cancellation**
   - [ ] Cancel via billing platform
   - [ ] Verify status updated to 'canceled'
   - [ ] Verify access revoked

### Database Verification Queries

```sql
-- Check subscription sync
SELECT * FROM subscription_user WHERE user_id = ?;

-- Check child assignment
SELECT su.*, c.child_name, c.year_group
FROM subscription_user su
LEFT JOIN children c ON su.child_id = c.id
WHERE su.user_id = ?;

-- Check granted access
SELECT a.*, cl.title, cl.year_group
FROM access a
INNER JOIN content_lessons cl ON a.content_lesson_id = cl.id
WHERE a.child_id = ? AND a.access = 1;
```

---

## 14. Future Enhancements

1. **Multi-Child Subscriptions**: Allow one subscription to be shared across multiple children
2. **Subscription Recommendations**: Suggest appropriate subscriptions based on child's year group
3. **Automatic Assignment**: Auto-assign if parent has only one child
4. **Transfer Subscriptions**: Allow reassignment between children
5. **Grace Period**: Maintain access for X days after cancellation
6. **Prorated Billing**: Handle mid-cycle changes
7. **Analytics Dashboard**: Track subscription usage and engagement

---

**Document Version**: 1.0  
**Last Updated**: 2025-11-18  
**Author**: System Documentation
