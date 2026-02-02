# Teacher Role Implementation - Phased Plan

## Overview

This document outlines the complete implementation plan for adding a dedicated "Teacher" role to the platform. The implementation is divided into 6 phases to ensure systematic and tested rollout.

**Key Decisions:**
- Teacher UI pages located under `resources/js/admin/Pages/Teacher/`
- Admin role retains all routes + permissions
- Teacher role gets selected routes with scoped data access
- Both roles share the admin layout but with different navigation items

---

## Phase 1: Foundation & Role Setup (Week 1)

### Objective
Establish the backend infrastructure for the teacher role including database, models, middleware, and permissions.

### ✅ Backend Changes

#### 1.1 Update User Model
**File:** `app/Models/User.php`

```php
// Add constant
public const ROLE_TEACHER = 'teacher';

// Add helper method
public function isTeacher(): bool
{
    return $this->role === self::ROLE_TEACHER;
}
```

#### 1.2 Create Role Middleware
**File:** `app/Http/Middleware/RoleMiddleware.php` (NEW)

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        if (!auth()->check()) {
            return redirect()->route('login');
        }
        
        // Check if user has any of the allowed roles
        foreach ($roles as $role) {
            if (auth()->user()->role === $role) {
                return $next($request);
            }
        }
        
        abort(403, 'Unauthorized access');
    }
}
```

#### 1.3 Register Middleware
**File:** `bootstrap/app.php`

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'role' => \App\Http\Middleware\RoleMiddleware::class,
    ]);
})
```

#### 1.4 Create Database Migration
**File:** `database/migrations/YYYY_MM_DD_HHMMSS_add_teacher_role_support.php` (NEW)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No schema changes needed - role column already exists
        // This migration is for documentation purposes
        
        // Optionally update existing users if needed:
        // DB::table('users')
        //   ->where('id', 'specific_user_id')
        //   ->update(['role' => 'teacher']);
    }

    public function down(): void
    {
        // Revert teacher roles to admin if needed
        // DB::table('users')
        //   ->where('role', 'teacher')
        //   ->update(['role' => 'admin']);
    }
};
```

### 📝 Files to Create/Modify

**Create:**
- `app/Http/Middleware/RoleMiddleware.php`
- Migration file for role support

**Modify:**
- `app/Models/User.php`
- `bootstrap/app.php`

### 🧪 Testing Checklist

- [ ] User model has `ROLE_TEACHER` constant
- [ ] `isTeacher()` method works correctly
- [ ] Middleware correctly blocks unauthorized roles
- [ ] Middleware allows multiple roles (e.g., `role:admin,teacher`)
- [ ] Migration runs without errors

---

## Phase 2: Core Teacher Routes & Controllers (Week 2)

### Objective
Create the teacher route file and update controllers to support role-based scoping.

### ✅ Backend Changes

#### 2.1 Create Teacher Routes File
**File:** `routes/teacher.php` (NEW)

```php
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Teacher\DashboardController;
use App\Http\Controllers\LiveLessonController;
use App\Http\Controllers\SubmissionsController;
use App\Http\Controllers\LessonUploadController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\ContentLessonController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\AttendanceController;

/*
|--------------------------------------------------------------------------
| TEACHER ROUTES
|--------------------------------------------------------------------------
| Routes accessible by users with 'teacher' role
| Teachers have scoped access to their own courses, students, and sessions
*/

