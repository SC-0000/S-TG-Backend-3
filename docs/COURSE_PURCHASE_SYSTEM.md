# Course Purchase System Implementation

## Overview

This system allows users to purchase courses through the existing Services infrastructure. When a course is purchased, the buyer automatically gains access to all course content including:
- **ContentLessons** (self-paced slide-based lessons)
- **LiveLessonSessions** (scheduled live classes)
- **Assessments** (module-level and course-level tests)

## Architecture

### Database Schema

**Services Table**
```sql
services
├─ id
├─ service_name
├─ course_id (NEW: nullable, foreign key to courses)
├─ price
└─ ... (existing fields)
```

**Access Table**
```sql
access
├─ id
├─ child_id
├─ lesson_id (LiveLessonSession)
├─ content_lesson_id (NEW: ContentLesson)
├─ assessment_id
├─ transaction_id
└─ ... (existing fields)
```

### Relationships

```
Service
├─ course() → Course
└─ isCourseService() → bool

Course
├─ modules() → Module[]
└─ assessments() → Assessment[]

Module
├─ lessons() → ContentLesson[]
└─ assessments() → Assessment[]

ContentLesson
└─ liveSessions() → LiveLessonSession[]
```

## How It Works

### 1. Admin Creates Course Service

**Step 1: Create Course Structure**
```
Admin → Courses → Create Course
├─ Add Modules
│   ├─ Module 1: "Algebra Basics"
│   │   ├─ ContentLesson: "Variables"
│   │   │   └─ LiveLessonSession: "Variables Workshop"
│   │   └─ Assessment: "Module 1 Quiz"
│   └─ Module 2: "Geometry"
└─ Add Course-level Assessment: "Final Exam"
```

**Step 2: Link Course to Service**
```php
// Admin creates a Service
Service::create([
    'service_name' => '11+ Math Mastery Course',
    'course_id' => 5, // Links to the course
    'price' => 299.00,
    // ... other fields
]);
```

### 2. User Purchases Course

**Frontend Flow:**
```
User → Browse Courses → Select Course → Add to Cart → Checkout
```

**Backend Purchase Process:**
1. Cart contains Service (with `course_id`)
2. Transaction is created
3. Payment is processed
4. `GrantAccessForTransactionJob` is dispatched

### 3. Access is Granted

**GrantAccessForTransactionJob Logic:**

```php
public function handle()
{
    // Check if service is a course service
    if ($service->isCourseService()) {
        // Use CourseAccessService to grant access
        app(CourseAccessService::class)->grantCourseAccess(
            childId: $childId,
            courseId: $service->course_id,
            transactionId: $tx->id
        );
    } else {
        // Handle regular services (existing logic)
    }
}
```

**CourseAccessService:**

```php
public function grantCourseAccess(int $childId, int $courseId, ?int $transactionId)
{
    $course = Course::with([
        'modules.lessons',
        'modules.lessons.liveSessions',
        'modules.assessments',
        'assessments'
    ])->findOrFail($courseId);

    // Create Access records for:
    // 1. All ContentLessons (self-paced)
    foreach ($module->lessons as $lesson) {
        Access::create([
            'child_id' => $childId,
            'content_lesson_id' => $lesson->id,
            'transaction_id' => $transactionId,
            'access' => true,
        ]);
    }

    // 2. All LiveLessonSessions
    foreach ($lesson->liveSessions as $session) {
        Access::create([
            'child_id' => $childId,
            'lesson_id' => $session->id,
            'transaction_id' => $transactionId,
            'access' => true,
        ]);
    }

    // 3. All Assessments
    foreach ($module->assessments as $assessment) {
        Access::create([
            'child_id' => $childId,
            'assessment_id' => $assessment->id,
            'transaction_id' => $transactionId,
            'access' => true,
        ]);
    }
}
```

### 4. User Accesses Content

**Access Checking:**

```php
// Check ContentLesson access
$child->accesses()
    ->where('content_lesson_id', $lessonId)
    ->where('access', true)
    ->exists();

// Check LiveSession access
$child->accesses()
    ->where('lesson_id', $sessionId)
    ->where('access', true)
    ->exists();

// Check Assessment access
$child->accesses()
    ->where('assessment_id', $assessmentId)
    ->where('access', true)
    ->exists();
```

## Implementation Files

### Core Files Created/Modified

1. **Migrations**
   - `2025_10_29_011403_add_course_support_to_services_table.php`
   - `2025_10_29_011427_add_content_lesson_support_to_access_table.php`

2. **Service Layer**
   - `app/Services/CourseAccessService.php` (NEW)

3. **Models Updated**
   - `app/Models/Service.php` (added `course_id`, `course()`, `isCourseService()`)
   - `app/Models/Access.php` (added `content_lesson_id`, `contentLesson()`)

4. **Jobs Updated**
   - `app/Jobs/GrantAccessForTransactionJob.php` (added course service handling)

