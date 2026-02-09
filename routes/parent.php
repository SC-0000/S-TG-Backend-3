<?php


use App\Http\Controllers\AboutController;
use App\Http\Controllers\ProfileController;
use Illuminate\Http\Request;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
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
use App\Http\Controllers\Api\ChatController;
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
use App\Http\Controllers\CalendarFeed;
use App\Http\Controllers\JourneyController;
use App\Http\Controllers\ParentFeedbackController;

Route::middleware('auth')->group(function () {
    Route::view('/profile', 'app-api')->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('verify-email', function (Request $request) {
        if ($request->user()?->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        return view('app-api');
    })->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');
});

Route::middleware(['auth', 'role:admin,parent,guest_parent,super_admin'])->group(function () {

    // Subscription Assignment
    Route::post('/subscriptions/{subscription}/assign', [App\Http\Controllers\Parent\SubscriptionController::class, 'assign'])
        ->middleware('feature:parent.subscriptions')
        ->name('parent.subscriptions.assign');

    // Parent Services Detail Page

   Route::post('/lesson/{lesson}/attendance', [AttendanceController::class,'store'])
            ->name('portal.attendance.store');
    Route::get('/lessons/{lesson}',[LessonController::class,'Show'])->name('lessons.show');
    Route::prefix('admin')
     ->name('attendance.')
     ->group(function(){
        Route::post('/lessons/{lesson}/attendance',            [AttendanceController::class,'store'])      ->name('store');
         
     });
Route::post('/ai/chat', [ChatController::class,'ask'])
    ->middleware(['subscription:ai_analysis', 'feature:parent.ai.chatbot'])
    ->name('ai.chat.ask'); // Ask AI a question
Route::view('/ai/chat', 'app-api')
    ->middleware(['subscription:ai_analysis', 'feature:parent.ai.chatbot'])
    ->name('ai.chat.fetch'); // Fetch chat history
Route::get('/ai/chat/history', [ChatController::class,'fetch'])
    ->middleware(['subscription:ai_analysis', 'feature:parent.ai.chatbot'])
    ->name('ai.chat.history'); // Fetch chat history
Route::post('/ai/hint-loop', [ChatController::class, 'hintLoop'])
    ->middleware(['subscription:ai_analysis', 'feature:parent.ai.post_submission_help']);
Route::get('/ai/chat/open', [ChatController::class,'open'])
    ->middleware(['subscription:ai_analysis', 'feature:parent.ai.chatbot']);


Route::get('/portal/journey',[JourneyController::class, 'portalOverview'])
        ->name('portal.journey.overview');


    // routes/web.php
Route::get('/calendar/{token}.ics', CalendarFeed::class)
     ->name('calendar.feed');

 Route::get('/portal/feedback/create', [ParentFeedbackController::class, 'create'])
         ->name('portal.feedback.create');
    // Handle form submission
    Route::post('/portal/feedback/create', [ParentFeedbackController::class, 'store'])
         ->name('portal.feedback.store');
    Route::get('/portal', [ PortalController::class, 'index'])->middleware('redirect.incomplete.guests')->name('parentportal.index');
Route::get('/portal/tracker', [ TrackerController::class, 'show'])->middleware('redirect.incomplete.guests')->name('parentportal.show');
Route::get('/portal/lessons', [AssessmentController::class, 'portalIndex'])->name('portal.lessons.index');
Route::get('/portal/assessments', [AssessmentController::class, 'portalIndex'])->name('portal.assessments.index');
Route::get('/portal/submissions', [AssessmentController::class, 'portalIndex'])->name('portal.submissions.index');
Route::get('/portal/calender', [PortalController::class, 'calenderIndex'])->middleware('redirect.incomplete.guests')->name('portal.calender.index');
Route::get('/portal/deadlines', [PortalController::class, 'deadlineIndex'])->middleware('redirect.incomplete.guests')->name('portal.deadline.index');
Route::get('/portal/submission', [PortalController::class, 'submissionIndex'])->name('portal.submission.index');
Route::get('/portal/schedule', [PortalController::class, 'scheduleIndex'])->middleware('redirect.incomplete.guests')->name('portal.schedule.index');
Route::get('/portal/transactions', [PortalController::class, 'transactionsIndex'])->name('portal.transactions.index');
// Assessment attempt & submit (admin/test mode)
Route::prefix('assessments/{assessment}')->group(function () {
    Route::get('attempt', [AssessmentController::class, 'attempt'])
         ->name('assessments.attempt');
    Route::post('attempt', [AssessmentController::class, 'attemptSubmit'])
         ->name('assessments.attemptSubmit');
});
    Route::get('/assessments/keep-alive', [AssessmentController::class, 'keepAlive'])->name('assessments.keepAlive');

Route::get('/submissions', [SubmissionsController::class, 'index'])->name('submissions.index');
Route::get('submissions/{submission}', [SubmissionsController::class, 'show'])
     ->name('submissions.show');
// AI Hub Demo Route - Phase 4 Frontend Hub
Route::get('/portal/ai-hub', [PortalController::class, 'aiHubDemo'])
    ->middleware('feature:parent.ai.chatbot')
    ->name('portal.ai-hub.demo');

// AI Console Page Route - Dedicated AI Chat Interface
Route::get('/portal/ai-console', [PortalController::class, 'aiConsole'])
    ->middleware('feature:parent.ai.chatbot')
    ->name('portal.ai-console');

    // Homework assignments routes


    // Homework submissions routes
    Route::get('/homework/{assignmentId}/submission/create', [HomeworkSubmissionController::class, 'create'])->name('homework.submission.create');
    Route::post('/homework/{assignmentId}/submission', [HomeworkSubmissionController::class, 'store'])->name('homework.submission.store');
    Route::get('/homework/submissions', [HomeworkSubmissionController::class, 'index'])->name('homework.submissions.index');
    Route::get('/homework/submissions/{id}', [HomeworkSubmissionController::class, 'show'])->name('homework.submission.show');
    Route::get('/homework/submissions/{id}/edit', [HomeworkSubmissionController::class, 'edit'])->name('homework.submission.edit');
    Route::put('/homework/submissions/{id}', [HomeworkSubmissionController::class, 'update'])->name('homework.submission.update');
    Route::delete('/homework/submissions/{id}', [HomeworkSubmissionController::class, 'destroy'])->name('homework.submission.destroy');


    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/create', [NotificationController::class, 'create'])->name('notifications.create');
    Route::post('/notifications', [NotificationController::class, 'store'])->name('notifications.store');
    Route::get('/notifications/{id}', [NotificationController::class, 'show'])->name('notifications.show');
    Route::get('/notifications/{id}/edit', [NotificationController::class, 'edit'])->name('notifications.edit');
    Route::put('/notifications/{id}', [NotificationController::class, 'update'])->name('notifications.update');
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy'])->name('notifications.destroy');

    Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::get('/tasks/create', [TaskController::class, 'create'])->name('tasks.create');
    Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::get('/tasks/{id}', [TaskController::class, 'show'])->name('tasks.show');
    Route::get('/tasks/{id}/edit', [TaskController::class, 'edit'])->name('tasks.edit');
    Route::put('/tasks/{id}', [TaskController::class, 'update'])->name('tasks.update');
    Route::delete('/tasks/{id}', [TaskController::class, 'destroy'])->name('tasks.destroy');

    // AI Grading Flag Routes
    Route::post('/flags', [App\Http\Controllers\Parent\FlagController::class, 'store'])->name('parent.flags.store');
    Route::get('/flags', [App\Http\Controllers\Parent\FlagController::class, 'index'])->name('parent.flags.index');
    Route::get('/flags/{flag}', [App\Http\Controllers\Parent\FlagController::class, 'show'])->name('parent.flags.show');











    // AI Agent routes - Phase 2 Complete!
    Route::prefix('ai')->group(function () {
        // Tutor Agent - ChatWidget.jsx integration
        Route::post('tutor/chat', [App\Http\Controllers\AIAgentController::class, 'tutorChat'])
            ->middleware('feature:parent.ai.chatbot')
            ->name('ai.tutor.chat');

        // Grading Review Agent - Submissions/Show.jsx integration
        Route::post('grading/review', [App\Http\Controllers\AIAgentController::class, 'gradingReview'])->name('ai.grading.review');

        // Progress Analysis Agent - MySubmissions.jsx & Reports integration
        Route::post('progress/analyze', [App\Http\Controllers\AIAgentController::class, 'progressAnalysis'])
            ->middleware('feature:parent.ai.report_generation')
            ->name('ai.progress.analyze');

        // Hint Generator Agent - HintLoop.jsx integration
        Route::post('hints/generate', [App\Http\Controllers\AIAgentController::class, 'generateHint'])
            ->middleware('feature:parent.ai.post_submission_help')
            ->name('ai.hints.generate');

        // Review Chat Agent - Phase 5 Grading Disputes
        Route::post('review/initiate', [App\Http\Controllers\AIAgentController::class, 'initiateReviewChat'])->name('ai.review.initiate');
        Route::post('review/chat', [App\Http\Controllers\AIAgentController::class, 'reviewChat'])->name('ai.review.chat');

        // Session Management
        Route::get('sessions/{child_id}', [App\Http\Controllers\AIAgentController::class, 'getSessions'])->name('ai.sessions.show');

        // Data Selection for AI Console
        Route::get('data-options/{child_id}', [App\Http\Controllers\AIAgentController::class, 'getDataOptions'])->name('ai.data-options');

        // Conversation History for Timeline
        Route::get('conversations/{child_id}', [App\Http\Controllers\AIAgentController::class, 'getConversations'])->name('ai.conversations');

        // Performance Analytics
        Route::get('performance/{child_id}', [App\Http\Controllers\AIAgentController::class, 'getPerformanceData'])->name('ai.performance');

        // AI Recommendations Engine
        Route::get('recommendations/{child_id}', [App\Http\Controllers\AIAgentController::class, 'getRecommendations'])->name('ai.recommendations');
        Route::post('recommendations/execute', [App\Http\Controllers\AIAgentController::class, 'executeRecommendation'])->name('ai.recommendations.execute');
        Route::post('recommendations/{id}/dismiss', [App\Http\Controllers\AIAgentController::class, 'dismissRecommendation'])->name('ai.recommendations.dismiss');

        // Weakness Detection
        Route::get('weakness-analysis/{child_id}', [App\Http\Controllers\AIAgentController::class, 'getWeaknessAnalysis'])->name('ai.weakness-analysis');
        Route::post('interventions/execute', [App\Http\Controllers\AIAgentController::class, 'executeIntervention'])->name('ai.interventions.execute');

        // Contextual Prompts
        Route::post('contextual-prompts/generate', [App\Http\Controllers\AIAgentController::class, 'generateContextualPrompts'])->name('ai.contextual-prompts');
        Route::get('prompt-library/{child_id}', [App\Http\Controllers\AIAgentController::class, 'getPromptLibrary'])->name('ai.prompt-library');
        Route::post('prompts/custom-generate', [App\Http\Controllers\AIAgentController::class, 'generateCustomPrompt'])->name('ai.prompts.custom');
        Route::post('prompts/execute', [App\Http\Controllers\AIAgentController::class, 'executePrompt'])->name('ai.prompts.execute');

        // Learning Paths
        Route::get('learning-paths/{child_id}', [App\Http\Controllers\AIAgentController::class, 'getLearningPaths'])->name('ai.learning-paths');
        Route::get('learning-paths/{child_id}/progress', [App\Http\Controllers\AIAgentController::class, 'getPathProgress'])->name('ai.learning-paths.progress');
        Route::post('learning-paths/generate', [App\Http\Controllers\AIAgentController::class, 'generateLearningPath'])->name('ai.learning-paths.generate');
        Route::post('learning-paths/{path_id}/start', [App\Http\Controllers\AIAgentController::class, 'startLearningPath'])->name('ai.learning-paths.start');
        Route::post('learning-paths/{path_id}/steps/{step_id}/execute', [App\Http\Controllers\AIAgentController::class, 'executePathStep'])->name('ai.learning-paths.step');

        // System Information
        Route::get('agents/capabilities', [App\Http\Controllers\AIAgentController::class, 'getCapabilities'])->name('ai.capabilities');
        Route::get('health', [App\Http\Controllers\AIAgentController::class, 'healthCheck'])->name('ai.health');
    });

    /*
    |--------------------------------------------------------------------------
    | STUDENT LESSON PLAYER ROUTES (Block-Based Content)
    |--------------------------------------------------------------------------
    | Student-facing routes for consuming lesson content
    */

    // Browse Courses/Lessons
    Route::prefix('courses')->name('parent.courses.')->group(function () {
        Route::get('/', [App\Http\Controllers\CourseController::class, 'browse'])->name('browse');
        Route::get('/my-courses', [App\Http\Controllers\CourseController::class, 'myCourses'])->name('my-courses');
        Route::get('/{course}', [App\Http\Controllers\CourseController::class, 'show'])->name('show');
    });

    // Lesson Player
    Route::prefix('lessons')->name('parent.lessons.')->group(function () {
        Route::post('/{lesson}/start', [App\Http\Controllers\LessonPlayerController::class, 'start'])->name('start');
        Route::get('/{lesson}/player', [App\Http\Controllers\LessonPlayerController::class, 'view'])->name('player');
        Route::get('/{lesson}/summary', [App\Http\Controllers\LessonPlayerController::class, 'summary'])->name('summary');

        // Slide API endpoints
        Route::get('/{lesson}/slides/{slide}', [App\Http\Controllers\LessonPlayerController::class, 'getSlide'])->name('slides.get');
        Route::post('/{lesson}/slides/{slide}/view', [App\Http\Controllers\LessonPlayerController::class, 'recordSlideView'])->name('slides.view');
        Route::post('/{lesson}/slides/{slide}/interaction', [App\Http\Controllers\LessonPlayerController::class, 'recordInteraction'])->name('slides.interaction');
        Route::post('/{lesson}/slides/{slide}/confidence', [App\Http\Controllers\LessonPlayerController::class, 'submitConfidence'])->name('slides.confidence');

        // Progress tracking
        Route::post('/{lesson}/progress', [App\Http\Controllers\LessonPlayerController::class, 'updateProgress'])->name('progress.update');
        Route::post('/{lesson}/complete', [App\Http\Controllers\LessonPlayerController::class, 'complete'])->name('complete');

        // Question submissions
        Route::post('/{lesson}/slides/{slide}/questions/submit', [App\Http\Controllers\LessonQuestionController::class, 'submitResponse'])->name('questions.submit');
        Route::get('/{lesson}/questions/responses', [App\Http\Controllers\LessonQuestionController::class, 'getResponses'])->name('questions.responses');
        Route::get('/{lesson}/slides/{slide}/questions/responses', [App\Http\Controllers\LessonQuestionController::class, 'getSlideResponses'])->name('questions.slide-responses');
        Route::post('/{lesson}/questions/{response}/retry', [App\Http\Controllers\LessonQuestionController::class, 'retryQuestion'])->name('questions.retry');

        // File uploads
        Route::post('/{lesson}/slides/{slide}/upload', [App\Http\Controllers\LessonUploadController::class, 'upload'])->name('upload.submit');
        Route::get('/{lesson}/uploads', [App\Http\Controllers\LessonUploadController::class, 'index'])->name('uploads.index');
        Route::get('/uploads/{upload}', [App\Http\Controllers\LessonUploadController::class, 'show'])->name('uploads.show');
        Route::delete('/uploads/{upload}', [App\Http\Controllers\LessonUploadController::class, 'destroy'])->name('uploads.delete');
    });

    // Whiteboard Routes
    Route::post('/lesson-slides/{slide}/whiteboard/save', [App\Http\Controllers\LessonSlideController::class, 'saveWhiteboardInteraction'])->name('lesson-slides.whiteboard.save');
    Route::get('/lesson-slides/{slide}/whiteboard/load', [App\Http\Controllers\LessonSlideController::class, 'loadWhiteboardInteraction'])->name('lesson-slides.whiteboard.load');

    /*
    |--------------------------------------------------------------------------
    | LIVE LESSON SESSION ROUTES (Student View)
    |--------------------------------------------------------------------------
    | WebSocket-enabled live lesson participation for students
    */

    Route::prefix('live-sessions')->name('parent.live-sessions.')->group(function () {
        // Browse available sessions
        Route::get('/', [App\Http\Controllers\LiveLessonController::class, 'studentIndex'])->name('index');

        // Join a live session
        Route::get('/{session}/join', [App\Http\Controllers\LiveLessonController::class, 'studentJoin'])->name('join');

        // Get Agora token for audio
        Route::get('/{session}/agora-token', [App\Http\Controllers\LiveLessonController::class, 'getLiveKitToken'])->name('agora-token');
        Route::get('/{session}/livekit-token', [App\Http\Controllers\LiveLessonController::class, 'getLiveKitToken'])->name('livekit-token');

        // Send student interaction (for teacher to see)
        Route::post('/{session}/interact', [App\Http\Controllers\LiveLessonController::class, 'sendAnnotation'])->name('interact');

        // Annotations
        Route::post('/{session}/send-annotation', [App\Http\Controllers\LiveLessonController::class, 'sendAnnotation'])->name('send-annotation');
        Route::post('/{session}/clear-annotations', [App\Http\Controllers\LiveLessonController::class, 'clearAnnotations'])->name('clear-annotations');

        // Raise/lower hand
        Route::post('/{session}/raise-hand', [App\Http\Controllers\LiveLessonController::class, 'raiseHand'])->name('raise-hand');

        // Messaging
        Route::post('/{session}/send-message', [App\Http\Controllers\LiveLessonController::class, 'sendMessage'])->name('send-message');

        // Emoji Reactions
        Route::post('/{session}/send-reaction', [App\Http\Controllers\LiveLessonController::class, 'sendReaction'])->name('send-reaction');

        // Leave session
        Route::post('/{session}/leave', [App\Http\Controllers\LiveLessonController::class, 'studentLeave'])->name('leave');
    });

    // My Purchased Live Sessions
    Route::prefix('my-live-sessions')->name('parent.my-live-sessions.')->group(function () {
        Route::get('/', [App\Http\Controllers\LiveLessonController::class, 'mySessionsIndex'])->name('index');
        Route::get('/{session}', [App\Http\Controllers\LiveLessonController::class, 'mySessionShow'])->name('show');
    });

});