Route::middleware(['auth', 'role:teacher'])->prefix('teacher')->name('teacher.')->group(function () {
    
    // Teacher Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    
    // Live Sessions Management (Teacher-specific)
    Route::prefix('live-sessions')->name('live-sessions.')->group(function () {
        Route::get('/', [LiveLessonController::class, 'teacherIndex'])->name('index');
        Route::get('/create', [LiveLessonController::class, 'create'])->name('create');
        Route::post('/', [LiveLessonController::class, 'store'])->name('store');
        Route::get('/{session}/edit', [LiveLessonController::class, 'edit'])->name('edit');
        Route::put('/{session}', [LiveLessonController::class, 'update'])->name('update');
        Route::delete('/{session}', [LiveLessonController::class, 'destroy'])->name('destroy');
        Route::post('/{session}/start', [LiveLessonController::class, 'startSession'])->name('start');
        
        // Teacher Control Panel
        Route::get('/{session}/teach', [LiveLessonController::class, 'teacherPanel'])->name('teach');
        
        // Session Control Actions
        Route::post('/{session}/change-slide', [LiveLessonController::class, 'changeSlide'])->name('change-slide');
        Route::post('/{session}/change-state', [LiveLessonController::class, 'changeState'])->name('change-state');
        Route::post('/{session}/toggle-navigation-lock', [LiveLessonController::class, 'toggleNavigationLock'])->name('toggle-navigation-lock');
        
        // Interactive Features
        Route::post('/{session}/highlight-block', [LiveLessonController::class, 'highlightBlock'])->name('highlight-block');
        Route::post('/{session}/send-annotation', [LiveLessonController::class, 'sendAnnotation'])->name('send-annotation');
        Route::post('/{session}/clear-annotations', [LiveLessonController::class, 'clearAnnotations'])->name('clear-annotations');
        
        // Participant Management
        Route::get('/{session}/participants', [LiveLessonController::class, 'getParticipants'])->name('participants');
        Route::post('/{session}/participants/{participant}/lower-hand', [LiveLessonController::class, 'lowerHand'])->name('lower-hand');
        Route::post('/{session}/participants/{participant}/mute', [LiveLessonController::class, 'muteParticipant'])->name('participants.mute');
        Route::post('/{session}/participants/mute-all', [LiveLessonController::class, 'muteAll'])->name('participants.mute-all');
        
        // Messaging & Reactions
        Route::get('/{session}/messages', [LiveLessonController::class, 'getMessages'])->name('messages');
        Route::post('/{session}/send-reaction', [LiveLessonController::class, 'sendReaction'])->name('send-reaction');
        
        // LiveKit Token
        Route::get('/{session}/livekit-token', [LiveLessonController::class, 'getLiveKitToken'])->name('livekit-token');
    });
    
    // My Courses (Scoped)
    Route::prefix('courses')->name('courses.')->group(function () {
        Route::get('/', [CourseController::class, 'teacherIndex'])->name('index');
        Route::get('/{course}', [CourseController::class, 'show'])->name('show');
    });
    
    // Content Lessons (Scoped - can create/edit own lessons)
    Route::prefix('content-lessons')->name('content-lessons.')->group(function () {
        Route::get('/', [ContentLessonController::class, 'teacherIndex'])->name('index');
        Route::get('/create', [ContentLessonController::class, 'create'])->name('create');
        Route::post('/', [ContentLessonController::class, 'storeAdmin'])->name('store');
        Route::get('/{lesson}/edit', [ContentLessonController::class, 'edit'])->name('edit');
        Route::get('/{lesson}/slides', [ContentLessonController::class, 'edit'])->name('slides.edit');
        Route::put('/{lesson}', [ContentLessonController::class, 'update'])->name('update');
    });
    
    // Assessments (Scoped - can create/edit own assessments)
    Route::prefix('assessments')->name('assessments.')->group(function () {
        Route::get('/', [AssessmentController::class, 'teacherIndex'])->name('index');
        Route::get('/create', [AssessmentController::class, 'create'])->name('create');
        Route::post('/', [AssessmentController::class, 'store'])->name('store');
        Route::get('/{assessment}/edit', [AssessmentController::class, 'edit'])->name('edit');
        Route::put('/{assessment}', [AssessmentController::class, 'update'])->name('update');
    });
    
    // Grading Queue
    Route::prefix('submissions')->name('submissions.')->group(function () {
        Route::get('/', [SubmissionsController::class, 'teacherIndex'])->name('index');
        Route::get('/{submission}/grade', [SubmissionsController::class, 'edit'])->name('grade');
        Route::get('/{submission}', [SubmissionsController::class, 'teacherShow'])->name('show');
        Route::patch('/{submission}', [SubmissionsController::class, 'update'])->name('update');
    });
    
    // Lesson Uploads Review
    Route::prefix('lesson-uploads')->name('lesson-uploads.')->group(function () {
        Route::get('/pending', [LessonUploadController::class, 'teacherPending'])->name('pending');
        Route::get('/{upload}', [LessonUploadController::class, 'show'])->name('show');
        Route::post('/{upload}/grade', [LessonUploadController::class, 'grade'])->name('grade');
        Route::post('/{upload}/feedback', [LessonUploadController::class, 'submitFeedback'])->name('feedback');
    });
    
    // Students (Scoped - only students in teacher's courses)
    Route::get('/students', [DashboardController::class, 'myStudents'])->name('students.index');
    Route::get('/students/{child}', [DashboardController::class, 'studentDetail'])->name('students.show');
    
    // Attendance (Scoped - only for teacher's lessons)
    Route::prefix('attendance')->name('attendance.')->group(function () {
        Route::get('/', [AttendanceController::class, 'teacherOverview'])->name('overview');
        Route::get('/lesson/{lesson}', [AttendanceController::class, 'sheet'])->name('sheet');
        Route::post('/lessons/{lesson}/attendance/mark-all', [AttendanceController::class, 'markAll'])->name('markAll');
        Route::post('/lessons/{lesson}/attendance/approve-all', [AttendanceController::class, 'approveAll'])->name('approveAll');
    });
});
```

#### 2.2 Update web.php to Include Teacher Routes
**File:** `routes/web.php`

```php
<?php