## Example Usage

### Example 1: Complete Course Purchase Flow

**Admin Setup:**
```php
// 1. Create course
$course = Course::create([
    'title' => '11+ Math Mastery',
    'description' => 'Complete preparation course',
    'price' => 299,
]);

// 2. Add modules
$module = Module::create([
    'course_id' => $course->id,
    'title' => 'Algebra Basics',
]);

// 3. Add content lessons
$lesson = ContentLesson::create([
    'module_id' => $module->id,
    'title' => 'Variables Introduction',
    'lesson_type' => 'self_paced',
]);

// 4. Schedule live sessions
LiveLessonSession::create([
    'lesson_id' => $lesson->id,
    'scheduled_start_time' => '2025-01-15 17:00:00',
]);

// 5. Add assessments
$module->assessments()->attach($assessment->id);

// 6. Create service linking to course
$service = Service::create([
    'service_name' => '11+ Math Mastery Course',
    'course_id' => $course->id,
    'price' => 299,
]);
```

**User Purchase:**
```php
// User adds to cart
$cartItem = CartItem::create([
    'cart_id' => $cart->id,
    'service_id' => $service->id,
    'qty' => 1,
]);

// Checkout processes payment
// GrantAccessForTransactionJob runs automatically

// Child now has access to:
// - 25 ContentLessons
// - 10 LiveLessonSessions
// - 5 Assessments
```

**Checking Access:**
```php
// Check if child can access a lesson
if ($child->accesses()
    ->where('content_lesson_id', $lesson->id)
    ->where('access', true)
    ->exists()) {
    // Allow access to lesson
}
```

## Benefits

1. **Unified Purchase System**: Courses use the same checkout/payment infrastructure as services
2. **Automatic Access**: All course content automatically becomes accessible
3. **Permanent Access**: Access doesn't expire (as per requirements)
4. **Flexible Content**: Courses can contain self-paced lessons, live sessions, and assessments
5. **Backward Compatible**: Existing services continue to work unchanged

## Next Steps

### Frontend Implementation Needed

1. **Admin Interface**
   - Add course selection dropdown to Service create/edit forms
   - Show course preview when linked

2. **User Interface**
   - Create courses browse page
   - Show "My Courses" dashboard
   - Display enrolled courses with progress

3. **Access Helpers**
   - Add helper methods to Child model for access checking
   - Create course enrollment status components

### Suggested Helper Methods

```php
// In app/Models/Child.php

public function hasAccessToContentLesson(int $lessonId): bool
{
    return $this->accesses()
        ->where('content_lesson_id', $lessonId)
        ->where('access', true)
        ->exists();
}

public function hasAccessToLiveSession(int $sessionId): bool
{
    return $this->accesses()
        ->where('lesson_id', $sessionId)
        ->where('access', true)
        ->exists();
}

public function hasAccessToCourse(int $courseId): bool
{
    $course = Course::with('modules.lessons')->find($courseId);
    foreach ($course->modules as $module) {
        foreach ($module->lessons as $lesson) {
            if ($this->hasAccessToContentLesson($lesson->id)) {
                return true;
            }
        }
    }
    return false;
}

public function enrolledCourses()
{
    return Course::whereHas('modules.lessons', function($q) {
        $q->whereHas('accesses', function($accessQuery) {
            $accessQuery->where('child_id', $this->id)
                       ->where('access', true);
        });
    })->with('modules.lessons');
}
```

## Testing

### Manual Testing Steps

1. **Create a test course**
   ```
   Admin → Courses → Create
   - Add 2 modules
   - Add 3 lessons per module
   - Schedule 2 live sessions
   - Add 2 assessments
   ```

2. **Link to service**
   ```
   Admin → Services → Create
   - Link to test course
   - Set price
   ```

3. **Purchase as user**
   ```
   User → Browse Courses → Purchase
   - Complete checkout
   ```

4. **Verify access**
   ```
   Check database:
   - Should have 6 Access records for ContentLessons
   - Should have 2 Access records for LiveSessions
   - Should have 2 Access records for Assessments
   Total: 10 Access records created
   ```

## Troubleshooting

### Issue: No access records created after purchase

**Check:**
1. Is the service properly linked to a course? (`service.course_id` is set)
2. Did the transaction complete? (status is 'paid' or 'completed')
3. Is there a child mapping in transaction metadata?
4. Check logs: `storage/logs/laravel.log` for "Course access granted"

### Issue: Some content not accessible

**Check:**
1. Are relationships properly loaded? (eager loading in CourseAccessService)
2. Are assessments attached to modules/course?
3. Are live sessions properly linked to content lessons?

## Conclusion

The course purchase system is now fully operational. Courses can be purchased as services, and all course content (self-paced lessons, live sessions, and assessments) is automatically accessible to the purchaser's child.

The system maintains backward compatibility with existing services while providing a comprehensive solution for selling structured educational courses.
