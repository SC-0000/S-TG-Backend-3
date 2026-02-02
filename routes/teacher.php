<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Teacher\DashboardController;
use App\Http\Controllers\LiveLessonController;
use App\Http\Controllers\LessonController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\ContentLessonController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\SubmissionsController;
use App\Http\Controllers\LessonUploadController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\JourneyController;
use App\Http\Controllers\JourneyCategoryController;

/*
|--------------------------------------------------------------------------
| TEACHER ROUTES
|--------------------------------------------------------------------------
| Routes accessible by users with 'teacher' role
| Teachers have scoped access to their own courses, students, and sessions
*/

Route::middleware(['auth', 'role:teacher,admin'])->prefix('teacher')->name('teacher.')->group(function () {
    
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
         Route::get('/{session}/teacher-panel', [App\Http\Controllers\LiveLessonController::class, 'teacherPanel'])->name('teacher-panel');
        
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
    
    // Traditional Lessons Management (CRUD)
    Route::resource('lessons', LessonController::class)->except(['index', 'show']);
    Route::get('/lessons', [LessonController::class, 'teacherIndex'])->name('lessons.index');
    Route::get('/lessons/{lesson}', [LessonController::class, 'show'])->name('lessons.show');
    Route::get('/assigned-lessons', [LessonController::class, 'teacherAssignedLessons'])->name('lessons.assigned');
    
    // Course Management (Full CRUD for teachers)
    Route::prefix('courses')->name('courses.')->group(function () {
        Route::get('/', [CourseController::class, 'index'])->name('index');
        Route::get('/create', [CourseController::class, 'create'])->name('create');
        Route::post('/', [CourseController::class, 'store'])->name('store');
        Route::get('/{course}', [CourseController::class, 'show'])->name('show');
        Route::get('/{course}/edit', [CourseController::class, 'edit'])->name('edit');
        Route::put('/{course}', [CourseController::class, 'update'])->name('update');
        Route::delete('/{course}', [CourseController::class, 'destroy'])->name('destroy');

        // Course actions
        Route::post('/{course}/publish', [CourseController::class, 'publish'])->name('publish');
        Route::post('/{course}/archive', [CourseController::class, 'archive'])->name('archive');
        Route::post('/{course}/duplicate', [CourseController::class, 'duplicate'])->name('duplicate');

        // Modules within course
        Route::prefix('/{course}/modules')->name('modules.')->group(function () {
            Route::get('/', [App\Http\Controllers\ModuleController::class, 'index'])->name('index');
            Route::post('/', [App\Http\Controllers\ModuleController::class, 'store'])->name('store');
            Route::post('/reorder', [App\Http\Controllers\ModuleController::class, 'reorder'])->name('reorder');
        });
    });
    
    // Module Management
    Route::prefix('modules')->name('modules.')->group(function () {
        Route::get('/{module}', [App\Http\Controllers\ModuleController::class, 'show'])->name('show');
        Route::put('/{module}', [App\Http\Controllers\ModuleController::class, 'update'])->name('update');
        Route::delete('/{module}', [App\Http\Controllers\ModuleController::class, 'destroy'])->name('destroy');
        Route::post('/{module}/publish', [App\Http\Controllers\ModuleController::class, 'publish'])->name('publish');
        Route::post('/{module}/duplicate', [App\Http\Controllers\ModuleController::class, 'duplicate'])->name('duplicate');

        // Attach/Detach Lessons
        Route::post('/{module}/lessons/attach', [App\Http\Controllers\ModuleController::class, 'attachLesson'])->name('lessons.attach');
        Route::delete('/{module}/lessons/{lesson}/detach', [App\Http\Controllers\ModuleController::class, 'detachLesson'])->name('lessons.detach');

        // Attach/Detach Assessments
        Route::post('/{module}/assessments/attach', [App\Http\Controllers\ModuleController::class, 'attachAssessment'])->name('assessments.attach');
        Route::delete('/{module}/assessments/{assessment}/detach', [App\Http\Controllers\ModuleController::class, 'detachAssessment'])->name('assessments.detach');

        // Lessons within module
        Route::prefix('/{module}/lessons')->name('lessons.')->group(function () {
            Route::get('/', [App\Http\Controllers\ContentLessonController::class, 'index'])->name('index');
            Route::post('/', [App\Http\Controllers\ModuleController::class, 'storeLesson'])->name('store');
            Route::post('/reorder', [App\Http\Controllers\ContentLessonController::class, 'reorder'])->name('reorder');
        });
    });
    
    // Content Lessons (Scoped - can create/edit own lessons)
    Route::prefix('content-lessons')->name('content-lessons.')->group(function () {
        Route::get('/', [ContentLessonController::class, 'teacherIndex'])->name('index');
        Route::get('/create', [ContentLessonController::class, 'create'])->name('create');
        Route::post('/', [ContentLessonController::class, 'storeAdmin'])->name('store');
        
        Route::get('/{lesson}', [ContentLessonController::class, 'show'])->name('show');
        
        // Metadata edit
        Route::get('/{lesson}/edit', [ContentLessonController::class, 'edit'])->name('edit');
        Route::get('/{lesson}/editform', [ContentLessonController::class, 'editForm'])->name('editForm');
        
        // Slide editor
        Route::get('/{lesson}/slides', [ContentLessonController::class, 'edit'])->name('slides.edit');
        
        Route::put('/{lesson}', [ContentLessonController::class, 'update'])->name('update');
        Route::delete('/{lesson}', [ContentLessonController::class, 'destroy'])->name('destroy');
        Route::post('/{lesson}/publish', [ContentLessonController::class, 'publish'])->name('publish');
        Route::post('/{lesson}/duplicate', [ContentLessonController::class, 'duplicate'])->name('duplicate');
        
        // Assessment management
        Route::post('/{lesson}/assessments/{assessment}/attach', [ContentLessonController::class, 'attachAssessment'])->name('assessments.attach');
        Route::delete('/{lesson}/assessments/{assessment}/detach', [ContentLessonController::class, 'detachAssessment'])->name('assessments.detach');
        
        // Slides within lesson
        Route::prefix('/{lesson}/slides')->name('slides.')->group(function () {
            Route::get('/', [App\Http\Controllers\LessonSlideController::class, 'index'])->name('index');
            Route::post('/', [App\Http\Controllers\LessonSlideController::class, 'store'])->name('store');
            Route::post('/reorder', [App\Http\Controllers\LessonSlideController::class, 'reorder'])->name('reorder');
        });
    });

// Teacher revenue dashboard
    Route::get('/revenue', [\App\Http\Controllers\Teacher\RevenueController::class, 'index'])
        ->middleware('feature:teacher.revenue_dashboard')
        ->name('revenue.index');

    // Lesson Slide Management (teacher)
    Route::prefix('lesson-slides')->name('lesson-slides.')->group(function () {
        Route::get('/{slide}', [App\Http\Controllers\LessonSlideController::class, 'show'])->name('show');
        Route::put('/{slide}', [App\Http\Controllers\LessonSlideController::class, 'update'])->name('update');
        Route::delete('/{slide}', [App\Http\Controllers\LessonSlideController::class, 'destroy'])->name('destroy');
        Route::post('/{slide}/duplicate', [App\Http\Controllers\LessonSlideController::class, 'duplicate'])->name('duplicate');

        // Block management
        Route::post('/{slide}/blocks', [App\Http\Controllers\LessonSlideController::class, 'addBlock'])->name('blocks.add');
        Route::put('/{slide}/blocks/{blockId}', [App\Http\Controllers\LessonSlideController::class, 'updateBlock'])->name('blocks.update');
        Route::delete('/{slide}/blocks/{blockId}', [App\Http\Controllers\LessonSlideController::class, 'deleteBlock'])->name('blocks.delete');
    });
    
    // Assessments (Scoped - can create/edit own assessments)
    Route::prefix('assessments')->name('assessments.')->group(function () {
        Route::get('/', [AssessmentController::class, 'teacherIndex'])->name('index');
        Route::get('/create', [AssessmentController::class, 'create'])->name('create');
        Route::post('/', [AssessmentController::class, 'store'])->name('store');
        Route::get('/{assessment}', [AssessmentController::class, 'show'])->name('show');
        Route::get('/{assessment}/edit', [AssessmentController::class, 'edit'])->name('edit');
        Route::put('/{assessment}', [AssessmentController::class, 'update'])->name('update');
        Route::delete('/{assessment}', [AssessmentController::class, 'destroy'])->name('destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | AI CONTENT CREATOR ROUTES (Teacher)
    |--------------------------------------------------------------------------
    */
    Route::prefix('ai-upload')->name('ai-upload.')->group(function () {
        Route::get('/', [App\Http\Controllers\Admin\AIUploadController::class, 'index'])->name('index');
        Route::post('/', [App\Http\Controllers\Admin\AIUploadController::class, 'store'])->name('store');
        Route::get('/{session}', [App\Http\Controllers\Admin\AIUploadController::class, 'show'])->name('show');
        Route::post('/{session}/cancel', [App\Http\Controllers\Admin\AIUploadController::class, 'cancel'])->name('cancel');
        Route::delete('/{session}', [App\Http\Controllers\Admin\AIUploadController::class, 'destroy'])->name('destroy');
        Route::get('/{session}/logs', [App\Http\Controllers\Admin\AIUploadController::class, 'logs'])->name('logs');
        Route::put('/proposals/{proposal}', [App\Http\Controllers\Admin\AIUploadController::class, 'updateProposal'])->name('proposals.update');
        Route::post('/proposals/{proposal}/refine', [App\Http\Controllers\Admin\AIUploadController::class, 'refineProposal'])->name('proposals.refine');
        Route::post('/{session}/approve', [App\Http\Controllers\Admin\AIUploadController::class, 'approveProposals'])->name('approve');
        Route::post('/{session}/reject', [App\Http\Controllers\Admin\AIUploadController::class, 'rejectProposals'])->name('reject');
        Route::post('/{session}/upload', [App\Http\Controllers\Admin\AIUploadController::class, 'upload'])->name('upload');
    });
    
    // Submissions grading (teacher view)
    Route::get('/submissions/{submission}/grade', [SubmissionsController::class, 'edit'])
        ->name('submissions.grade');
    // Keep-alive for assessment builder (match admin route)
    Route::get('/assessments/keep-alive', [AssessmentController::class, 'keepAlive'])->name('assessments.keepAlive');
    
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
    
    // Question Bank Management (Teacher can create/manage questions for assessments)
    Route::prefix('questions')->name('questions.')->group(function () {
        Route::get('/', [QuestionController::class, 'index'])->name('index');
        Route::get('/create', [QuestionController::class, 'create'])->name('create');
        Route::post('/', [QuestionController::class, 'store'])->name('store');
        Route::get('/{question}', [QuestionController::class, 'show'])->name('show');
        Route::get('/{question}/edit', [QuestionController::class, 'edit'])->name('edit');
        Route::put('/{question}', [QuestionController::class, 'update'])->name('update');
        Route::delete('/{question}', [QuestionController::class, 'destroy'])->name('destroy');
        Route::post('/{question}/duplicate', [QuestionController::class, 'duplicate'])->name('duplicate');
    });
    
    // Question type defaults API (standalone route - matches admin structure)
    Route::get('api/question-types/defaults', [QuestionController::class, 'getTypeDefaults'])->name('api.question-types.defaults');
    
    // Question Bank API endpoints (for assessment creation)
    Route::prefix('api/questions')->name('api.questions.')->group(function () {
        Route::get('/search', [QuestionController::class, 'searchApi'])->name('search');
        Route::post('/quick-create', [QuestionController::class, 'quickCreateApi'])->name('quick-create');
        Route::get('/types/defaults/{type}', [QuestionController::class, 'getTypeDefaults'])->name('types.defaults');
    });
    
    // Journeys (Teacher can view and organize learning journeys)
    // Route::resource('journeys', JourneyController::class)->only(['index', 'create', 'store']);
    // Route::get('/journeys/overview', [JourneyController::class, 'show'])->name('journeys.overview');
    
    // Teacher Tasks Management
    Route::prefix('tasks')->name('tasks.')->group(function () {
        Route::get('/', [App\Http\Controllers\AdminTaskController::class, 'teacherIndex'])->name('index');
        Route::get('/{id}', [App\Http\Controllers\AdminTaskController::class, 'teacherShow'])->name('show');
        Route::put('/{id}/status', [App\Http\Controllers\AdminTaskController::class, 'updateStatus'])->name('update-status');
        
        // Real-time counter endpoint
        Route::get('/count/pending', [App\Http\Controllers\AdminTaskController::class, 'getPendingCount'])->name('count');
    });
    
    // Year Group Management (Teacher - scoped to assigned students only)
    Route::prefix('year-groups')->name('year-groups.')->group(function () {
        Route::get('/', [App\Http\Controllers\Admin\YearGroupManagementController::class, 'getYearGroups'])->name('index');
        Route::post('/bulk-update', [App\Http\Controllers\Admin\YearGroupManagementController::class, 'teacherBulkUpdate'])->name('bulk-update');
    });
});
