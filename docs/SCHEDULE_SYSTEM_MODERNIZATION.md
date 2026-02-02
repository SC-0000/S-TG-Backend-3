# Schedule System Modernization

**Date:** December 11, 2025  
**Status:** Planning → Implementation  
**Priority:** High

---

## 📋 Overview

Modernize the Schedule & Calendar system to replace deprecated `Lesson` model with new `LiveLessonSession` and `ContentLesson` models, improving UX and adding missing features.

---

## 🔍 Current State Analysis

### **What's Working:**
- ✅ Assessments display correctly
- ✅ Calendar grid view
- ✅ Deadlines view with urgency grouping
- ✅ Filter and sort functionality
- ✅ Webcal feed integration

### **What's Broken:**
- ❌ **Lessons** - Uses deprecated `Lesson` model (commented out, migration pending)
- ❌ Calendar shows empty for lessons
- ❌ Deadlines missing lesson data

### **What's Missing:**
- ❌ **Live Lesson Sessions** (`LiveLessonSession` model) - Interactive scheduled lessons
- ❌ **Content Lessons** (`ContentLesson` model) - Self-paced lessons
- ❌ No progress tracking for content lessons
- ❌ No direct action buttons (Join/Start/Continue)
- ❌ No visual distinction between content types

---

## 🎯 Goals

1. **Replace deprecated models** with LiveLessonSession and ContentLesson
2. **Separate time-based vs self-paced content:**
   - Calendar: Live Sessions + Assessments (scheduled)
   - Sidebar: Content Lessons (self-paced, incomplete only)
3. **Add visual distinction** with color-coded types
4. **Improve UX** with direct action buttons
5. **Enhance mobile experience**

---

## 🏗️ Architecture Changes

### **Data Flow - Before:**
```
PortalController
  ├─ Lesson (DEPRECATED) ❌
  └─ Assessment ✅
       ↓
  Schedule.jsx
       ↓
  CalendarSection / DeadlinesSection
```

### **Data Flow - After:**
```
PortalController
  ├─ LiveLessonSession ✅ (NEW)
  ├─ ContentLesson ✅ (NEW)
  └─ Assessment ✅
       ↓
  Schedule.jsx
       ↓
  ├─ CalendarSection (Live Sessions + Assessments)
  ├─ DeadlinesSection (Live Sessions + Assessments)
  └─ IncompleteContentSection (NEW - Content Lessons)
```

---

## 📊 Data Structures

### **Backend Response Structure:**

```php
[
    // Calendar events (time-based)
    'calendarEvents' => [
        'liveSessions' => [
            [
                'id' => 1,
                'title' => 'Math Session',
                'description' => '...',
                'start_time' => '2025-12-15T10:00:00Z',
                'end_time' => '2025-12-15T11:00:00Z',
                'status' => 'scheduled|in_progress|completed',
                'type' => 'live_session',
                'child_ids' => ['1', '2'],
            ],
            // ...
        ],
        'assessments' => [
            [
                'id' => 1,
                'title' => 'Math Test',
                'description' => '...',
                'availability' => '2025-12-14T00:00:00Z',
                'deadline' => '2025-12-20T23:59:59Z',
                'duration' => 60,
                'status' => 'available',
                'type' => 'assessment',
                'child_ids' => ['1', '2'],
            ],
            // ...
        ],
    ],
    
    // Content lessons (self-paced, incomplete only)
    'incompleteContentLessons' => [
        [
            'id' => 1,
            'title' => 'Introduction to Algebra',
            'description' => '...',
            'course_name' => 'Mathematics Course',
            'module_name' => 'Module 1',
            'progress' => 75,
            'estimated_duration' => 45,
            'type' => 'content_lesson',
        ],
        // ...
    ],
    
    // Deadlines (future events only)
    'deadlines' => [
        'liveSessions' => [...],  // Future sessions
        'assessments' => [...],   // Future assessments
    ],
    
    'childrenList' => [...],
    'selectedChild' => null|object,
    'feedUrl' => 'webcal://...',
]
```

---