require __DIR__.'/public.php';
require __DIR__.'/admin.php';
require __DIR__.'/teacher.php';  // ← Add this line
require __DIR__.'/parent.php';
```

#### 2.3 Add Model Scopes
**File:** `app/Models/LiveLessonSession.php`

```php
// Add scope for teacher-specific queries
public function scopeForTeacher($query, $teacherId)
{
    return $query->where('teacher_id', $teacherId);
}
```

**File:** `app/Models/Course.php`

```php
// Add scope for teacher-specific queries
public function scopeForTeacher($query, $teacherId)
{
    return $query->where('teacher_id', $teacherId)
                 ->orWhereHas('liveLessonSessions', function($q) use ($teacherId) {
                     $q->where('teacher_id', $teacherId);
                 });
}
```

**File:** `app/Models/Assessment.php`

```php
// Add scope for teacher-specific queries
public function scopeForTeacher($query, $teacherId)
{
    return $query->where('created_by', $teacherId);
}
```

**File:** `app/Models/ContentLesson.php`

```php
// Add scope for teacher-specific queries
public function scopeForTeacher($query, $teacherId)
{
    return $query->where('created_by', $teacherId);
}
```

#### 2.4 Create Teacher Dashboard Controller
**File:** `app/Http/Controllers/Teacher/DashboardController.php` (NEW)

```php
<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\LiveLessonSession;
use App\Models\Child;
use App\Models\AssessmentSubmission;
use App\Models\LessonUpload;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        $teacherId = auth()->id();
        
        // Get teacher's upcoming sessions
        $upcomingSessions = LiveLessonSession::forTeacher($teacherId)
            ->where('status', 'scheduled')
            ->where('scheduled_start_time', '>', now())
            ->orderBy('scheduled_start_time')
            ->limit(5)
            ->with(['lesson', 'course'])
            ->get();
        
        // Get active sessions
        $activeSessions = LiveLessonSession::forTeacher($teacherId)
            ->where('status', 'live')
            ->with(['lesson', 'participants'])
            ->get();
        
        // Get pending submissions count
        $pendingSubmissions = AssessmentSubmission::whereHas('assessment', function($q) use ($teacherId) {
            $q->where('created_by', $teacherId);
        })->where('status', 'submitted')->count();
        
        // Get pending uploads count
        $pendingUploads = LessonUpload::whereHas('lesson.liveLessonSessions', function($q) use ($teacherId) {
            $q->where('teacher_id', $teacherId);
        })->where('status', 'pending')->count();
        
        // Get total students (from courses)
        $totalStudents = Child::whereHas('courses', function($q) use ($teacherId) {
            $q->forTeacher($teacherId);
        })->distinct()->count();
        
        return Inertia::render('admin/Pages/Teacher/Dashboard/TeacherDashboard', [
            'upcomingSessions' => $upcomingSessions,
            'activeSessions' => $activeSessions,
            'pendingSubmissions' => $pendingSubmissions,
            'pendingUploads' => $pendingUploads,
            'totalStudents' => $totalStudents,
        ]);
    }
    
    public function myStudents()
    {
        $teacherId = auth()->id();
        
        $students = Child::whereHas('courses', function($q) use ($teacherId) {
            $q->forTeacher($teacherId);
        })->with(['user', 'courses' => function($q) use ($teacherId) {
            $q->forTeacher($teacherId);
        }])->paginate(20);
        
        return Inertia::render('admin/Pages/Teacher/Students/Index', [
            'students' => $students
        ]);
    }
    
    public function studentDetail($childId)
    {
        $teacherId = auth()->id();
        
        $student = Child::whereHas('courses', function($q) use ($teacherId) {
            $q->forTeacher($teacherId);
        })->with([
            'user',
            'courses' => function($q) use ($teacherId) {
                $q->forTeacher($teacherId);
            },
            'assessmentSubmissions',
            'lessonProgress'
        ])->findOrFail($childId);
        
        return Inertia::render('admin/Pages/Teacher/Students/Show', [
            'student' => $student
        ]);
    }
}
```

### 📝 Files to Create/Modify

**Create:**
- `routes/teacher.php`
- `app/Http/Controllers/Teacher/DashboardController.php`

**Modify:**
- `routes/web.php`
- `app/Models/LiveLessonSession.php`
- `app/Models/Course.php`
- `app/Models/Assessment.php`
- `app/Models/ContentLesson.php`

### 🧪 Testing Checklist

- [ ] Teacher routes file loads without errors
- [ ] Middleware correctly restricts teacher routes
- [ ] Model scopes return only teacher's data
- [ ] Dashboard controller returns correct data
- [ ] Teacher cannot access admin-only routes

---

## Phase 3: Teacher Dashboard & UI Foundation (Week 3)

### Objective
Create the teacher dashboard interface and basic UI components.

### 🎨 Frontend Changes

#### 3.1 Create Teacher Dashboard
**File:** `resources/js/admin/Pages/Teacher/Dashboard/TeacherDashboard.jsx` (NEW)

```jsx
import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AdminPortalLayout from '@/admin/Layouts/AdminPortalLayout';
import { motion } from 'framer-motion';
import {
    AcademicCapIcon,
    ClipboardDocumentCheckIcon,
    UserGroupIcon,
    CalendarDaysIcon,
    ChartBarIcon
} from '@heroicons/react/24/outline';

