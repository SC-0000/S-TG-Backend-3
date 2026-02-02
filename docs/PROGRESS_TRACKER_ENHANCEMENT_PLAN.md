# Progress Tracker Enhancement Implementation Plan

## Document Version: 1.0
**Last Updated:** January 11, 2025  
**Status:** Planning Phase

---

## Table of Contents
1. [Executive Summary](#executive-summary)
2. [Current System Analysis](#current-system-analysis)
3. [Data Architecture](#data-architecture)
4. [Backend Implementation](#backend-implementation)
5. [Frontend Implementation](#frontend-implementation)
6. [Feature-by-Feature Plan](#feature-by-feature-plan)
7. [Implementation Phases](#implementation-phases)
8. [Testing Strategy](#testing-strategy)
9. [Deployment Plan](#deployment-plan)

---

## Executive Summary

### Current State
The existing Progress Tracker shows:
- ✅ Live sessions (old Lesson model) with attendance
- ✅ Assessments with submissions and category breakdowns
- ✅ Basic journey overview

### Desired State
Enhanced Progress Tracker will include:
- ✅ **Courses** with module structure and progress
- ✅ **Content Lessons** (self-paced) with detailed progress tracking
- ✅ **Live Sessions** with participation details (join/leave times, duration)
- ✅ **Enhanced Assessments** with context linking
- ✅ **Comprehensive Analytics** with time investment, learning patterns, and performance insights

### Impact
- **Parents:** Clear visibility into student's learning journey
- **Students:** Better understanding of progress and goals
- **Teachers:** Insights into student engagement and performance
- **Organization:** Data-driven decision making

---

## Current System Analysis

### Current Data Flow
```
TrackerController::show()
├── Fetches Access records for child
├── Extracts lesson_ids and assessment_ids
├── Loads Lessons (old model) with attendances
├── Loads Assessments with submissions
└── Returns to TrackerTab.jsx
```

### Current Frontend Structure
```
ProgressTracker.jsx
├── TabSwitcher.jsx
├── Breadcrumbs.jsx
├── Overview/
│   └── OverviewTab.jsx
└── Tracker/
    └── TrackerTab.jsx (4 sections)
        ├── Overview (charts, stats)
        ├── Lessons (live sessions table)
        ├── Assessments (expandable cards)
        └── Analytics (category performance)
```

### Identified Gaps
1. ❌ **No Course Progress Visibility** - Parents can't see course structure or progress
2. ❌ **No Content Lesson Tracking** - Self-paced lessons (with slides, questions) not shown
3. ❌ **Limited Live Session Data** - Only attendance status, no participation details
4. ❌ **No Time Investment Tracking** - Can't see how much time spent learning
5. ❌ **Disconnected Data** - No context linking between courses → modules → lessons → assessments

---

## Data Architecture

### Database Schema Overview

#### Core Tables
```
courses
├── id, title, description, thumbnail
├── estimated_duration_minutes
├── status, order_position
└── organization_id

modules
├── id, course_id, title, description
├── order_position
└── estimated_duration_minutes

new_lessons (ContentLesson)
├── id, title, description
├── lesson_type, delivery_mode, status
├── estimated_minutes
└── organization_id

lesson_progress
├── id, child_id, lesson_id
├── status (not_started, in_progress, completed)
├── completion_percentage
├── slides_viewed, time_spent_seconds
├── questions_attempted, questions_correct
├── uploads_submitted, uploads_required
├── started_at, completed_at, last_accessed_at
└── live_lesson_session_id

live_lesson_sessions
├── id, lesson_id, course_id, teacher_id
├── status (scheduled, live, ended)
├── scheduled_start_time, actual_start_time, end_time
└── recording_url

live_session_participants
├── id, live_lesson_session_id, child_id
├── status (invited, joined, left)
├── joined_at, left_at
├── audio_muted, video_off
└── interaction_data

access
├── id, child_id
├── lesson_id, content_lesson_id, assessment_id
├── lesson_ids, course_ids, module_ids, assessment_ids (JSON)
├── purchase_date, due_date
└── access, payment_status

assessments
├── id, title, description, type
├── journey_category_id
├── availability, deadline
└── questions_json

assessment_submissions
├── id, assessment_id, child_id
├── marks_obtained, total_marks
├── status, finished_at
└── answers_json
```

### Relationships Map
```
Course
├── hasMany: Modules
├── belongsToMany: Assessments (course-level)
└── hasManyThrough: ContentLessons (via modules)

Module
├── belongsTo: Course
├── belongsToMany: ContentLessons
└── belongsToMany: Assessments (module-level)

ContentLesson
├── belongsToMany: Modules
├── hasMany: LessonSlides
├── hasMany: LiveLessonSessions
├── hasMany: LessonProgress
└── belongsToMany: Assessments (lesson-embedded)

LessonProgress
├── belongsTo: Child
├── belongsTo: ContentLesson
└── belongsTo: LiveLessonSession

LiveLessonSession
├── belongsTo: ContentLesson
├── belongsTo: Course
├── belongsTo: Teacher (User)
└── hasMany: LiveSessionParticipants

LiveSessionParticipant
├── belongsTo: LiveLessonSession
└── belongsTo: Child

Assessment
├── belongsToMany: Courses
├── belongsToMany: Modules
├── belongsToMany: ContentLessons
└── hasMany: AssessmentSubmissions

Access
├── belongsTo: Child
├── Stores: course_ids, module_ids, lesson_ids, assessment_ids
```

---

## Backend Implementation

### Phase 1: TrackerController Enhancement

#### Step 1: Add Course Progress Method

```php
// Add to TrackerController.php

private function getCourseProgress($childIds)
{
    // Get courses child has access to
    $accessRecords = Access::whereIn('child_id', $childIds)->get();
    
    $courseIds = collect();
    foreach ($accessRecords as $access) {
        if ($access->course_ids) {
            foreach ((array) $access->course_ids as $cid) {
                $courseIds->push($cid);
            }
        }
    }
    $courseIds = $courseIds->unique();
    
    // Fetch courses with nested relationships
    $courses = Course::with([
        'modules' => function($q) {
            $q->orderBy('order_position')
              ->with([
                  'lessons' => function($lq) {
                      $lq->select('new_lessons.id', 'new_lessons.title', 'new_lessons.estimated_minutes', 'new_lessons.status');
                  }
              ]);
        }
    ])
    ->whereIn('id', $courseIds)
    ->get();
    
    // Calculate progress for each course
    $courseData = $courses->map(function($course) use ($childIds) {
        $allLessonIds = $course->modules->flatMap(function($module) {
            return $module->lessons->pluck('id');
        });
        
        // Get progress for all lessons in course
        $progressRecords = LessonProgress::whereIn('child_id', $childIds)
            ->whereIn('lesson_id', $allLessonIds)
            ->get();
        
        $totalLessons = $allLessonIds->count();
        $completedLessons = $progressRecords->where('status', 'completed')->count();
        $inProgressLessons = $progressRecords->where('status', 'in_progress')->count();
        
        $totalTimeSpent = $progressRecords->sum('time_spent_seconds');
        $avgCompletion = $totalLessons > 0 
            ? $progressRecords->avg('completion_percentage') 
            : 0;
        
        // Module-level progress
        $moduleProgress = $course->modules->map(function($module) use ($progressRecords) {
            $moduleLessonIds = $module->lessons->pluck('id');
            $moduleProgress = $progressRecords->whereIn('lesson_id', $moduleLessonIds);
            
            $total = $moduleLessonIds->count();
            $completed = $moduleProgress->where('status', 'completed')->count();
            
            return [
                'id' => $module->id,
                'title' => $module->title,
                'order' => $module->order_position,
                'lessons_total' => $total,
                'lessons_completed' => $completed,
                'completion_percentage' => $total > 0 ? round(($completed / $total) * 100) : 0,
                'status' => $completed === $total ? 'completed' : ($completed > 0 ? 'in_progress' : 'not_started'),
            ];
        });
        
        return [
            'id' => $course->id,
            'title' => $course->title,
            'description' => $course->description,
            'thumbnail' => $course->thumbnail,
            'total_modules' => $course->modules->count(),
            'completed_modules' => $moduleProgress->where('status', 'completed')->count(),
            'total_lessons' => $totalLessons,
            'completed_lessons' => $completedLessons,
            'in_progress_lessons' => $inProgressLessons,
            'overall_completion' => round($avgCompletion),
            'time_spent_minutes' => round($totalTimeSpent / 60),
            'estimated_duration' => $course->estimated_duration_minutes,
            'last_accessed' => $progressRecords->max('last_accessed_at')?->toDateTimeString(),
            'modules' => $moduleProgress,
        ];
    });
    
    return $courseData;
}
```

#### Step 2: Add Content Lesson Progress Method

```php
private function getContentLessonProgress($childIds)
{
    // Get content lesson IDs from access
    $accessRecords = Access::whereIn('child_id', $childIds)->get();
    
    $contentLessonIds = collect();
    foreach ($accessRecords as $access) {
        if ($access->content_lesson_id) {
            $contentLessonIds->push($access->content_lesson_id);
        }
        if ($access->lesson_ids) {
            foreach ((array) $access->lesson_ids as $lid) {
                $contentLessonIds->push($lid);
            }
        }
    }
    $contentLessonIds = $contentLessonIds->unique();
    
    // Fetch content lessons with progress
    $lessons = ContentLesson::with([
        'modules.course',
        'slides',
        'progress' => function($q) use ($childIds) {
            $q->whereIn('child_id', $childIds);
        }
    ])
    ->whereIn('id', $contentLessonIds)
    ->get();
    
    return $lessons->map(function($lesson) {
        $progress = $lesson->progress->first();
        $course = $lesson->modules->first()?->course;
        $module = $lesson->modules->first();
        
        return [
            'id' => $lesson->id,
            'title' => $lesson->title,
            'description' => $lesson->description,
            'course_name' => $course?->title,
            'course_id' => $course?->id,
            'module_name' => $module?->title,
            'module_id' => $module?->id,
            'lesson_type' => $lesson->lesson_type,
            'delivery_mode' => $lesson->delivery_mode,
            'estimated_minutes' => $lesson->estimated_minutes,
            'total_slides' => $lesson->slides->count(),
            
            // Progress data
            'status' => $progress?->status ?? 'not_started',
            'completion_percentage' => $progress?->completion_percentage ?? 0,
            'slides_viewed' => count($progress?->slides_viewed ?? []),
            'questions_attempted' => $progress?->questions_attempted ?? 0,
            'questions_correct' => $progress?->questions_correct ?? 0,
            'questions_total' => $progress?->questions_total ?? 0,
            'questions_score' => $progress?->questions_score ?? 0,
            'uploads_submitted' => $progress?->uploads_submitted ?? 0,
            'uploads_required' => $progress?->uploads_required ?? 0,
            'time_spent_minutes' => round(($progress?->time_spent_seconds ?? 0) / 60),
            'started_at' => $progress?->started_at?->toDateTimeString(),
            'completed_at' => $progress?->completed_at?->toDateTimeString(),
            'last_accessed' => $progress?->last_accessed_at?->toDateTimeString(),
        ];
    });
}
```

#### Step 3: Add Live Session Participation Method

```php
private function getLiveSessionsWithParticipation($childIds)
{
    // Get live session IDs from access
    $accessRecords = Access::whereIn('child_id', $childIds)->get();
    
    $liveLessonIds = collect();
    foreach ($accessRecords as $access) {
        if ($access->lesson_id) {
            $liveLessonIds->push($access->lesson_id);
        }
        // Note: lesson_ids in access could also contain live lesson session IDs
        if ($access->lesson_ids) {
            foreach ((array) $access->lesson_ids as $lid) {
                $liveLessonIds->push($lid);
            }
        }
    }
    $liveLessonIds = $liveLessonIds->unique();
    
    // Fetch live lesson sessions with participation
    $sessions = LiveLessonSession::with([
        'contentLesson',
        'course',
        'teacher',
        'participants' => function($q) use ($childIds) {
            $q->whereIn('child_id', $childIds);
        }
    ])
    ->whereIn('id', $liveLessonIds)
    ->orWhereHas('contentLesson', function($q) use ($accessRecords) {
        // Also get sessions linked to content lessons they have access to
        $contentLessonIds = $accessRecords->pluck('content_lesson_id')->filter();
        $q->whereIn('id', $contentLessonIds);
    })
    ->orderBy('scheduled_start_time', 'desc')
    ->get();
    
    return $sessions->map(function($session) {
        $participant = $session->participants->first();
        
        // Determine session status
        $now = now();
        $sessionStatus = 'upcoming';
        if ($session->status === 'ended') {
            $sessionStatus = 'completed';
        } elseif ($session->status === 'live') {
            $sessionStatus = 'live';
        } elseif ($session->scheduled_start_time < $now && $session->status !== 'live') {
            $sessionStatus = 'missed';
        }
        
        // Calculate duration
        $duration = null;
        if ($participant && $participant->joined_at && $participant->left_at) {
            $duration = $participant->joined_at->diffInMinutes($participant->left_at);
        }
        
        return [
            'id' => $session->id,
            'title' => $session->contentLesson?->title ?? 'Live Session',
            'course_name' => $session->course?->title,
            'course_id' => $session->course_id,
            'lesson_name' => $session->contentLesson?->title,
            'lesson_id' => $session->lesson_id,
            'teacher_name' => $session->teacher?->name,
            'teacher_id' => $session->teacher_id,
            'scheduled_time' => $session->scheduled_start_time?->toDateTimeString(),
            'scheduled_date' => $session->scheduled_start_time?->toDateString(),
            'start_time' => $session->actual_start_time?->toDateTimeString(),
            'end_time' => $session->end_time?->toDateTimeString(),
            'session_status' => $sessionStatus,
            'recording_url' => $session->recording_url,
            'recording_available' => !empty($session->recording_url),
            
            // Participation data
            'participated' => $participant !== null,
            'attendance_status' => $participant?->status ?? 'not_joined',
            'joined_at' => $participant?->joined_at?->toDateTimeString(),
            'left_at' => $participant?->left_at?->toDateTimeString(),
            'duration_minutes' => $duration,
            'connection_status' => $participant?->connection_status,
        ];
    });
}
```

#### Step 4: Add Analytics Calculation Method

```php
private function calculateAnalytics($childIds, $courses, $contentLessons, $liveSessions, $assessments)
{
    // Time investment calculation
    $contentLessonTime = $contentLessons->sum('time_spent_minutes');
    $liveSessionTime = $liveSessions->sum('duration_minutes');
    $assessmentTime = 0; // Can be calculated if you track assessment time
    
    $totalTimeMinutes = $contentLessonTime + $liveSessionTime + $assessmentTime;
    
    // Learning patterns - would need historical data, simplified here
    $recentActivity = LessonProgress::whereIn('child_id', $childIds)
        ->where('last_accessed_at', '>=', now()->subDays(30))
        ->get();
    
    // Group by day of week
    $activityByDay = $recentActivity->groupBy(function($item) {
        return $item->last_accessed_at->format('l'); // Monday, Tuesday, etc.
    })->map->count();
    
    $mostActiveDay = $activityByDay->sortDesc()->keys()->first() ?? 'N/A';
    
    // Current streak calculation
    $progressDates = $recentActivity->pluck('last_accessed_at')
        ->map(fn($date) => $date->toDateString())
        ->unique()
        ->sort()
        ->values();
    
    $streak = 0;
    $currentDate = now()->toDateString();
    foreach ($progressDates->reverse() as $date) {
        if ($date === $currentDate || $date === now()->subDay()->toDateString()) {
            $streak++;
            $currentDate = \Carbon\Carbon::parse($date)->subDay()->toDateString();
        } else {
            break;
        }
    }
    
    return [
        'time_investment' => [
            'total_minutes' => $totalTimeMinutes,
            'total_hours' => round($totalTimeMinutes / 60, 1),
            'content_lessons_minutes' => $contentLessonTime,
            'live_sessions_minutes' => $liveSessionTime,
            'assessments_minutes' => $assessmentTime,
            'avg_session_minutes' => $recentActivity->count() > 0 
                ? round($contentLessonTime / $recentActivity->count()) 
                : 0,
        ],
        'learning_patterns' => [
            'most_active_day' => $mostActiveDay,
            'peak_time' => 'N/A', // Would need hour-level tracking
            'current_streak_days' => $streak,
            'total_active_days' => $progressDates->count(),
        ],
        'course_breakdown' => $courses->map(function($course) {
            return [
                'course_id' => $course['id'],
                'course_title' => $course['title'],
                'completion' => $course['overall_completion'],
                'time_spent' => $course['time_spent_minutes'],
                'modules' => $course['modules'],
            ];
        }),
    ];
}
```

#### Step 5: Update Main show() Method

```php
public function show(Request $request)
{
    $user     = $request->user();
    $childKey = $request->get('child', 'all');
    
    // 1. Which children does this user see?
    $children = $user->role === 'admin'
        ? Child::select('id', 'child_name AS name')->orderBy('name')->get()
        : $user->children()
               ->select('id', 'child_name AS name')
               ->orderBy('name')
               ->get();
    
    $childIds = ($childKey === 'all')
        ? $children->pluck('id')
        : collect([(int)$childKey]);
    
    // 2. Get ALL data
    $courses = $this->getCourseProgress($childIds);
    $contentLessons = $this->getContentLessonProgress($childIds);
    $liveSessions = $this->getLiveSessionsWithParticipation($childIds);
    
    // 3. Get assessment data (keep existing logic, enhance with context)
    $assessments = $this->getAssessmentsWithContext($childIds);
    
    // 4. Calculate analytics
    $analytics = $this->calculateAnalytics(
        $childIds, 
        $courses, 
        $contentLessons, 
        $liveSessions, 
        $assessments
    );
    
    // 5. Journey overview (keep existing)
    $journeys = $this->getJourneyOverview();
    $childrenData = $this->getChildrenJourneyData($user);
    
    // 6. Child stats (keep existing, enhance)
    $childStats = $this->calculateChildStats($childIds, $assessments);
    
    // 7. Return enhanced data
    return Inertia::render('@parent/Main/ProgressTracker', [
        'progressData' => [
            'courses' => $courses,
            'contentLessons' => $contentLessons,
            'liveSessions' => $liveSessions,
            'assessments' => $assessments,
            'analytics' => $analytics,
            'childrenStats' => $childStats,
        ],
        'childrenList' => $children,
        'selectedChild' => $childKey,
        'journeys' => $journeys,
        'childrenData' => $childrenData,
    ]);
}
```

---

## Frontend Implementation

### Component Structure

```
ProgressTracker/
├── Tracker/
│   ├── TrackerTab.jsx (main container)
│   ├── Overview/
│   │   ├── QuickStats.jsx
│   │   ├── CourseProgressCards.jsx
│   │   └── RecentActivity.jsx
│   ├── Lessons/
│   │   ├── LessonsTabSwitcher.jsx
│   │   ├── ContentLessons/
│   │   │   ├── ContentLessonCard.jsx
│   │   │   └── ContentLessonFilters.jsx
│   │   └── LiveSessions/
│   │       ├── LiveSessionCard.jsx
│   │       ├── LiveSessionCalendar.jsx
│   │       └── AttendanceStats.jsx
│   ├── Assessments/
│   │   ├── AssessmentCard.jsx (existing, enhanced)
│   │   ├── CategoryBreakdown.jsx
│   │   └── PerformanceInsights.jsx
│   └── Analytics/
│       ├── TimeInvestmentChart.jsx
│       ├── CourseBreakdown.jsx
│       ├── LearningPatterns.jsx
│       └── CategoryPerformance.jsx (existing)
```

### Phase 1: Update TrackerTab.jsx Main Structure

```jsx
// Update section navigation
const sections = [
  { key: 'overview', icon: MdOutlineAnalytics, label: 'Overview' },
  { key: 'courses', icon: FaGraduationCap, label: 'Courses' },
  { key: 'lessons', icon: FaBook, label: 'Lessons' },
  { key: 'assessments', icon: FaTrophy, label: 'Assessments' },
  { key: 'analytics', icon: FaChartLine, label: 'Analytics' }
];
```

### Phase 2: Create CourseProgressCards Component

```jsx
// resources/js/parent/components/ProgressTracker/Tracker/Overview/CourseProgressCards.jsx
import React, { useState } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { FaChevronDown, FaChevronUp, FaCheckCircle, FaPlayCircle } from 'react-icons/fa';

export default function CourseProgressCards({ courses }) {
  const [expandedCourse, setExpandedCourse] = useState(null);

  if (!courses || courses.length === 0) {
    return (
      <div className="text-center py-8 text-gray-500">
        No courses enrolled yet
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <h3 className="text-xl font-bold text-gray-800 mb-4">📚 My Courses Progress</h3>
      
      {courses.map((course) => (
        <motion.div
          key={course.id}
          className="backdrop-blur-xl p-4 rounded-xl shadow-lg border border-gray-300"
          whileHover={{ scale: 1.02 }}
        >
          {/* Course Header */}
          <div 
            className="flex justify-between items-center cursor-pointer"
            onClick={() => setExpandedCourse(expandedCourse === course.id ? null : course.id)}
          >
            <div className="flex-1">
              <h4 className="text-lg font-semibold text-gray-800">{course.title}</h4>
              <div className="flex items-center gap-4 text-sm text-gray-600 mt-1">
                <span>{course.completed_modules}/{course.total_modules} modules</span>
                <span>•</span>
                <span>{course.completed_lessons}/{course.total_lessons} lessons</span>
                <span>•</span>
                <span>Last: {course.last_accessed ? new Date(course.last_accessed).toLocaleDateString() : 'Never'}</span>
              </div>
            </div>
            
            {/* Progress Bar */}
            <div className="flex items-center gap-4">
              <div className="w-32">
                <div className="w-full bg-gray-200 rounded-full h-2">
                  <div
                    className="bg-gradient-to-r from-primary to-accent h-2 rounded-full transition-all"
                    style={{ width: `${course.overall_completion}%` }}
                  ></div>
                </div>
                <span className="text-xs text-gray-600 mt-1">{course.overall_completion}%</span>
              </div>
              
              {expandedCourse === course.id ? (
                <FaChevronUp className="text-gray-600" />
              ) : (
                <FaChevronDown className="text-gray-600" />
              )}
            </div>
          </div>

          {/* Expanded Module View */}
          <AnimatePresence>
            {expandedCourse === course.id && (
              <motion.div
                initial={{ opacity: 0, height: 0 }}
                animate={{ opacity: 1, height: 'auto' }}
                exit={{ opacity: 0, height: 0 }}
                className="mt-4 space-y-2"
              >
                {course.modules && course.modules.map((module) => (
                  <div
                    key={module.id}
                    className="flex items-center justify-between p-3 bg-white/50 rounded-lg"
                  >
                    <div className="flex items-center gap-3">
                      {module.status === 'completed' ? (
                        <FaCheckCircle className="text-green-500" />
                      ) : module.status === 'in_progress' ? (
                        <FaPlayCircle className="text-yellow-500" />
                      ) : (
                        <div className="w-4 h-4 rounded-full border-2 border-gray-300" />
                      )}
                      <span className="font-medium">{module.title}</span>
                    </div>
                    <div className="flex items-center gap-2">
                      <span className="text-sm text-gray-600">
                        {module.lessons_completed}/{module.lessons_total} lessons
                      </span>
                      <span className="text-sm font-bold text-primary">
                        {module.completion_percentage}%
                      </span>
                    </div>
                  </div>
                ))}
              </motion.div>
            )}
          </AnimatePresence>
        </motion.div>
      ))}
    </div>
  );
}
```

### Phase 3: Create Content Lessons Tab

```jsx
// resources/js/parent/components/ProgressTracker/Tracker/Lessons/ContentLessonCard.jsx
import React from 'react';
import { motion } from 'framer-motion';
import { FaBook, FaClock, FaCheckCircle, FaSpinner } from 'react-icons/fa';

export default function ContentLessonCard({ lesson }) {
  const getStatusBadge = () => {
    switch (lesson.status) {
      case 'completed':
        return <span className="px-3 py-1 bg-green-500 text-white rounded-full text-xs font-bold flex items-center gap-1">
          <FaCheckCircle /> Completed
        </span>;
      case 'in_progress':
        return <span className="px-3 py-1 bg-yellow-500 text-white rounded-full text-xs font-bold flex items-center gap-1">
          <FaSpinner className="animate-spin" /> In Progress
        </span>;
      default:
        return <span className="px-3 py-1 bg-gray-400 text-white rounded-full text-xs font-bold">
          Not Started
        </span>;
    }
  };

  const getActionButton = () => {
    switch (lesson.status) {
      case 'completed':
        return <button className="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">
          Review Lesson
        </button>;
      case 'in_progress':
        return <button className="px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary-dark">
          Continue
        </button>;
      default:
        return <button className="px-4 py-2 bg-accent text-white rounded-lg hover:bg-accent-dark">
          Start Lesson
        </button>;
    }
  };

  return (
    <motion.div
      className="backdrop-blur-xl p-6 rounded-2xl shadow-lg border border-gray-300"
      whileHover={{ scale: 1.02 }}
    >
      {/* Header */}
      <div className="flex justify-between items-start mb-4">
        <div className="flex-1">
          <h3 className="text-xl font-semibold text-gray-800 flex items-center gap-2">
            <FaBook className="text-accent" />
            {lesson.title}
          </h3>
          {lesson.course_name && (
            <p className="text-sm text-gray-600 mt-1">
              {lesson.course_name} {lesson.module_name && `• ${lesson.module_name}`}
            </p>
          )}
        </div>
        {getStatusBadge()}
      </div>

      {/* Progress Bar */}
      <div className="mb-4">
        <div className="flex justify-between text-sm text-gray-600 mb-1">
          <span>Progress</span>
          <span className="font-bold">{lesson.completion_percentage}%</span>
        </div>
        <div className="w-full bg-gray-200 rounded-full h-3">
          <div
            className="bg-gradient-to-r from-primary to-accent h-3 rounded-full transition-all"
            style={{ width: `${lesson.completion_percentage}%` }}
          ></div>
        </div>
      </div>

      {/* Details */}
      <div className="grid grid-cols-2 gap-4 mb-4">
        <div className="flex items-center gap-2 text-sm text-gray-600">
          <span>📄 Slides:</span>
          <span className="font-medium">{lesson.slides_viewed}/{lesson.total_slides}</span>
        </div>
        <div className="flex items-center gap-2 text-sm text-gray-600">
          <span>❓ Questions:</span>
          <span className="font-medium">{lesson.questions_attempted}/{lesson.questions_total}</span>
        </div>
        <div className="flex items-center gap-2 text-sm text-gray-600">
          <FaClock />
          <span className="font-medium">{lesson.time_spent_minutes} minutes</span>
        </div>
        {lesson.last_accessed && (
          <div className="flex items-center gap-2 text-sm text-gray-600">
            <span>Last:</span>
            <span className="font-medium">
              {new Date(lesson.last_accessed).toLocaleDateString()}
            </span>
          </div>
        )}
      </div>

      {/* Action Button */}
      <div className="flex justify-end">
        {getActionButton()}
      </div>
    </motion.div>
  );
}
```

This is a comprehensive start to the implementation plan. The document is getting quite long. Would you like me to:

1. **Continue with the remaining sections** (Live Sessions, Enhanced Assessments, Analytics, Testing, Deployment)?
2. **Save this and create a second document** for the remaining parts?
3. **Focus on specific sections** you want detailed first?

For now, I've created the foundation with:
- ✅ Complete backend architecture
- ✅ Detailed TrackerController enhancement methods
- ✅ Frontend component structure
- ✅ Sample components (Course Progress, Content Lessons)

Let me know how you'd like to proceed!