## 🎨 Visual Design System

### **Color Coding:**
| Type | Primary Color | Badge Color | Use Case |
|------|---------------|-------------|----------|
| **Live Session** | Red-500 | Red-50 bg, Red-700 text | Scheduled interactive lessons |
| **Assessment** | Yellow-500 | Yellow-50 bg, Yellow-700 text | Graded tests with deadlines |
| **Content Lesson** | Purple-600 | Purple-50 bg, Purple-700 text | Self-paced learning materials |

### **Icons:**
- 🎥 Live Session: `Video` icon
- 📝 Assessment: `FileText` icon
- 📚 Content Lesson: `BookOpen` icon

---

## 🔧 Implementation Steps

### **Phase 1: Backend (PortalController.php)**

#### **Step 1.1: Fetch Live Lesson Sessions**

```php
// Extract live session IDs from access records
$liveSessionIds = collect();
foreach ($accessRecords as $access) {
    if ($access->live_lesson_session_id) {
        $liveSessionIds->push($access->live_lesson_session_id);
    }
    if (isset($access->live_lesson_session_ids)) {
        foreach ((array) $access->live_lesson_session_ids as $sid) {
            $liveSessionIds->push($sid);
        }
    }
}
$liveSessionIds = $liveSessionIds->unique()->values();

// Fetch for calendar (include recent past)
$calendarLiveSessions = LiveLessonSession::whereIn('id', $liveSessionIds)
    ->where('start_time', '>=', now()->subDays(7))
    ->orderBy('start_time')
    ->get()
    ->map(function($session) use ($childIds) {
        return [
            'id' => $session->id,
            'title' => $session->title,
            'description' => $session->description ?? '',
            'start_time' => $session->start_time->toIso8601String(),
            'end_time' => $session->end_time?->toIso8601String(),
            'status' => $session->status,
            'type' => 'live_session',
            'child_ids' => collect($childIds)->map(fn($id) => (string) $id),
        ];
    });

// Fetch for deadlines (future only)
$deadlineLiveSessions = LiveLessonSession::whereIn('id', $liveSessionIds)
    ->where('start_time', '>=', now())
    ->orderBy('start_time')
    ->get()
    ->map(function($session) use ($childIds) {
        return [
            'id' => $session->id,
            'title' => $session->title,
            'description' => $session->description ?? '',
            'start_time' => $session->start_time->toIso8601String(),
            'end_time' => $session->end_time?->toIso8601String(),
            'status' => $session->status,
            'type' => 'live_session',
            'child_ids' => collect($childIds)->map(fn($id) => (string) $id),
        ];
    });
```

#### **Step 1.2: Fetch Content Lessons (Incomplete Only)**

```php
// Extract content lesson IDs from access records
$contentLessonIds = collect();
foreach ($accessRecords as $access) {
    if ($access->content_lesson_id) {
        $contentLessonIds->push($access->content_lesson_id);
    }
    if (isset($access->content_lesson_ids)) {
        foreach ((array) $access->content_lesson_ids as $clid) {
            $contentLessonIds->push($clid);
        }
    }
}
$contentLessonIds = $contentLessonIds->unique()->values();

// Fetch incomplete content lessons
$incompleteContentLessons = ContentLesson::whereIn('id', $contentLessonIds)
    ->with(['course', 'module'])
    ->get()
    ->filter(function($lesson) use ($childIds) {
        // Check if any child has incomplete progress
        foreach ($childIds as $childId) {
            $progress = LessonProgress::where('content_lesson_id', $lesson->id)
                ->where('child_id', $childId)
                ->first();
            
            // Include if no progress or not completed
            if (!$progress || $progress->completion_percentage < 100) {
                return true;
            }
        }
        return false;
    })
    ->map(function($lesson) use ($childIds) {
        // Get progress for first child (or aggregate if multiple)
        $progress = LessonProgress::where('content_lesson_id', $lesson->id)
            ->whereIn('child_id', $childIds)
            ->first();
            
        return [
            'id' => $lesson->id,
            'title' => $lesson->title,
            'description' => $lesson->description ?? '',
            'course_name' => $lesson->course->title ?? null,
            'module_name' => $lesson->module->title ?? null,
            'progress' => $progress ? $progress->completion_percentage : 0,
            'estimated_duration' => $lesson->estimated_duration_minutes ?? null,
            'type' => 'content_lesson',
        ];
    })
    ->values();
```