const StatCard = ({ title, value, icon: Icon, link, color = 'primary' }) => (
    <Link href={link}>
        <motion.div
            whileHover={{ scale: 1.02, y: -5 }}
            className={`bg-white rounded-xl p-6 shadow-md hover:shadow-xl transition-all border-l-4 border-${color}`}
        >
            <div className="flex items-center justify-between">
                <div>
                    <p className="text-sm text-gray-600 mb-1">{title}</p>
                    <p className="text-3xl font-bold text-gray-800">{value}</p>
                </div>
                <div className={`p-3 bg-${color}/10 rounded-lg`}>
                    <Icon className={`h-8 w-8 text-${color}`} />
                </div>
            </div>
        </motion.div>
    </Link>
);

const TeacherDashboard = ({ upcomingSessions, activeSessions, pendingSubmissions, pendingUploads, totalStudents }) => {
    return (
        <>
            <Head title="Teacher Dashboard" />
            
            <div className="min-h-screen bg-gray-50 px-6 md:px-20 py-12">
                <header className="mb-12">
                    <h1 className="text-4xl font-bold text-primary">Teacher Dashboard</h1>
                    <p className="text-gray-600 mt-2">Manage your classes and students</p>
                </header>
                
                {/* Stats Grid */}
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-12">
                    <StatCard
                        title="Active Sessions"
                        value={activeSessions.length}
                        icon={AcademicCapIcon}
                        link={route('teacher.live-sessions.index')}
                        color="green-500"
                    />
                    <StatCard
                        title="Pending Grading"
                        value={pendingSubmissions + pendingUploads}
                        icon={ClipboardDocumentCheckIcon}
                        link={route('teacher.submissions.index')}
                        color="orange-500"
                    />
                    <StatCard
                        title="Total Students"
                        value={totalStudents}
                        icon={UserGroupIcon}
                        link={route('teacher.students.index')}
                        color="blue-500"
                    />
                    <StatCard
                        title="Upcoming Sessions"
                        value={upcomingSessions.length}
                        icon={CalendarDaysIcon}
                        link={route('teacher.live-sessions.index')}
                        color="purple-500"
                    />
                </div>
                
                {/* Active Sessions */}
                {activeSessions.length > 0 && (
                    <div className="bg-white rounded-xl p-6 shadow-md mb-8">
                        <h2 className="text-2xl font-bold text-gray-800 mb-4">Active Live Sessions</h2>
                        <div className="space-y-4">
                            {activeSessions.map(session => (
                                <div key={session.id} className="flex items-center justify-between p-4 bg-green-50 rounded-lg border border-green-200">
                                    <div>
                                        <h3 className="font-semibold text-gray-800">{session.lesson?.title || 'Live Session'}</h3>
                                        <p className="text-sm text-gray-600">{session.participants.length} participants</p>
                                    </div>
                                    <Link
                                        href={route('teacher.live-sessions.teach', session.id)}
                                        className="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600"
                                    >
                                        Join Session
                                    </Link>
                                </div>
                            ))}
                        </div>
                    </div>
                )}
                
                {/* Upcoming Sessions */}
                <div className="bg-white rounded-xl p-6 shadow-md">
                    <h2 className="text-2xl font-bold text-gray-800 mb-4">Upcoming Sessions</h2>
                    {upcomingSessions.length > 0 ? (
                        <div className="space-y-3">
                            {upcomingSessions.map(session => (
                                <div key={session.id} className="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                    <div>
                                        <h3 className="font-semibold text-gray-800">{session.lesson?.title || 'Session'}</h3>
                                        <p className="text-sm text-gray-600">
                                            {new Date(session.scheduled_start_time).toLocaleString()}
                                        </p>
                                    </div>
                                    <Link
                                        href={route('teacher.live-sessions.edit', session.id)}
                                        className="px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary/90"
                                    >
                                        Manage
                                    </Link>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="text-gray-500">No upcoming sessions</p>
                    )}
                </div>
            </div>
        </>
    );
};

TeacherDashboard.layout = page => <AdminPortalLayout>{page}</AdminPortalLayout>;
export default TeacherDashboard;
```

#### 3.2 Update Admin Layout Navigation
**File:** `resources/js/admin/Layouts/AdminPortalLayout.jsx`

Update the navigation to show different items based on role:

```jsx
// Add role-based navigation
const { auth } = usePage().props;
const isAdmin = auth.user.role === 'admin';
const isTeacher = auth.user.role === 'teacher';

// Define navigation items
const navigationItems = isTeacher ? [
    { name: 'Dashboard', href: route('teacher.dashboard'), icon: HomeIcon },
    { name: 'Live Sessions', href: route('teacher.live-sessions.index'), icon: VideoCameraIcon },
    { name: 'My Courses', href: route('teacher.courses.index'), icon: BookOpenIcon },
    { name: 'Grading Queue', href: route('teacher.submissions.index'), icon: ClipboardIcon },
    { name: 'My Students', href: route('teacher.students.index'), icon: UserGroupIcon },
    { name: 'Content Lessons', href: route('teacher.content-lessons.index'), icon: DocumentTextIcon },
    { name: 'Assessments', href: route('teacher.assessments.index'), icon: CheckCircleIcon },
] : [
    // Admin navigation items (existing)
    { name: 'Dashboard', href: route('admin.dashboard'), icon: HomeIcon },
    // ... rest of admin navigation
];
```

### 📝 Files to Create/Modify

**Create:**
- `resources/js/admin/Pages/Teacher/Dashboard/TeacherDashboard.jsx`
- `resources/js/admin/Pages/Teacher/Students/Index.jsx`
- `resources/js/admin/Pages/Teacher/Students/Show.jsx`

**Modify:**
- `resources/js/admin/Layouts/AdminPortalLayout.jsx`

### 🧪 Testing Checklist

- [ ] Teacher dashboard renders correctly
- [ ] Stats display accurate data
- [ ] Navigation shows teacher-specific items
- [ ] Links navigate to correct routes
- [ ] Active sessions display properly

---

## Phase 4: Grading & Student Management (Week 4)

### Objective
Implement teacher-specific grading interfaces and student management features.

### ✅ Backend Changes

#### 4.1 Update SubmissionsController
**File:** `app/Http/Controllers/SubmissionsController.php`

```php
// Add teacher-specific methods
public function teacherIndex()
{
    $teacherId = auth()->id();
    
    $submissions = AssessmentSubmission::whereHas('assessment', function($q) use ($teacherId) {
        $q->where('created_by', $teacherId);
    })
    ->with(['child.user', 'assessment'])
    ->orderBy('submitted_at', 'desc')
    ->paginate(20);
    
    return inertia('admin/Pages/Teacher/Submissions/Index', [
        'submissions' => $submissions
    ]);
}

public function teacherShow($id)
{
    $teacherId = auth()->id();
    
    $submission = AssessmentSubmission::whereHas('assessment', function($q) use ($teacherId) {
        $q->where('created_by', $teacherId);
    })
    ->with(['child.user', 'assessment', 'items.question'])
    ->findOrFail($id);
    
    return inertia('admin/Pages/Teacher/Submissions/Show', [
        'submission' => $submission
    ]);
}
```

#### 4.2 Update LessonUploadController
**File:** `app/Http/Controllers/LessonUploadController.php`

```php
// Add teacher-specific methods
public function teacherPending()
{
    $teacherId = auth()->id();
    
    $uploads = LessonUpload::whereHas('lesson.liveLessonSessions', function($q) use ($teacherId) {
        $q->where('teacher_id', $teacherId);
    })
    ->where('status', 'pending')
    ->with(['child.user', 'lesson', 'slide'])
    ->orderBy('created_at', 'desc')
    ->paginate(20);
    
    return inertia('admin/Pages/Teacher/Uploads/Index', [
        'uploads' => $uploads
    ]);
}
```

### 🎨 Frontend Changes

#### 4.3 Create Submissions Index Page
**File:** `resources/js/admin/Pages/Teacher/Submissions/Index.jsx` (NEW)

```jsx
import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AdminPortalLayout from '@/admin/Layouts/AdminPortalLayout';
import { ClipboardDocumentCheckIcon } from '@heroicons/react/24/outline';

const SubmissionsIndex = ({ submissions }) => {
    const [filter, setFilter] = useState('all');
    
    const getStatusBadge = (status) => {
        const colors = {
            submitted: 'bg-yellow-100 text-yellow-800',
            graded: 'bg-green-100 text-green-800',
            returned: 'bg-blue-100 text-blue-800',
        };
        
        return (
            <span className={`px-3 py-1 rounded-full text-sm font-medium ${colors[status] || 'bg-gray-100 text-gray-800'}`}>
                {status}
            </span>
        );
    };
    
    return (
        <>
            <Head title="Grading Queue" />
            
            <div className="min-h-screen bg-gray-50 px-6 md:px-20 py-12">
                <header className="mb-8">
                    <h1 className="text-4xl font-bold text-primary">Grading Queue</h1>
                    <p className="text-gray-600 mt-2">Review and grade student submissions</p>
                </header>
                
                {/* Filters */}
                <div className="mb-6 flex gap-3">
                    {['all', 'submitted', 'graded'].map(status => (
                        <button
                            key={status}
                            onClick={() => setFilter(status)}
                            className={`px-4 py-2 rounded-lg font-medium transition-colors ${
                                filter === status
                                    ? 'bg-primary text-white'
                                    : 'bg-white text-gray-700 hover:bg-gray-100'
                            }`}
                        >
                            {status.charAt(0).toUpperCase() + status.slice(1)}
                        </button>
                    ))}
                </div>
                
                {/* Submissions List */}
                <div className="bg-white rounded-xl shadow-md overflow-hidden">
                    <table className="w-full">
                        <thead className="bg-gray-50 border-b">
                            <tr>
                                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-700">Student</th>
                                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-700">Assessment</th>
                                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-700">Submitted</th>
                                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-700">Status</th>
                                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-700">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-200">
                            {submissions.data
                                .filter(sub => filter === 'all' || sub.status === filter)
                                .map(submission => (
                                    <tr key={submission.id} className="hover:bg-gray-50">
                                        <td className="px-6 py-4">
                                            <div>
                                                <p className="font-medium text-gray-900">{submission.child.user.name}</p>
                                                <p className="text-sm text-gray-500">{submission.child.user.email}</p>
                                            </div>
                                        </td>
                                        <td className="px-6 py-4 text-gray-700">{submission.assessment.title}</td>
                                        <td className="px-6 py-4 text-sm text-gray-600">
                                            {new Date(submission.submitted_at).toLocaleDateString()}
                                        </td>
                                        <td className="px-6 py-4">{getStatusBadge(submission.status)}</td>
                                        <td className="px-6 py-4">
                                            <Link
                                                href={route('teacher.submissions.grade', submission.id)}
                                                className="inline-flex items-center px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary/90"
                                            >
                                                {submission.status === 'submitted' ? 'Grade' : 'Review'}
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
};

SubmissionsIndex.layout = page => <AdminPortalLayout>{page}</AdminPortalLayout>;
export default SubmissionsIndex;
```

### 📝 Files to Create/Modify

**Create:**
- `resources/js/admin/Pages/Teacher/Submissions/Index.jsx`
- `resources/js/admin/Pages/Teacher/Submissions/Show.jsx`
- `resources/js/admin/Pages/Teacher/Uploads/Index.jsx`

**Modify:**
- `app/Http/Controllers/SubmissionsController.php`
- `app/Http/Controllers/LessonUploadController.php`

### 🧪 Testing Checklist

- [ ] Grading queue shows only teacher's submissions
- [ ] Filter functionality works correctly
- [ ] Status badges display properly
- [ ] Grading interface accessible
- [ ] Data scoping prevents access to other teachers' data

---

## Phase 5: Analytics & Course Management (Week 5)

### Objective
Add analytics dashboard and course/lesson management for teachers.

### ✅ Backend Changes

#### 5.1 Update Controllers
**File:** `app/Http/Controllers/CourseController.php`

```php
public function teacherIndex()
{
    $teacherId = auth()->id();
    
    $courses = Course::forTeacher($teacherId)
        ->with(['modules.contentLessons', 'liveLessonSessions'])
        ->withCount('students')
        ->paginate(12);
    
    return inertia('admin/Pages/Teacher/Courses/Index', [
        'courses' => $courses
    ]);
}
```

**File:** `app/Http/Controllers/ContentLessonController.php`

```php
public function teacherIndex()
{
    $teacherId = auth()->id();
    
    $lessons = ContentLesson::forTeacher($teacherId)
        ->with(['slides'])
        ->withCount('completions')
        ->orderBy('created_at', 'desc')
        ->paginate(15);
    
    return inertia('admin/Pages/Teacher/ContentLessons/Index', [
        'lessons' => $lessons
    ]);
}
```

### 🎨 Frontend Changes

#### 5.2 Create Courses Index
**File:** `resources/js/admin/Pages/Teacher/Courses/Index.jsx` (NEW)

```jsx
import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AdminPortalLayout from '@/admin/Layouts/AdminPortalLayout';
import { BookOpenIcon, UserGroupIcon, AcademicCapIcon } from '@heroicons/react/24/outline';

const CoursesIndex = ({ courses }) => {
    return (
        <>
            <Head title="My Courses" />
            
            <div className="min-h-screen bg-gray-50 px-6 md:px-20 py-12">
                <header className="mb-8 flex justify-between items-center">
                    <div>
                        <h1 className="text-4xl font-bold text-primary">My Courses</h1>
                        <p className="text-gray-600 mt-2">Manage your teaching courses</p>
                    </div>
                </header>
                
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    {courses.data.map(course => (
                        <Link
                            key={course.id}
                            href={route('teacher.courses.show', course.id)}
                            className="bg-white rounded-xl p-6 shadow-md hover:shadow-xl transition-all"
                        >
                            <div className="flex items-center gap-4 mb-4">
                                <div className="p-3 bg-primary/10 rounded-lg">
                                    <BookOpenIcon className="h-8 w-8 text-primary" />
                                </div>
                                <div>
                                    <h3 className="font-bold text-lg text-gray-900">{course.title}</h3>
                                    <p className="text-sm text-gray-500">{course.category}</p>
                                </div>
                            </div>
                            
                            <p className="text-gray-600 text-sm mb-4 line-clamp-2">
                                {course.description}
                            </p>
                            
                            <div className="flex items-center gap-4 text-sm text-gray-500">
                                <div className="flex items-center gap-1">
                                    <UserGroupIcon className="h-4 w-4" />
                                    <span>{course.students_count} students</span>
                                </div>
                                <div className="flex items-center gap-1">
                                    <AcademicCapIcon className="h-4 w-4" />
                                    <span>{course.modules.length} modules</span>
                                </div>
                            </div>
                        </Link>
                    ))}
                </div>
            </div>
        </>
    );
};

CoursesIndex.layout = page => <AdminPortalLayout>{page}</AdminPortalLayout>;
export default CoursesIndex;
```

### 📝 Files to Create/Modify

**Create:**
- `resources/js/admin/Pages/Teacher/Courses/Index.jsx`
- `resources/js/admin/Pages/Teacher/ContentLessons/Index.jsx`
- `resources/js/admin/Pages/Teacher/Analytics/Dashboard.jsx`

**Modify:**
- `app/Http/Controllers/CourseController.php`
- `app/Http/Controllers/ContentLessonController.php`

### 🧪 Testing Checklist

- [ ] Courses display only teacher's courses
- [ ] Student count accurate
- [ ] Content lessons properly scoped
- [ ] Analytics show relevant data
- [ ] Performance metrics calculate correctly

---

## Phase 6: Testing, Polish & Documentation (Week 6)

### Objective
Comprehensive testing, UI refinements, and complete documentation.

### 🧪 Testing Tasks

#### 6.1 Permission Testing
- [ ] Teacher cannot access admin-only routes
- [ ] Teacher can only see their own data
- [ ] Teacher can grade only their students' submissions
- [ ] Teacher can manage only their live sessions
- [ ] Admin retains full access to all routes

#### 6.2 Data Scoping Testing
- [ ] Courses query returns only teacher's courses
- [ ] Students query returns only students in teacher's courses
- [ ] Submissions query returns only from teacher's assessments
- [ ] Live sessions query returns only teacher's sessions

#### 6.3 UI/UX Testing
- [ ] Navigation items show correctly based on role
- [ ] Dashboard displays accurate statistics
- [ ] Forms work correctly for teachers
- [ ] Responsive design on mobile/tablet
- [ ] Error messages display properly

### 📝 Documentation Tasks

#### 6.4 Create User Guide
**File:** `docs/TEACHER_PORTAL_USER_GUIDE.md` (NEW)

- Teacher login process
- Dashboard overview
- Managing live sessions
- Grading workflows
- Student management
- Creating content

#### 6.5 Update Route Documentation
**File:** `docs/ROUTE_PERMISSIONS_MATRIX.md` (NEW)

Create a matrix showing:
- Route path
- Admin access (Yes/No)
- Teacher access (Yes/No)
- Scoping rules

### ⚠️ Important Notes

1. **Existing Data**: Current admins who are teaching should be manually converted to teacher role via database update
2. **Organization Context**: Teacher scoping works within organization boundaries
3. **Shared Resources**: Some resources (Question Bank, Content Lessons) are shared but with created_by filtering
4. **Backward Compatibility**: Admin routes remain unchanged for admins

---

## Migration Strategy

### For Existing Admins Who Teach

```sql
-- Identify admins who are actively teaching
SELECT u.id, u.name, u.email, COUNT(lls.id) as session_count
FROM users u
INNER JOIN live_lesson_sessions lls ON lls.teacher_id = u.id
WHERE u.role = 'admin'
GROUP BY u.id, u.name, u.email
HAVING COUNT(lls.id) > 0;

-- Convert to teacher role (run individually after review)
UPDATE users SET role = 'teacher' WHERE id = ?;
```

### For New Teachers

Admins can create new users with teacher role via existing teacher management interface.

---

## Post-Implementation Checklist

- [ ] All phases completed
- [ ] Migration script tested
- [ ] Documentation complete
- [ ] User guide published
- [ ] Training materials created
- [ ] Beta testing with select teachers
- [ ] Feedback incorporated
- [ ] Production deployment plan ready

---

## Support & Maintenance

### Known Limitations
1. Teachers cannot create organizations
2. Teachers cannot manage subscriptions
3. Teachers cannot access platform analytics

### Future Enhancements
- Teacher performance analytics
- Peer collaboration features
- Advanced grading tools
- Custom report generation
- Mobile app for teachers

---

## Rollback Plan

If issues arise:

1. **Database**: Revert teacher roles to admin
2. **Routes**: Comment out `require __DIR__.'/teacher.php';` in web.php
3. **Navigation**: Restore original navigation logic
4. **Deploy**: Use previous stable commit

---

**Document Version:** 1.0  
**Last Updated:** [Current Date]  
**Authors:** Development Team  
**Status:** Ready for Implementation
