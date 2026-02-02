# Course Access Management System

## Overview

This document describes the unified access management system for courses, lessons, assessments, and live sessions.

## Architecture

### Access Table Structure

The `access` table uses JSON arrays to store multiple IDs efficiently:

```sql
- child_id (int)
- course_ids (JSON array)      -- Array of Course IDs
- lesson_ids (JSON array)       -- Array of ContentLesson IDs
- module_ids (JSON array)       -- Array of Module IDs
- assessment_ids (JSON array)   -- Array of Assessment IDs
- transaction_id (int)
- access (boolean)
- metadata (JSON)               -- Stores live_session_ids and other info
```

### Key Benefits

1. **Single Record Per Purchase** - One access record grants access to entire course
2. **Efficient Queries** - JSON contains queries work well with proper indexing
3. **Backward Compatible** - Singular columns (`content_lesson_id`, `assessment_id`) still supported
4. **Scalable** - Adding new content to course automatically included

## How It Works

### When a Course is Purchased

```php
// CourseAccessService->grantCourseAccess()

1. Load course with all relationships
2. Collect all IDs:
   - lesson_ids: All ContentLessons in all modules
   - assessment_ids: Module + course level assessments
   - module_ids: All modules
   - live_session_ids: All live sessions (stored in metadata)
3. Create ONE access record with all arrays populated
```

### Access Checking

```php
// Check lesson access
$hasAccess = Access::forChild($childId)
    ->withLessonAccess($lessonId)
    ->exists();

// Check course access
$hasAccess = Access::forChild($childId)
    ->withCourseAccess($courseId)
    ->exists();

// Check assessment access
$hasAccess = Access::forChild($childId)
    ->withAssessmentAccess($assessmentId)
    ->exists();
```

## Updated Models

### Access Model

**New Fillable Fields:**
- `lesson_ids`
- `course_ids`
- `module_ids`
- `assessment_ids`

**New Casts:**
```php
'lesson_ids' => 'array',
'course_ids' => 'array',
'module_ids' => 'array',
'assessment_ids' => 'array',
```

**Helper Methods:**
```php
hasAccessToCourse($courseId)
hasAccessToLesson($lessonId)
hasAccessToAssessment($assessmentId)
hasAccessToModule($moduleId)
```

**Scopes:**
```php
forChild($childId)              // Filter by child + access=true
withCourseAccess($courseId)     // JSON contains course_id
withLessonAccess($lessonId)     // JSON contains lesson_id OR singular match
withAssessmentAccess($id)       // JSON contains assessment_id OR singular match
```

### Course Model

**New Helper Methods:**
```php
getAllLessonIds()           // Returns array of all ContentLesson IDs
getAllAssessmentIds()       // Returns array of all Assessment IDs (module + course)
getAllModuleIds()           // Returns array of all Module IDs
getAllLiveSessionIds()      // Returns array of all LiveLessonSession IDs
```

### ContentLesson Model

**New Relationship:**
```php
liveSessions()  // hasMany LiveLessonSession
```

## Services

### CourseAccessService

**grantCourseAccess($childId, $courseId, $transactionId)**
- Creates single access record with all content IDs
- Returns statistics about granted access

**hasLessonAccess($childId, $lessonId)**
- Checks if child has access to specific lesson

**hasAssessmentAccess($childId, $assessmentId)**
- Checks if child has access to specific assessment

**hasCourseAccess($childId, $courseId)**
- Checks if child has access to entire course

## Jobs

### GrantAccessForTransactionJob

When a transaction completes:

1. **If Service is Course Type:**
   - Calls `CourseAccessService->grantCourseAccess()`
   - Creates ONE access record with all IDs

2. **If Service is Regular (Individual Lessons/Assessments):**
   - Creates access record with lesson_ids and assessment_ids arrays
   - Maintains backward compatibility

## Database Queries

### Efficient Access Checks