#### **Step 1.3: Update Assessments (Keep Structure)**

```php
// Calendar assessments
$calendarAssessments = Assessment::whereIn('id', $assessmentIds)
    ->get()
    ->map(function($a) use ($childIds) {
        return [
            'id' => $a->id,
            'title' => $a->title,
            'description' => $a->description ?? '',
            'availability' => $a->availability->toIso8601String(),
            'deadline' => $a->deadline->toIso8601String(),
            'duration' => $a->duration ?? 60,
            'status' => $a->status ?? 'available',
            'type' => 'assessment',
            'child_ids' => collect($childIds)->map(fn($id) => (string) $id),
        ];
    });

// Deadline assessments (future only)
$deadlineAssessments = Assessment::whereIn('id', $assessmentIds)
    ->where('deadline', '>=', now())
    ->orderBy('deadline')
    ->get()
    ->map(function($a) use ($childIds) {
        return [
            'id' => $a->id,
            'title' => $a->title,
            'description' => $a->description ?? '',
            'deadline' => $a->deadline->toIso8601String(),
            'duration' => $a->duration ?? 60,
            'status' => $a->status ?? 'available',
            'type' => 'assessment',
            'child_ids' => collect($childIds)->map(fn($id) => (string) $id),
        ];
    });
```

#### **Step 1.4: Return Structured Response**

```php
return Inertia::render('@parent/Schedule/Schedule', [
    'calendarEvents' => [
        'liveSessions' => $calendarLiveSessions,
        'assessments' => $calendarAssessments,
    ],
    'incompleteContentLessons' => $incompleteContentLessons,
    'deadlines' => [
        'liveSessions' => $deadlineLiveSessions,
        'assessments' => $deadlineAssessments,
    ],
    'childrenList' => $allChildren,
    'selectedChild' => $selectedChild,
    'feedUrl' => $webcal,
]);
```

---

### **Phase 2: Frontend Updates**

#### **Step 2.1: Update CalendarSection.jsx**

**Changes:**
1. Replace `calendarEvents.lessons` → `calendarEvents.liveSessions`
2. Update icon from `BookOpen` → `Video`
3. Update colors: Red instead of Blue
4. Update filter option: "Lessons" → "Live Sessions"

```jsx
// Process live sessions
if (calendarEvents.liveSessions) {
    calendarEvents.liveSessions.forEach(session => {
        events.push({
            id: `live-${session.id}`,
            title: session.title,
            start: new Date(session.start_time),
            end: new Date(session.end_time),
            type: 'live_session',
            icon: Video,
            color: 'bg-red-500',
            lightColor: 'bg-red-50',
            textColor: 'text-red-700'
        });
    });
}
```

#### **Step 2.2: Update DeadlinesSection.jsx**

Similar changes to CalendarSection:
- Replace `deadlines.lessons` → `deadlines.liveSessions`
- Update colors and icons

#### **Step 2.3: Create IncompleteContentSection.jsx**

**New Component:** `resources/js/parent/components/Schedule/IncompleteContentSection.jsx`

**Features:**
- Display incomplete content lessons
- Show progress bars
- "Start Lesson" / "Continue" buttons
- Course/Module context
- Estimated duration
- Empty state for completed lessons

