<?php
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\AboutController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\UserSubscriptionController;
use App\Http\Controllers\ProfileController;

// use App\Http\Controllers\AlertController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SlideController;
use App\Http\Controllers\FaqController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\ChildController;
use App\Http\Controllers\LessonController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\HomeworkController;
use App\Http\Controllers\HomeworkSubmissionController;
use App\Http\Controllers\AdminTaskController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\TestimonialController;
use App\Http\Controllers\MilestoneController;
// routes/web.php
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\SubmissionsController;
use App\Http\Controllers\TrackerController;

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\JourneyCategoryController;
use App\Http\Controllers\JourneyController;
use App\Http\Controllers\ParentFeedbackController;
use App\Http\Controllers\Admin\AccessController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\TeacherController;
use App\Http\Controllers\Admin\TeacherStudentAssignmentController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\QuestionController;

// Admin Access Management

Route::middleware(['auth', 'role:admin,parent,user,super_admin'])->group(function () {

    // Organization Management
    Route::resource('organizations', OrganizationController::class);
    Route::post('organizations/switch', [OrganizationController::class, 'switch'])->name('organizations.switch');
    Route::get('organizations/{organization}/users', [OrganizationController::class, 'users'])->name('organizations.users');
    Route::post('organizations/{organization}/users', [OrganizationController::class, 'addUser'])->name('organizations.addUser');
    Route::put('organizations/{organization}/users/{user}/role', [OrganizationController::class, 'updateUserRole'])->name('organizations.updateUserRole');
    Route::delete('organizations/{organization}/users/{user}', [OrganizationController::class, 'removeUser'])->name('organizations.removeUser');
    Route::get('organizations/{organization}/features', [OrganizationController::class, 'features'])->name('organizations.features');
    Route::put('organizations/{organization}/features', [OrganizationController::class, 'updateFeatures'])->name('organizations.features.update');

    // Question Bank Management
    Route::resource('questions', QuestionController::class)->names([
        'index' => 'admin.questions.index',
        'create' => 'admin.questions.create',
        'store' => 'admin.questions.store',
        'show' => 'admin.questions.show',
        'edit' => 'admin.questions.edit',
        'update' => 'admin.questions.update',
        'destroy' => 'admin.questions.destroy',
    ]);
    Route::post('questions/{question}/duplicate', [QuestionController::class, 'duplicate'])->name('admin.questions.duplicate');
    Route::get('questions/{question}/preview', [QuestionController::class, 'preview'])->name('admin.questions.preview');
    Route::get('api/question-types/defaults', [QuestionController::class, 'getTypeDefaults'])->name('admin.api.question-types.defaults');

    // Question Bank API endpoints
    Route::prefix('api/questions')->name('api.questions.')->group(function () {
        Route::get('/search', [QuestionController::class, 'searchApi'])->name('search');
        Route::post('/quick-create', [QuestionController::class, 'quickCreateApi'])->name('quick-create');
        Route::get('/types/defaults/{type}', [QuestionController::class, 'getTypeDefaults'])->name('types.defaults');
    });

    // Assessment-Question API endpoints
    Route::prefix('api/assessments')->name('api.assessments.')->group(function () {
        Route::get('/{assessment}/questions', [AssessmentController::class, 'getQuestionsApi'])->name('questions');
        Route::post('/{assessment}/questions/attach', [AssessmentController::class, 'attachQuestionsApi'])->name('questions.attach');
        Route::delete('/{assessment}/questions/{question}', [AssessmentController::class, 'detachQuestionApi'])->name('questions.detach');
    });

    // Keep-alive endpoint for session extension during assessment creation
    Route::get('/assessments/keep-alive', [\App\Http\Controllers\AssessmentController::class, 'keepAlive'])->name('assessments.keepAlive');



     Route::get('/access', [AccessController::class, 'index'])->name('admin.access.index');
     Route::put('/{id}', [AccessController::class, 'update'])->name('admin.access.update');

     // Teachers
     Route::get('/teachers/assignments', [TeacherController::class, 'assignments'])->name('teachers.assignments');
     Route::resource('teachers', TeacherController::class);
     Route::get('/admin-dashboard', [DashboardController::class, 'index'])->name('admin.dashboard');
     Route::get('/admin-dashboard/debug', [DashboardController::class, 'debug'])->name('admin.dashboard.debug');

     Route::resource('subscriptions', SubscriptionController::class)
          ->except(['show']);

    // Grant / revoke for users
    Route::post  ('users/{user}/subscriptions', [UserSubscriptionController::class,'store'])
         ->name('users.subscriptions.store');
    Route::delete('users/{user}/subscriptions/{pivotId}', [UserSubscriptionController::class,'destroy'])
         ->name('users.subscriptions.destroy');
     Route::get('/user-subscriptions', [UserSubscriptionController::class,'index'])
         ->name('user_subscriptions.index');

    // grant / revoke for a specific user
    Route::get('/users/{user}/subscriptions',    [UserSubscriptionController::class,'show'])
         ->name('user_subscriptions.show');
    Route::post('/users/{user}/subscriptions',   [UserSubscriptionController::class,'store'])
         ->name('user_subscriptions.store');
    Route::delete('/users/{user}/subscriptions/{pivot}',
         [UserSubscriptionController::class,'destroy'])
         ->name('user_subscriptions.destroy');

    // Grant subscription to any user (new page and action)
    Route::get('/user-subscriptions/grant', [UserSubscriptionController::class, 'grant'])
        ->name('user_subscriptions.grant');
    Route::post('/user-subscriptions/grant', [UserSubscriptionController::class, 'storeForNewUser'])
        ->name('user_subscriptions.store_for_new_user');

// Route::post('/attendance/{attendance}/approve', [AttendanceController::class,'approve'])
//     ->name('attendance.approve');
Route::resource('journeys', JourneyController::class)
     ->only(['index','create','store']);

Route::get('/journeys/overview',[JourneyController::class, 'show'])
        ->name('journeys.overview');

Route::resource('journey-categories', JourneyCategoryController::class)
     ->only(['index','create','store']);

// Articles Management (Admin)
Route::get('/admin/articles', [ArticleController::class, 'adminIndex'])->name('admin.articles.index');
Route::get('/admin/articles/create', [ArticleController::class, 'create'])->name('admin.articles.create');
Route::post('/articles', [ArticleController::class, 'store'])->name('articles.store');

Route::get('/articles/{id}/edit', [ArticleController::class, 'edit'])->name('articles.edit');
Route::put('/articles/{id}', [ArticleController::class, 'update'])->name('articles.update');
Route::delete('/articles/{id}', [ArticleController::class, 'destroy'])->name('articles.destroy');

Route::get('/submissions', [SubmissionsController::class, 'index'])->name('submissions.index');
 Route::get('admin/submissions/{submission}/grade',
        [SubmissionsController::class, 'edit'])->name('admin.submissions.grade');
 Route::get('admin/submissions/{submission}',
        [SubmissionsController::class, 'AdminShow'])->name('admin.submissions.show');
    Route::patch('admin/submissions/{submission}',
        [SubmissionsController::class, 'update'])->name('admin.submissions.update');

Route::get('/feedbacks', [FeedbackController::class, 'index'])->name('feedbacks.index');
Route::get('/admin/feedbacks/create', [FeedbackController::class, 'create'])->name('admin.feedbacks.create');

 Route::get('/homework', [HomeworkController::class, 'index'])->name('homework.index');
Route::get('/homework/create', [HomeworkController::class, 'create'])->name('homework.create');
Route::post('/homework', [HomeworkController::class, 'store'])->name('homework.store');
Route::get('/homework/{id}', [HomeworkController::class, 'show'])  ->where('id', '[0-9]+')->name('homework.show');
Route::get('/homework/{id}/edit', [HomeworkController::class, 'edit'])->name('homework.edit');
Route::put('/homework/{id}', [HomeworkController::class, 'update'])->name('homework.update');
Route::delete('/homework/{id}', [HomeworkController::class, 'destroy'])->name('homework.destroy');

Route::get('/feedbacks/{id}/edit', [FeedbackController::class, 'edit'])->name('feedbacks.edit');
Route::put('/feedbacks/{id}', [FeedbackController::class, 'update'])->name('feedbacks.update');
Route::delete('/feedbacks/{id}', [FeedbackController::class, 'destroy'])->name('feedbacks.destroy');


Route::get('/faqs', [FaqController::class, 'index'])->name('faqs.index');
Route::get('/admin/faqs/create', [FaqController::class, 'create'])->name('admin.faqs.create');
Route::post('/faqs', [FaqController::class, 'store'])->name('faqs.store');

Route::get('/faqs/{id}/edit', [FaqController::class, 'edit'])->name('faqs.edit');
Route::put('/faqs/{id}', [FaqController::class, 'update'])->name('faqs.update');
Route::delete('/faqs/{id}', [FaqController::class, 'destroy'])->name('faqs.destroy');


Route::get('/slides', [SlideController::class, 'index'])->name('slides.index');
Route::get('/admin/slides/create', [SlideController::class, 'create'])->name('admin.slides.create');
Route::post('/slides', [SlideController::class, 'store'])->name('slides.store');

Route::get('/slides/{slide_id}/edit', [SlideController::class, 'edit'])->name('slides.edit');
Route::put('/slides/{slide_id}', [SlideController::class, 'update'])->name('slides.update');
Route::delete('/slides/{slide_id}', [SlideController::class, 'destroy'])->name('slides.destroy');


// Route::get('/services', [ServiceController::class, 'index'])->name('services.index');
// Route::get('/services/create', [ServiceController::class, 'create'])->name('services.create');
// Route::post('/services', [ServiceController::class, 'store'])->name('services.store');
// Route::get('/services/{id}', [ServiceController::class, 'show'])->name('services.show');
// Route::get('/services/{id}/edit', [ServiceController::class, 'edit'])->name('services.edit');
// Route::put('/services/{id}', [ServiceController::class, 'update'])->name('services.update');
// Route::delete('/services/{id}', [ServiceController::class, 'destroy'])->name('services.destroy');
// routes/web.php
Route::get('services/{service}/available-content', [ServiceController::class, 'getAvailableContent'])->name('services.available-content');
Route::resource('admin/services', ServiceController::class)->except(['index','show']);
Route::get('admin/services', [ServiceController::class, 'adminIndex'])->name('services.admin.index');
Route::get('admin/services/{service}', [ServiceController::class, 'show'])->name('admin.services.show');
// ->middleware('can:manage-services');


Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');
Route::get('/admin/alerts/create', [AlertController::class, 'create'])->name('alerts.create');
Route::post('/alerts', [AlertController::class, 'store'])->name('alerts.store');

Route::get('/alerts/{id}/edit', [AlertController::class, 'edit'])->name('alerts.edit');
Route::put('/alerts/{id}', [AlertController::class, 'update'])->name('alerts.update');
Route::delete('/alerts/{id}', [AlertController::class, 'destroy'])->name('alerts.destroy');


// Route::get('/milestones', [MilestoneController::class, 'index'])->name('milestones.index');
// Route::get('/admin/milestones/create', [MilestoneController::class, 'create'])->name('admin.milestones.create');
// Route::post('/milestones', [MilestoneController::class, 'store'])->name('milestones.store');
// Route::get('/milestones/{id}/edit', [MilestoneController::class, 'edit'])->name('milestones.edit');
// Route::put('/milestones/{id}', [MilestoneController::class, 'update'])->name('milestones.update');
Route::resource('milestones', MilestoneController::class);


Route::get('/testimonials', [TestimonialController::class, 'index'])->name('testimonials.index');
Route::get('/admin/testimonials/create', [TestimonialController::class, 'create'])->name('admin.testimonials.create');
Route::post('/testimonials', [TestimonialController::class, 'store'])->name('testimonials.store');

Route::get('/testimonials/{id}/edit', [TestimonialController::class, 'edit'])->name('testimonials.edit');
Route::put('/testimonials/{id}', [TestimonialController::class, 'update'])->name('testimonials.update');
Route::delete('/testimonials/{id}', [TestimonialController::class, 'destroy'])->name('testimonials.destroy');



Route::get('/admin-tasks', [AdminTaskController::class, 'index'])->name('admin_tasks.index');
Route::get('/admin-tasks/create', [AdminTaskController::class, 'create'])->name('admin_tasks.create');
Route::post('/admin-tasks', [AdminTaskController::class, 'store'])->name('admin_tasks.store');
Route::get('/admin-tasks/{id}', [AdminTaskController::class, 'show'])->name('admin_tasks.show');
Route::get('/admin-tasks/{id}/edit', [AdminTaskController::class, 'edit'])->name('admin_tasks.edit');
Route::put('/admin-tasks/{id}', [AdminTaskController::class, 'update'])->name('admin_tasks.update');
Route::delete('/admin-tasks/{id}', [AdminTaskController::class, 'destroy'])->name('admin_tasks.destroy');

// Teacher Application Management Routes
Route::prefix('teacher-applications')->name('teacher.applications.')->group(function () {
    Route::get('/', [\App\Http\Controllers\TeacherController::class, 'index'])->name('index');
    Route::get('/pending', [\App\Http\Controllers\TeacherController::class, 'getPendingApplications'])->name('pending');
    Route::post('/{task}/approve', [\App\Http\Controllers\TeacherController::class, 'approve'])->name('approve');
    Route::post('/{task}/reject', [\App\Http\Controllers\TeacherController::class, 'reject'])->name('reject');
});

 Route::get('/attendance',               [AttendanceController::class,'overview'])
         ->name('attendance.overview');

    /* page 2 – per-lesson sheet */
    Route::get('/attendance/lesson/{lesson}', [AttendanceController::class,'sheet'])
         ->name('attendance.sheet');

    /* bulk actions (POST so a form can submit easily) */
    Route::prefix('admin')
     ->name('attendance.')
     ->group(function(){
         Route::post('/lessons/{lesson}/attendance/mark-all',    [AttendanceController::class,'markAll'])    ->name('markAll');
         Route::post('/lessons/{lesson}/attendance/approve-all', [AttendanceController::class,'approveAll'])->name('approveAll');
         Route::post('/lessons/{lesson}/attendance',            [AttendanceController::class,'store'])      ->name('store');
         Route::post('/attendance/{attendance}/approve',        [AttendanceController::class,'approve'])    ->name('approve');
         // …etc…
     });

    Route::get('/admin/lessons/{lesson}',[LessonController::class,'adminShow'])->name('lessons.admin.show');
    Route::get('/admin/assigned-lessons',[LessonController::class,'AssignedLessons'])->name('lessons.admin.assigned');

       Route::get('/admin/portal-feedbacks',        [ParentFeedbackController::class, 'index'])
         ->name('portal.feedback.index');

    Route::get('/admin/portal-feedbacks/{id}',   [ParentFeedbackController::class, 'show'])
         ->name('portal.feedback.show');

    // "Manage" page: to update status/admin_response, etc.
    Route::put('/admin/portal-feedbacks/{id}',   [ParentFeedbackController::class, 'update'])
         ->name('portal.feedback.update');



/*
|--------------------------------------------------------------------------
| ADMIN ROUTES
|--------------------------------------------------------------------------
|
| These resource routes and management endpoints are intended for
| administrative or backend use (e.g., CRUD operations). In a real app,
| you would wrap these in an admin middleware or prefix.
|
*/

// Children management (CRUD)
Route::resource('children', ChildController::class);

// Lessons management (CRUD)
Route::resource('lessons', LessonController::class);

// Products management (CRUD)
Route::resource('products', ProductController::class);

// Assessments management (CRUD)
Route::resource('assessments', AssessmentController::class);

// Assessment attempt & submit (admin/test mode)
Route::prefix('assessments/{assessment}')->group(function () {
    Route::get('attempt', [AssessmentController::class, 'attempt'])
         ->name('assessments.attempt');
    Route::post('attempt', [AssessmentController::class, 'attemptSubmit'])
         ->name('assessments.attemptSubmit');
});

// Milestones management (CRUD)
Route::resource('milestones', MilestoneController::class);

// Transactions management (index/show)
Route::resource('transactions', TransactionController::class)
     ->except(['index', 'show']);

// Submissions (admin view of results)
Route::get('submissions/{submission}', [SubmissionsController::class, 'show'])
     ->name('submissions.show');

// Applications management (index/show/edit/review/delete)
Route::get('/applications', [ApplicationController::class, 'index'])->name('applications.index');
Route::get('/applications/{id}', [ApplicationController::class, 'show'])->name('applications.show');
Route::get('/applications/{id}/edit', [ApplicationController::class, 'edit'])->name('applications.edit');
Route::put('/applications/{id}', [ApplicationController::class, 'reviewApplication'])->name('applications.review');
Route::delete('/applications/{id}', [ApplicationController::class, 'destroy'])->name('applications.destroy');

// AI Grading Flags Management
Route::prefix('flags')->name('admin.flags.')->group(function () {
    Route::get('/', [App\Http\Controllers\Admin\FlagController::class, 'index'])->name('index');
    Route::get('/{flag}', [App\Http\Controllers\Admin\FlagController::class, 'show'])->name('show');
    Route::post('/{flag}/resolve', [App\Http\Controllers\Admin\FlagController::class, 'resolve'])->name('resolve');
    Route::post('/bulk-resolve', [App\Http\Controllers\Admin\FlagController::class, 'bulkResolve'])->name('bulk-resolve');
    Route::get('/stats', [App\Http\Controllers\Admin\FlagController::class, 'stats'])->name('stats');
});

/*
|--------------------------------------------------------------------------
| NEW LESSON SYSTEM ROUTES (Block-Based Content)
|--------------------------------------------------------------------------
| Course → Module → Lesson → Slide hierarchy with block-based content
*/

// Course Management
Route::prefix('admin/courses')->name('admin.courses.')->group(function () {
    Route::get('/', [App\Http\Controllers\CourseController::class, 'index'])->name('index');
    Route::get('/create', [App\Http\Controllers\CourseController::class, 'create'])->name('create');
    Route::post('/', [App\Http\Controllers\CourseController::class, 'store'])->name('store');
    Route::get('/{course}', [App\Http\Controllers\CourseController::class, 'show'])->name('show');
    Route::get('/{course}/edit', [App\Http\Controllers\CourseController::class, 'edit'])->name('edit');
    Route::put('/{course}', [App\Http\Controllers\CourseController::class, 'update'])->name('update');
    Route::delete('/{course}', [App\Http\Controllers\CourseController::class, 'destroy'])->name('destroy');

    // Course actions
    Route::post('/{course}/publish', [App\Http\Controllers\CourseController::class, 'publish'])->name('publish');
    Route::post('/{course}/archive', [App\Http\Controllers\CourseController::class, 'archive'])->name('archive');
    Route::post('/{course}/duplicate', [App\Http\Controllers\CourseController::class, 'duplicate'])->name('duplicate');

    // Modules within course
    Route::prefix('/{course}/modules')->name('modules.')->group(function () {
        Route::get('/', [App\Http\Controllers\ModuleController::class, 'index'])->name('index');
        Route::post('/', [App\Http\Controllers\ModuleController::class, 'store'])->name('store');
        Route::post('/reorder', [App\Http\Controllers\ModuleController::class, 'reorder'])->name('reorder');
    });
});

// Module Management
Route::prefix('admin/modules')->name('admin.modules.')->group(function () {
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

// Content Lesson Management
Route::prefix('admin/content-lessons')->name('admin.content-lessons.')->group(function () {
    // Admin index/create for standalone Content Lessons
    Route::get('/', [App\Http\Controllers\ContentLessonController::class, 'adminIndex'])->name('index');
    Route::get('/create', [App\Http\Controllers\ContentLessonController::class, 'create'])->name('create');
    Route::post('/', [App\Http\Controllers\ContentLessonController::class, 'storeAdmin'])->name('store');

    Route::get('/{lesson}', [App\Http\Controllers\ContentLessonController::class, 'show'])->name('show');

    // Metadata edit (admin): /admin/content-lessons/{lesson}/edit
    Route::get('/{lesson}/edit', [App\Http\Controllers\ContentLessonController::class, 'edit'])->name('edit');
    Route::get('/{lesson}/editform', [App\Http\Controllers\ContentLessonController::class, 'editForm'])->name('editForm');

    // Slide editor (reuse existing SlideEditor) exposed under /admin/content-lessons/{lesson}/slides
    Route::get('/{lesson}/slides', [App\Http\Controllers\ContentLessonController::class, 'edit'])->name('slides.edit');

    Route::put('/{lesson}', [App\Http\Controllers\ContentLessonController::class, 'update'])->name('update');
    Route::delete('/{lesson}', [App\Http\Controllers\ContentLessonController::class, 'destroy'])->name('destroy');
    Route::post('/{lesson}/publish', [App\Http\Controllers\ContentLessonController::class, 'publish'])->name('publish');
    Route::post('/{lesson}/duplicate', [App\Http\Controllers\ContentLessonController::class, 'duplicate'])->name('duplicate');

    // Assessment management
    Route::post('/{lesson}/assessments/{assessment}/attach', [App\Http\Controllers\ContentLessonController::class, 'attachAssessment'])->name('assessments.attach');
    Route::delete('/{lesson}/assessments/{assessment}/detach', [App\Http\Controllers\ContentLessonController::class, 'detachAssessment'])->name('assessments.detach');

    // Slides within lesson
    Route::prefix('/{lesson}/slides')->name('slides.')->group(function () {
        Route::get('/', [App\Http\Controllers\LessonSlideController::class, 'index'])->name('index');
        Route::post('/', [App\Http\Controllers\LessonSlideController::class, 'store'])->name('store');
        Route::post('/reorder', [App\Http\Controllers\LessonSlideController::class, 'reorder'])->name('reorder');
    });
});

// Lesson Slide Management
Route::prefix('admin/lesson-slides')->name('admin.lesson-slides.')->group(function () {
    Route::get('/{slide}', [App\Http\Controllers\LessonSlideController::class, 'show'])->name('show');
    Route::put('/{slide}', [App\Http\Controllers\LessonSlideController::class, 'update'])->name('update');
    Route::delete('/{slide}', [App\Http\Controllers\LessonSlideController::class, 'destroy'])->name('destroy');
    Route::post('/{slide}/duplicate', [App\Http\Controllers\LessonSlideController::class, 'duplicate'])->name('duplicate');

    // Block management
    Route::post('/{slide}/blocks', [App\Http\Controllers\LessonSlideController::class, 'addBlock'])->name('blocks.add');
    Route::put('/{slide}/blocks/{blockId}', [App\Http\Controllers\LessonSlideController::class, 'updateBlock'])->name('blocks.update');
    Route::delete('/{slide}/blocks/{blockId}', [App\Http\Controllers\LessonSlideController::class, 'deleteBlock'])->name('blocks.delete');
});

// Image Upload for Lesson Content
Route::post('admin/upload-image', [App\Http\Controllers\ImageUploadController::class, 'upload'])->name('admin.upload-image');

// Upload Review (Teacher/Admin)
Route::prefix('admin/lesson-uploads')->name('admin.lesson-uploads.')->group(function () {
    Route::get('/pending', [App\Http\Controllers\LessonUploadController::class, 'pendingUploads'])->name('pending');
    Route::get('/{upload}', [App\Http\Controllers\LessonUploadController::class, 'show'])->name('show');
    Route::post('/{upload}/grade', [App\Http\Controllers\LessonUploadController::class, 'grade'])->name('grade');
    Route::post('/{upload}/feedback', [App\Http\Controllers\LessonUploadController::class, 'submitFeedback'])->name('feedback');
    Route::post('/{upload}/return', [App\Http\Controllers\LessonUploadController::class, 'returnToStudent'])->name('return');
    Route::post('/{upload}/ai-analysis', [App\Http\Controllers\LessonUploadController::class, 'requestAIAnalysis'])->name('ai-analysis');
    Route::delete('/{upload}', [App\Http\Controllers\LessonUploadController::class, 'destroy'])->name('destroy');
});

/*
|--------------------------------------------------------------------------
| LIVE LESSON SESSION ROUTES (Teacher Control Panel)
|--------------------------------------------------------------------------
| WebSocket-enabled live lesson control for teachers
*/

Route::prefix('admin/live-sessions')->name('admin.live-sessions.')->group(function () {
    // Session Management (CRUD)
    Route::get('/', [App\Http\Controllers\LiveLessonController::class, 'index'])->name('index');
    Route::get('/create', [App\Http\Controllers\LiveLessonController::class, 'create'])->name('create');
    Route::post('/', [App\Http\Controllers\LiveLessonController::class, 'store'])->name('store');
    Route::get('/{session}/edit', [App\Http\Controllers\LiveLessonController::class, 'edit'])->name('edit');
    Route::put('/{session}', [App\Http\Controllers\LiveLessonController::class, 'update'])->name('update');
    Route::delete('/{session}', [App\Http\Controllers\LiveLessonController::class, 'destroy'])->name('destroy');
    Route::post('/{session}/start', [App\Http\Controllers\LiveLessonController::class, 'startSession'])->name('start');

    // Teacher Control Panel
    Route::get('/{session}/teach', [App\Http\Controllers\LiveLessonController::class, 'teacherPanel'])->name('teach');
    Route::get('/{session}/teacher-panel', [App\Http\Controllers\LiveLessonController::class, 'teacherPanel'])->name('teacher-panel');

    // Session Control Actions
    Route::post('/{session}/change-slide', [App\Http\Controllers\LiveLessonController::class, 'changeSlide'])->name('change-slide');
    Route::post('/{session}/change-state', [App\Http\Controllers\LiveLessonController::class, 'changeState'])->name('change-state');
    Route::post('/{session}/toggle-navigation-lock', [App\Http\Controllers\LiveLessonController::class, 'toggleNavigationLock'])->name('toggle-navigation-lock');

    // Interactive Features
    Route::post('/{session}/highlight-block', [App\Http\Controllers\LiveLessonController::class, 'highlightBlock'])->name('highlight-block');
    Route::post('/{session}/send-annotation', [App\Http\Controllers\LiveLessonController::class, 'sendAnnotation'])->name('send-annotation');
    Route::post('/{session}/clear-annotations', [App\Http\Controllers\LiveLessonController::class, 'clearAnnotations'])->name('clear-annotations');

    // Participant Management
    Route::get('/{session}/participants', [App\Http\Controllers\LiveLessonController::class, 'getParticipants'])->name('participants');
    Route::post('/{session}/participants/{participant}/lower-hand', [App\Http\Controllers\LiveLessonController::class, 'lowerHand'])->name('lower-hand');
    Route::post('/{session}/participants/{participant}/mute', [App\Http\Controllers\LiveLessonController::class, 'muteParticipant'])->name('participants.mute');
    Route::post('/{session}/participants/{participant}/disable-camera', [App\Http\Controllers\LiveLessonController::class, 'disableCamera'])->name('participants.disable-camera');
    Route::post('/{session}/participants/mute-all', [App\Http\Controllers\LiveLessonController::class, 'muteAll'])->name('participants.mute-all');
    Route::post('/{session}/participants/{participant}/kick', [App\Http\Controllers\LiveLessonController::class, 'kickParticipant'])->name('participants.kick');

    // Messaging
    Route::get('/{session}/messages', [App\Http\Controllers\LiveLessonController::class, 'getMessages'])->name('messages');
    Route::post('/{session}/messages/{message}/answer', [App\Http\Controllers\LiveLessonController::class, 'answerMessage'])->name('messages.answer');

    // Emoji Reactions
    Route::post('/{session}/send-reaction', [App\Http\Controllers\LiveLessonController::class, 'sendReaction'])->name('send-reaction');

    // LiveKit Audio Token (replaces Agora)
    Route::get('/{session}/agora-token', [App\Http\Controllers\LiveLessonController::class, 'getLiveKitToken'])->name('agora-token');
    Route::get('/{session}/livekit-token', [App\Http\Controllers\LiveLessonController::class, 'getLiveKitToken'])->name('livekit-token');
});

// Public application creation & verification (already in Public section, but listed here for clarity)

// Teacher-Student Assignment Management
Route::prefix('teacher-student-assignments')->name('admin.teacher-student-assignments.')->group(function () {
    Route::get('/', [TeacherStudentAssignmentController::class, 'index'])->name('index');
    Route::get('/data', [TeacherStudentAssignmentController::class, 'getData'])->name('data');
    Route::post('/assign', [TeacherStudentAssignmentController::class, 'assign'])->name('assign');
    Route::post('/bulk-assign', [TeacherStudentAssignmentController::class, 'bulkAssign'])->name('bulk-assign');
    Route::post('/unassign', [TeacherStudentAssignmentController::class, 'unassign'])->name('unassign');
    Route::delete('/{id}', [TeacherStudentAssignmentController::class, 'destroy'])->name('destroy');
    Route::get('/assignments', [TeacherStudentAssignmentController::class, 'getAssignments'])->name('assignments');
});

// Year Group Management
Route::prefix('year-groups')->name('admin.year-groups.')->group(function () {
    Route::get('/', [App\Http\Controllers\Admin\YearGroupManagementController::class, 'getYearGroups'])->name('index');
    Route::post('/bulk-update', [App\Http\Controllers\Admin\YearGroupManagementController::class, 'bulkUpdate'])->name('bulk-update');
});

/*
|--------------------------------------------------------------------------
| AI CONTENT CREATOR ROUTES
|--------------------------------------------------------------------------
| AI-powered bulk content generation for questions, assessments, courses,
| modules, lessons, slides, and articles
*/

Route::prefix('admin/ai-upload')->name('admin.ai-upload.')->group(function () {
    // Main dashboard
    Route::get('/', [App\Http\Controllers\Admin\AIUploadController::class, 'index'])->name('index');
    
    // Session CRUD
    Route::post('/', [App\Http\Controllers\Admin\AIUploadController::class, 'store'])->name('store');
    Route::get('/{session}', [App\Http\Controllers\Admin\AIUploadController::class, 'show'])->name('show');
    Route::post('/{session}/cancel', [App\Http\Controllers\Admin\AIUploadController::class, 'cancel'])->name('cancel');
    Route::delete('/{session}', [App\Http\Controllers\Admin\AIUploadController::class, 'destroy'])->name('destroy');
    
    // Session logs
    Route::get('/{session}/logs', [App\Http\Controllers\Admin\AIUploadController::class, 'logs'])->name('logs');
    
    // Proposal management
    Route::put('/proposals/{proposal}', [App\Http\Controllers\Admin\AIUploadController::class, 'updateProposal'])->name('proposals.update');
    Route::post('/proposals/{proposal}/refine', [App\Http\Controllers\Admin\AIUploadController::class, 'refineProposal'])->name('proposals.refine');
    
    // Bulk approval/rejection
    Route::post('/{session}/approve', [App\Http\Controllers\Admin\AIUploadController::class, 'approveProposals'])->name('approve');
    Route::post('/{session}/reject', [App\Http\Controllers\Admin\AIUploadController::class, 'rejectProposals'])->name('reject');
    
    // Upload to database
    Route::post('/{session}/upload', [App\Http\Controllers\Admin\AIUploadController::class, 'upload'])->name('upload');
});


});