```sql
-- Check if child has access to lesson
SELECT * FROM access 
WHERE child_id = ? 
AND access = true
AND (
    JSON_CONTAINS(lesson_ids, ?) 
    OR content_lesson_id = ?
);

-- Check if child has access to course
SELECT * FROM access
WHERE child_id = ?
AND access = true
AND JSON_CONTAINS(course_ids, ?);
```

### Recommended Indexes

```sql
CREATE INDEX idx_access_child_id ON access(child_id);
CREATE INDEX idx_access_child_access ON access(child_id, access);
```

## Migration Path

### For Existing Data

If you have existing individual access records, you can migrate them:

```php
// Group by child_id and transaction_id
$grouped = Access::whereNotNull('transaction_id')
    ->get()
    ->groupBy(fn($a) => $a->child_id . '-' . $a->transaction_id);

foreach ($grouped as $records) {
    // Collect all IDs
    $lessonIds = $records->pluck('content_lesson_id')->filter()->values()->toArray();
    $assessmentIds = $records->pluck('assessment_id')->filter()->values()->toArray();
    
    // Create consolidated record
    Access::create([
        'child_id' => $records->first()->child_id,
        'lesson_ids' => $lessonIds,
        'assessment_ids' => $assessmentIds,
        'transaction_id' => $records->first()->transaction_id,
        'access' => true,
    ]);
    
    // Optionally delete old records
    // $records->each->delete();
}
```

## Best Practices

### 1. Always Use Scopes
```php
// Good
Access::forChild($childId)->withLessonAccess($lessonId)->exists();

// Avoid raw queries
DB::table('access')->where(...)->exists();
```

### 2. Use CourseAccessService
```php
// Good
app(CourseAccessService::class)->grantCourseAccess($childId, $courseId);

// Avoid manual creation
Access::create([...]); // Only use through service
```

### 3. Check Access Consistently
```php
// In controllers
if (!app(CourseAccessService::class)->hasLessonAccess($childId, $lessonId)) {
    abort(403, 'No access to this lesson');
}
```

## Testing

### Example Test Cases

```php
// Test course access grants all content access
$child = Child::factory()->create();
$course = Course::factory()->withModules(3)->create();

app(CourseAccessService::class)->grantCourseAccess($child->id, $course->id);

// Should have access to all lessons
$course->modules->each(function($module) use ($child) {
    $module->lessons->each(function($lesson) use ($child) {
        $this->assertTrue(
            app(CourseAccessService::class)->hasLessonAccess($child->id, $lesson->id)
        );
    });
});

// Should have access to all assessments
$course->assessments->each(function($assessment) use ($child) {
    $this->assertTrue(
        app(CourseAccessService::class)->hasAssessmentAccess($child->id, $assessment->id)
    );
});
```

## Frontend Integration

### Checking Access in Controllers

```php
public function show(ContentLesson $lesson)
{
    $child = auth()->user()->selectedChild;
    
    if (!app(CourseAccessService::class)->hasLessonAccess($child->id, $lesson->id)) {
        return redirect()->route('courses.browse')
            ->with('error', 'Please purchase the course to access this lesson.');
    }
    
    return inertia('ContentLessons/Player', [
        'lesson' => $lesson->load('slides'),
    ]);
}
```

### Displaying Available Courses

```php
public function myCourses()
{
    $child = auth()->user()->selectedChild;
    
    // Get all courses the child has access to
    $courseIds = Access::forChild($child->id)
        ->get()
        ->flatMap(fn($a) => $a->course_ids ?? [])
        ->unique()
        ->values();
    
    $courses = Course::whereIn('id', $courseIds)
        ->with('modules.lessons')
        ->get();
    
    return inertia('Courses/MyCourses', [
        'courses' => $courses,
    ]);
}
```

## Summary

This array-based access management system provides:
- ✅ Efficient single-record per purchase
- ✅ Scalable for courses with many lessons
- ✅ Backward compatible with existing code
- ✅ Easy to query and manage
- ✅ Clear separation of concerns

All course purchases now create one clean access record that grants access to all course content, making the system more maintainable and performant.