```jsx
import React from 'react';
import { motion } from 'framer-motion';
import { BookOpen, Clock, ArrowRight, CheckCircle } from 'lucide-react';

export default function IncompleteContentSection({ contentLessons }) {
    return (
        <motion.div 
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            className="bg-white rounded-2xl shadow-lg p-6 border border-gray-100"
        >
            {/* Header */}
            <div className="flex items-center justify-between mb-4">
                <h3 className="text-xl font-bold text-gray-900 flex items-center gap-2">
                    <BookOpen className="w-6 h-6 text-purple-600" />
                    Continue Learning
                </h3>
                <span className="text-sm text-gray-500">
                    {contentLessons.length} lesson{contentLessons.length !== 1 ? 's' : ''} to complete
                </span>
            </div>
            
            {/* Empty State */}
            {contentLessons.length === 0 ? (
                <div className="text-center py-8">
                    <CheckCircle className="w-12 h-12 mx-auto text-green-400 mb-3" />
                    <p className="text-gray-600 font-medium">All lessons completed!</p>
                    <p className="text-sm text-gray-500 mt-1">Great job! 🎉</p>
                </div>
            ) : (
                /* Lesson Cards */
                <div className="space-y-3">
                    {contentLessons.map((lesson) => (
                        <div 
                            key={lesson.id} 
                            className="border border-gray-200 rounded-xl p-4 hover:shadow-md transition-shadow"
                        >
                            {/* Lesson Header */}
                            <div className="flex items-start justify-between mb-2">
                                <div className="flex-1">
                                    <h4 className="font-semibold text-gray-900">{lesson.title}</h4>
                                    {lesson.course_name && (
                                        <p className="text-sm text-gray-500 mt-1">
                                            {lesson.course_name}
                                            {lesson.module_name && ` • ${lesson.module_name}`}
                                        </p>
                                    )}
                                </div>
                                {lesson.estimated_duration && (
                                    <span className="text-xs text-gray-500 flex items-center gap-1">
                                        <Clock className="w-3 h-3" />
                                        {lesson.estimated_duration}min
                                    </span>
                                )}
                            </div>
                            
                            {/* Progress Bar */}
                            <div className="mb-3">
                                <div className="flex items-center justify-between text-xs text-gray-600 mb-1">
                                    <span>Progress</span>
                                    <span>{lesson.progress}%</span>
                                </div>
                                <div className="w-full bg-gray-200 rounded-full h-2">
                                    <div 
                                        className="bg-purple-600 h-2 rounded-full transition-all"
                                        style={{ width: `${lesson.progress}%` }}
                                    />
                                </div>
                            </div>
                            
                            {/* Action Button */}
                            <a
                                href={route('parent.content-lessons.player', lesson.id)}
                                className="w-full flex items-center justify-center gap-2 bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700 transition-colors text-sm font-medium"
                            >
                                {lesson.progress > 0 ? 'Continue' : 'Start Lesson'}
                                <ArrowRight className="w-4 h-4" />
                            </a>
                        </div>
                    ))}
                </div>
            )}
        </motion.div>
    );
}
```

#### **Step 2.4: Update Schedule.jsx Main Page**

**Changes:**
- Import new `IncompleteContentSection`
- Update layout: 2-column grid (calendar + sidebar)
- Pass `incompleteContentLessons` prop

```jsx
import IncompleteContentSection from './IncompleteContentSection';

export default function Schedule({ 
    calendarEvents, 
    incompleteContentLessons, 
    deadlines, 
    childrenList, 
    selectedChild, 
    feedUrl 
}) {
    // ... existing code ...
    
    return (
        <>
            {/* ... existing header ... */}
            
            <section className="py-8 px-6 bg-white/75">
                <div className="max-w-7xl mx-auto">
                    
                    {/* UPDATED: Grid Layout */}
                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        
                        {/* Left: Calendar/Deadlines (2/3 width) */}
                        <div className="lg:col-span-2">
                            <AnimatePresence mode="wait">
                                {activeTab === TAB_CALENDAR && (
                                    <motion.div key="calendar" {...animationProps}>
                                        <CalendarSection 
                                            calendarEvents={calendarEvents} 
                                            feedUrl={feedUrl}
                                            childrenList={childrenList}
                                            selectedChild={selectedChild}
                                        />
                                    </motion.div>
                                )}
                                {activeTab === TAB_DEADLINES && (
                                    <motion.div key="deadlines" {...animationProps}>
                                        <DeadlinesSection 
                                            deadlines={deadlines}
                                            childrenList={childrenList}
                                            selectedChild={selectedChild}
                                        />
                                    </motion.div>
                                )}
                            </AnimatePresence>
                        </div>
                        
                        {/* NEW: Right Sidebar (1/3 width) */}
                        <div className="lg:col-span-1">
                            <IncompleteContentSection 
                                contentLessons={incompleteContentLessons} 
                            />
                        </div>
                        
                    </div>
                </div>
            </section>
        </>
    );
}
```

---

### **Phase 3: UI Enhancements**

#### **3.1: Add Direct Action Buttons**

On calendar events and deadline cards:

```jsx
{/* Live Session */}
<a 
    href={route('parent.live-sessions.join', session.id)}
    className="flex items-center gap-2 bg-red-600 text-white px-4 py-2 rounded-lg hover:bg-red-700"
>
    <Video className="w-4 h-4" />
    {session.status === 'in_progress' ? 'Join Now' : 'View Details'}
</a>

{/* Assessment */}
<a 
    href={route('parent.assessments.take', assessment.id)}
    className="flex items-center gap-2 bg-yellow-600 text-white px-4 py-2 rounded-lg hover:bg-yellow-700"
>
    <FileText className="w-4 h-4" />
    Start Assessment
</a>
```

#### **3.2: Mobile Responsive Layout**

```jsx
{/* Mobile: Stack sidebar below calendar */}
<div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
    {/* Calendar - Full width on mobile, 2/3 on desktop */}
    <div className="lg:col-span-2">
        {/* Calendar/Deadlines content */}
    </div>
    
    {/* Sidebar - Full width on mobile, 1/3 on desktop */}
    <div className="lg:col-span-1">
        {/* Content lessons */}
    </div>
</div>
```

---

## ✅ Testing Checklist

### **Backend Testing:**
- [ ] Live sessions appear in calendar events
- [ ] Live sessions appear in deadlines (future only)
- [ ] Content lessons filtered to incomplete only
- [ ] Content lessons show correct progress
- [ ] Assessments still work correctly
- [ ] Child filtering works
- [ ] Access records properly linked

### **Frontend Testing:**
- [ ] Calendar displays live sessions (red)
- [ ] Calendar displays assessments (yellow)
- [ ] Stats show correct counts
- [ ] Filter dropdown updated (Live Sessions instead of Lessons)
- [ ] Deadlines section shows live sessions
- [ ] Incomplete content section displays correctly
- [ ] Progress bars render properly
- [ ] Action buttons work (Join, Start, Continue)
- [ ] Empty states show when appropriate
- [ ] Mobile layout responsive

### **Integration Testing:**
- [ ] Navigate between Calendar and Deadlines tabs
- [ ] Filter by content type
- [ ] Sort by date/title/type
- [ ] Search functionality
- [ ] Child selection updates data
- [ ] Links navigate correctly

---

## 🚀 Deployment Plan

1. **Create documentation** ✅ (This file)
2. **Update backend** (PortalController.php)
3. **Update CalendarSection** component
4. **Update DeadlinesSection** component
5. **Create IncompleteContentSection** component
6. **Update Schedule.jsx** main page
7. **Test locally**
8. **Deploy to staging**
9. **User acceptance testing**
10. **Deploy to production**

---

## 📝 Notes

- **Content Lessons**: Only show incomplete lessons (progress < 100%)
- **No Time Constraint**: Content lessons are self-paced, shown in sidebar not calendar
- **Color System**: Red = Live, Yellow = Assessments, Purple = Content
- **Skipped Features**: Study planner, Agenda view, Advanced customization (future consideration)

---

## 🔗 Related Documentation

- [Lesson System Implementation](./LESSON_SYSTEM_IMPLEMENTATION_PLAN.md)
- [Live Lesson System](./LIVE_LESSON_PHASE5_COMPLETE_CONTEXT.md)
- [Course Purchase System](./COURSE_PURCHASE_SYSTEM.md)

---

**Last Updated:** December 11, 2025  
**Next Review:** After implementation completion
