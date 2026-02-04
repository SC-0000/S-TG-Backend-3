<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\TeacherRegistrationController;
use App\Http\Controllers\Api\Auth\GuestOnboardingController;
use App\Http\Controllers\Api\Auth\EmailVerificationController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\Admin\DashboardController as ApiAdminDashboardController;
use App\Http\Controllers\Api\Admin\AccessController as AdminAccessController;
use App\Http\Controllers\Api\Admin\AdminTaskController as ApiAdminTaskController;
use App\Http\Controllers\Api\Admin\LessonUploadController as ApiAdminLessonUploadController;
use App\Http\Controllers\Api\Admin\LessonController as ApiAdminLessonController;
use App\Http\Controllers\Api\Admin\AttendanceController as ApiAdminAttendanceController;
use App\Http\Controllers\Api\Admin\ProductController as ApiAdminProductController;
use App\Http\Controllers\Api\Admin\TeacherController as ApiAdminTeacherController;
use App\Http\Controllers\Api\Admin\AIUploadController as ApiAdminAIUploadController;
use App\Http\Controllers\Api\Admin\FlagController as ApiAdminFlagController;
use App\Http\Controllers\Api\Admin\ApplicationController as ApiAdminApplicationController;
use App\Http\Controllers\Api\Admin\JourneyController as ApiAdminJourneyController;
use App\Http\Controllers\Api\Admin\TeacherApplicationController as ApiTeacherApplicationController;
use App\Http\Controllers\Api\Admin\PortalFeedbackController as ApiAdminPortalFeedbackController;
use App\Http\Controllers\Api\Admin\ServiceController as ApiAdminServiceController;
use App\Http\Controllers\Api\Admin\FeedbackController as ApiAdminFeedbackController;
use App\Http\Controllers\Api\Admin\TeacherStudentAssignmentController as ApiTeacherStudentAssignmentController;
use App\Http\Controllers\Api\Admin\YearGroupController as ApiAdminYearGroupController;
use App\Http\Controllers\Api\Admin\NotificationController as ApiAdminNotificationController;
use App\Http\Controllers\Api\Admin\ChildController as ApiAdminChildController;
use App\Http\Controllers\Api\Public\ContentController as PublicContentController;
use App\Http\Controllers\Api\Public\ApplicationController as ApiPublicApplicationController;
use App\Http\Controllers\Api\Public\ApeAcademyController as ApiPublicApeAcademyController;
use App\Http\Controllers\Api\ProductController as ApiProductController;
use App\Http\Controllers\Api\ServiceController as ApiServiceController;
use App\Http\Controllers\Api\SubscriptionCatalogController as ApiSubscriptionCatalogController;
use App\Http\Controllers\Api\CartController as ApiCartController;
use App\Http\Controllers\Api\CheckoutController as ApiCheckoutController;
use App\Http\Controllers\Api\BillingController as ApiBillingController;
use App\Http\Controllers\Api\TransactionController as ApiTransactionController;
use App\Http\Controllers\Api\AssessmentController as ApiAssessmentController;
use App\Http\Controllers\Api\AssessmentQuestionController as ApiAssessmentQuestionController;
use App\Http\Controllers\Api\AssessmentAttemptController as ApiAssessmentAttemptController;
use App\Http\Controllers\Api\SubmissionController as ApiSubmissionController;
use App\Http\Controllers\Api\QuestionBankController as ApiQuestionBankController;
use App\Http\Controllers\Api\AssessmentPortalController as ApiAssessmentPortalController;
use App\Http\Controllers\Api\HomeworkAssignmentController as ApiHomeworkAssignmentController;
use App\Http\Controllers\Api\HomeworkSubmissionController as ApiHomeworkSubmissionController;
use App\Http\Controllers\Api\LessonPlayerController as ApiLessonPlayerController;
use App\Http\Controllers\Api\LessonQuestionController as ApiLessonQuestionController;
use App\Http\Controllers\Api\LessonUploadController as ApiLessonUploadController;
use App\Http\Controllers\Api\LessonWhiteboardController as ApiLessonWhiteboardController;
use App\Http\Controllers\Api\PortalController as ApiPortalController;
use App\Http\Controllers\Api\PortalCourseController as ApiPortalCourseController;
use App\Http\Controllers\Api\PortalLessonController as ApiPortalLessonController;
use App\Http\Controllers\Api\JourneyController as ApiJourneyController;
use App\Http\Controllers\Api\NotificationController as ApiNotificationController;
use App\Http\Controllers\Api\TaskController as ApiTaskController;
use App\Http\Controllers\Api\AIAgentController as ApiAIAgentController;
use App\Http\Controllers\Api\ParentChatController as ApiParentChatController;
use App\Http\Controllers\Api\LiveSessions\LiveSessionController as ApiLiveSessionController;
use App\Http\Controllers\Api\LiveSessions\LiveSessionParticipantController as ApiLiveSessionParticipantController;
use App\Http\Controllers\Api\LiveSessions\LiveSessionMessageController as ApiLiveSessionMessageController;
use App\Http\Controllers\Api\LiveSessions\LiveSessionAccessController as ApiLiveSessionAccessController;
use App\Http\Controllers\Api\Content\CourseController as ApiCourseController;
use App\Http\Controllers\Api\Content\ModuleController as ApiModuleController;
use App\Http\Controllers\Api\Content\ContentLessonController as ApiContentLessonController;
use App\Http\Controllers\Api\Content\LessonSlideController as ApiLessonSlideController;
use App\Http\Controllers\Api\Content\AdminCourseController as ApiAdminCourseController;
use App\Http\Controllers\Api\Content\AdminModuleController as ApiAdminModuleController;
use App\Http\Controllers\Api\Content\AdminContentLessonController as ApiAdminContentLessonController;
use App\Http\Controllers\Api\Content\AdminLessonSlideController as ApiAdminLessonSlideController;
use App\Http\Controllers\Api\Content\ImageUploadController as ApiImageUploadController;
use App\Http\Controllers\Api\Content\TeacherCourseController as ApiTeacherCourseController;
use App\Http\Controllers\Api\Teacher\DashboardController as ApiTeacherDashboardController;
use App\Http\Controllers\Api\Teacher\LessonUploadController as ApiTeacherLessonUploadController;
use App\Http\Controllers\Api\Teacher\StudentController as ApiTeacherStudentController;
use App\Http\Controllers\Api\Teacher\TaskController as ApiTeacherTaskController;
use App\Http\Controllers\Api\Teacher\AttendanceController as ApiTeacherAttendanceController;
use App\Http\Controllers\Api\Teacher\RevenueController as ApiTeacherRevenueController;
use App\Http\Controllers\Api\Teacher\YearGroupController as ApiTeacherYearGroupController;
use App\Http\Controllers\Api\SuperAdmin\DashboardController as ApiSuperAdminDashboardController;
use App\Http\Controllers\Api\SuperAdmin\UserController as ApiSuperAdminUserController;
use App\Http\Controllers\Api\SuperAdmin\OrganizationController as ApiSuperAdminOrganizationController;
use App\Http\Controllers\Api\SuperAdmin\OrganizationBrandingController as ApiSuperAdminOrganizationBrandingController;
use App\Http\Controllers\Api\SuperAdmin\ContentController as ApiSuperAdminContentController;
use App\Http\Controllers\Api\SuperAdmin\SystemSettingsController as ApiSuperAdminSystemSettingsController;
use App\Http\Controllers\Api\SuperAdmin\BillingController as ApiSuperAdminBillingController;
use App\Http\Controllers\Api\SuperAdmin\AnalyticsController as ApiSuperAdminAnalyticsController;
use App\Http\Controllers\Api\SuperAdmin\LogsController as ApiSuperAdminLogsController;
use App\Http\Controllers\Api\Admin\SubscriptionController as ApiAdminSubscriptionController;
use App\Http\Controllers\Api\Admin\UserSubscriptionController as ApiAdminUserSubscriptionController;
use App\Http\Controllers\Api\Admin\Content\ArticleController as ApiAdminArticleController;
use App\Http\Controllers\Api\Admin\Content\FaqController as ApiAdminFaqController;
use App\Http\Controllers\Api\Admin\Content\AlertController as ApiAdminAlertController;
use App\Http\Controllers\Api\Admin\Content\SlideController as ApiAdminSlideController;
use App\Http\Controllers\Api\Admin\Content\TestimonialController as ApiAdminTestimonialController;
use App\Http\Controllers\Api\Admin\Content\MilestoneController as ApiAdminMilestoneController;
use App\Http\Controllers\Api\YearGroupSubscriptionController as ApiYearGroupSubscriptionController;
use App\Http\Controllers\Api\FeedbackController as ApiFeedbackController;
use App\Http\Controllers\Api\ParentFlagController as ApiParentFlagController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware(['throttle:api'])->group(function () {
    Route::prefix('v1')->group(function () {
        Route::prefix('auth')->group(function () {
            Route::post('/login', [AuthController::class, 'login'])->name('api.v1.auth.login');
            Route::post('/register', [AuthController::class, 'register'])->name('api.v1.auth.register');
            Route::post('/password/forgot', [AuthController::class, 'forgotPassword'])->name('api.v1.auth.password.forgot');
            Route::post('/password/reset', [AuthController::class, 'resetPassword'])->name('api.v1.auth.password.reset');
            Route::post('/teacher/send-otp', [TeacherRegistrationController::class, 'sendOtp'])->name('api.v1.auth.teacher.send-otp');
            Route::post('/teacher/verify-otp', [TeacherRegistrationController::class, 'verifyOtp'])->name('api.v1.auth.teacher.verify-otp');
            Route::post('/teacher/register', [TeacherRegistrationController::class, 'register'])->name('api.v1.auth.teacher.register');
            Route::get('/email/verify/{id}/{hash}', EmailVerificationController::class)
                ->middleware(['signed', 'throttle:6,1'])
                ->name('api.v1.auth.email.verify');
        });

        Route::prefix('public')->group(function () {
            Route::get('/home', [PublicContentController::class, 'home'])->name('api.v1.public.home');
            Route::get('/about', [PublicContentController::class, 'about'])->name('api.v1.public.about');
            Route::get('/contact', [PublicContentController::class, 'contact'])->name('api.v1.public.contact');
            Route::get('/pages', [PublicContentController::class, 'pages'])->name('api.v1.public.pages');
            Route::get('/articles', [PublicContentController::class, 'articles'])->name('api.v1.public.articles.index');
            Route::get('/articles/{article}', [PublicContentController::class, 'articleShow'])->name('api.v1.public.articles.show');
            Route::get('/faqs', [PublicContentController::class, 'faqs'])->name('api.v1.public.faqs.index');
            Route::get('/faqs/{faq}', [PublicContentController::class, 'faqShow'])->name('api.v1.public.faqs.show');
            Route::get('/alerts', [PublicContentController::class, 'alerts'])->name('api.v1.public.alerts.index');
            Route::get('/alerts/{alertId}', [PublicContentController::class, 'alertShow'])->name('api.v1.public.alerts.show');
            Route::get('/slides', [PublicContentController::class, 'slides'])->name('api.v1.public.slides.index');
            Route::get('/slides/{slideId}', [PublicContentController::class, 'slideShow'])->name('api.v1.public.slides.show');
            Route::get('/testimonials', [PublicContentController::class, 'testimonials'])->name('api.v1.public.testimonials.index');
            Route::get('/testimonials/{testimonialId}', [PublicContentController::class, 'testimonialShow'])->name('api.v1.public.testimonials.show');
            Route::get('/ape-academy', [ApiPublicApeAcademyController::class, 'index'])->name('api.v1.public.ape-academy');
        });

        Route::get('/testimonials', [PublicContentController::class, 'testimonials'])->name('api.v1.testimonials.index');

        Route::post('/applications', [ApiPublicApplicationController::class, 'store'])->name('api.v1.applications.store');
        Route::get('/applications/verify/{token}', [ApiPublicApplicationController::class, 'verify'])->name('api.v1.applications.verify');
        Route::post('/applications/resend-verification', [ApiPublicApplicationController::class, 'resendVerification'])
            ->name('api.v1.applications.resend');

        Route::get('/services', [ApiServiceController::class, 'index'])->name('api.v1.services.index');
        Route::get('/services/{service}', [ApiServiceController::class, 'show'])->name('api.v1.services.show');
        Route::get('/services/{service}/available-content', [ApiServiceController::class, 'availableContent'])->name('api.v1.services.available-content');
        Route::post('/services/{service}/selection', [ApiServiceController::class, 'selection'])->name('api.v1.services.selection');

        Route::get('/products', [ApiProductController::class, 'index'])->name('api.v1.products.index');
        Route::get('/products/{product}', [ApiProductController::class, 'show'])->name('api.v1.products.show');

        Route::get('/subscription-plans', [ApiSubscriptionCatalogController::class, 'index'])->name('api.v1.subscription-plans.index');

        Route::prefix('cart')->group(function () {
            Route::get('/', [ApiCartController::class, 'index'])->name('api.v1.cart.index');
            Route::post('/items', [ApiCartController::class, 'store'])->name('api.v1.cart.items.store');
            Route::post('/flexible', [ApiCartController::class, 'storeFlexible'])->name('api.v1.cart.flexible.store');
            Route::patch('/items/{item}', [ApiCartController::class, 'update'])->name('api.v1.cart.items.update');
            Route::delete('/items/{item}', [ApiCartController::class, 'destroy'])->name('api.v1.cart.items.destroy');
        });

        Route::prefix('checkout/guest')->group(function () {
            Route::post('/send-code', [ApiCheckoutController::class, 'sendGuestCode'])->name('api.v1.checkout.guest.send-code');
            Route::post('/verify-code', [ApiCheckoutController::class, 'verifyGuestCode'])->name('api.v1.checkout.guest.verify-code');
        });

        Route::post('/webhooks/billing', [\App\Http\Controllers\BillingWebhookController::class, 'handleInvoice'])
            ->name('api.v1.webhooks.billing');

        Route::get('/courses', [ApiCourseController::class, 'index'])->name('api.v1.courses.index');
        Route::get('/courses/{course}', [ApiCourseController::class, 'show'])->name('api.v1.courses.show');
        Route::get('/courses/{course}/modules', [ApiCourseController::class, 'modules'])->name('api.v1.courses.modules');
        Route::get('/modules/{module}', [ApiModuleController::class, 'show'])->name('api.v1.modules.show');
        Route::get('/modules/{module}/lessons', [ApiModuleController::class, 'lessons'])->name('api.v1.modules.lessons');
        Route::get('/modules/{module}/assessments', [ApiModuleController::class, 'assessments'])->name('api.v1.modules.assessments');
        Route::get('/content-lessons', [ApiContentLessonController::class, 'index'])->name('api.v1.content-lessons.index');
        Route::get('/content-lessons/{lesson}', [ApiContentLessonController::class, 'show'])->name('api.v1.content-lessons.show');
        Route::get('/content-lessons/{lesson}/slides', [ApiContentLessonController::class, 'slides'])->name('api.v1.content-lessons.slides');
        Route::get('/lesson-slides/{slide}', [ApiLessonSlideController::class, 'show'])->name('api.v1.lesson-slides.show');

        Route::prefix('admin')->middleware('role:admin,super_admin')->group(function () {
            Route::prefix('services')->group(function () {
                Route::get('/', [ApiAdminServiceController::class, 'index'])->name('api.v1.admin.services.index');
                Route::get('/create-data', [ApiAdminServiceController::class, 'createData'])->name('api.v1.admin.services.create-data');
                Route::post('/', [ApiAdminServiceController::class, 'store'])->name('api.v1.admin.services.store');
                Route::get('/{service}', [ApiAdminServiceController::class, 'show'])->name('api.v1.admin.services.show');
                Route::get('/{service}/edit-data', [ApiAdminServiceController::class, 'editData'])->name('api.v1.admin.services.edit-data');
                Route::put('/{service}', [ApiAdminServiceController::class, 'update'])->name('api.v1.admin.services.update');
                Route::delete('/{service}', [ApiAdminServiceController::class, 'destroy'])->name('api.v1.admin.services.destroy');
            });
            Route::prefix('feedbacks')->group(function () {
                Route::get('/', [ApiAdminFeedbackController::class, 'index'])->name('api.v1.admin.feedbacks.index');
                Route::post('/', [ApiAdminFeedbackController::class, 'store'])->name('api.v1.admin.feedbacks.store');
                Route::get('/{feedback}', [ApiAdminFeedbackController::class, 'show'])->name('api.v1.admin.feedbacks.show');
                Route::put('/{feedback}', [ApiAdminFeedbackController::class, 'update'])->name('api.v1.admin.feedbacks.update');
                Route::delete('/{feedback}', [ApiAdminFeedbackController::class, 'destroy'])->name('api.v1.admin.feedbacks.destroy');
            });
            Route::get('/journey-categories', [\App\Http\Controllers\Api\Admin\JourneyCategoryController::class, 'index'])
                ->name('api.v1.admin.journey-categories.index');
            Route::post('/journey-categories', [\App\Http\Controllers\Api\Admin\JourneyCategoryController::class, 'store'])
                ->name('api.v1.admin.journey-categories.store');
            Route::prefix('journeys')->group(function () {
                Route::get('/', [ApiAdminJourneyController::class, 'index'])->name('api.v1.admin.journeys.index');
                Route::post('/', [ApiAdminJourneyController::class, 'store'])->name('api.v1.admin.journeys.store');
                Route::get('/overview', [ApiAdminJourneyController::class, 'overview'])->name('api.v1.admin.journeys.overview');
            });
            Route::prefix('lessons')->group(function () {
                Route::get('/', [ApiAdminLessonController::class, 'index'])->name('api.v1.admin.lessons.index');
                Route::get('/assigned', [ApiAdminLessonController::class, 'assigned'])->name('api.v1.admin.lessons.assigned');
                Route::get('/create-data', [ApiAdminLessonController::class, 'createData'])->name('api.v1.admin.lessons.create-data');
                Route::post('/', [ApiAdminLessonController::class, 'store'])->name('api.v1.admin.lessons.store');
                Route::get('/{lesson}', [ApiAdminLessonController::class, 'show'])->name('api.v1.admin.lessons.show');
                Route::get('/{lesson}/edit-data', [ApiAdminLessonController::class, 'editData'])->name('api.v1.admin.lessons.edit-data');
                Route::put('/{lesson}', [ApiAdminLessonController::class, 'update'])->name('api.v1.admin.lessons.update');
                Route::delete('/{lesson}', [ApiAdminLessonController::class, 'destroy'])->name('api.v1.admin.lessons.destroy');
            });
            Route::prefix('courses')->group(function () {
                Route::get('/', [ApiAdminCourseController::class, 'index'])->name('api.v1.admin.courses.index');
                Route::get('/{course}', [ApiAdminCourseController::class, 'show'])->name('api.v1.admin.courses.show');
                Route::get('/{course}/edit-data', [ApiAdminCourseController::class, 'editData'])->name('api.v1.admin.courses.edit-data');
                Route::post('/', [ApiAdminCourseController::class, 'store'])->name('api.v1.admin.courses.store');
                Route::put('/{course}', [ApiAdminCourseController::class, 'update'])->name('api.v1.admin.courses.update');
                Route::delete('/{course}', [ApiAdminCourseController::class, 'destroy'])->name('api.v1.admin.courses.destroy');
                Route::post('/{course}/publish', [ApiAdminCourseController::class, 'publish'])->name('api.v1.admin.courses.publish');
                Route::post('/{course}/archive', [ApiAdminCourseController::class, 'archive'])->name('api.v1.admin.courses.archive');
                Route::post('/{course}/duplicate', [ApiAdminCourseController::class, 'duplicate'])->name('api.v1.admin.courses.duplicate');

                Route::post('/{course}/modules', [ApiAdminModuleController::class, 'store'])->name('api.v1.admin.modules.store');
                Route::post('/{course}/modules/reorder', [ApiAdminModuleController::class, 'reorder'])->name('api.v1.admin.modules.reorder');
            });

            Route::prefix('modules')->group(function () {
                Route::put('/{module}', [ApiAdminModuleController::class, 'update'])->name('api.v1.admin.modules.update');
                Route::delete('/{module}', [ApiAdminModuleController::class, 'destroy'])->name('api.v1.admin.modules.destroy');
                Route::post('/{module}/publish', [ApiAdminModuleController::class, 'publish'])->name('api.v1.admin.modules.publish');
                Route::post('/{module}/duplicate', [ApiAdminModuleController::class, 'duplicate'])->name('api.v1.admin.modules.duplicate');
                Route::post('/{module}/lessons/attach', [ApiAdminModuleController::class, 'attachLesson'])->name('api.v1.admin.modules.lessons.attach');
                Route::delete('/{module}/lessons/{lessonId}/detach', [ApiAdminModuleController::class, 'detachLesson'])->name('api.v1.admin.modules.lessons.detach');
                Route::post('/{module}/assessments/attach', [ApiAdminModuleController::class, 'attachAssessment'])->name('api.v1.admin.modules.assessments.attach');
                Route::delete('/{module}/assessments/{assessmentId}/detach', [ApiAdminModuleController::class, 'detachAssessment'])->name('api.v1.admin.modules.assessments.detach');
            });

            Route::prefix('content-lessons')->group(function () {
                Route::get('/', [ApiAdminContentLessonController::class, 'index'])->name('api.v1.admin.content-lessons.index');
                Route::get('/{lesson}', [ApiAdminContentLessonController::class, 'show'])->name('api.v1.admin.content-lessons.show');
                Route::post('/', [ApiAdminContentLessonController::class, 'storeStandalone'])->name('api.v1.admin.content-lessons.store-standalone');
                Route::post('/{module}', [ApiAdminContentLessonController::class, 'store'])->name('api.v1.admin.content-lessons.store');
                Route::put('/{lesson}', [ApiAdminContentLessonController::class, 'update'])->name('api.v1.admin.content-lessons.update');
                Route::delete('/{lesson}', [ApiAdminContentLessonController::class, 'destroy'])->name('api.v1.admin.content-lessons.destroy');
                Route::post('/{module}/reorder', [ApiAdminContentLessonController::class, 'reorder'])->name('api.v1.admin.content-lessons.reorder');
                Route::post('/{lesson}/publish', [ApiAdminContentLessonController::class, 'publish'])->name('api.v1.admin.content-lessons.publish');
                Route::post('/{lesson}/duplicate', [ApiAdminContentLessonController::class, 'duplicate'])->name('api.v1.admin.content-lessons.duplicate');
                Route::post('/{lesson}/assessments/attach', [ApiAdminContentLessonController::class, 'attachAssessment'])->name('api.v1.admin.content-lessons.assessments.attach');
                Route::delete('/{lesson}/assessments/{assessmentId}/detach', [ApiAdminContentLessonController::class, 'detachAssessment'])->name('api.v1.admin.content-lessons.assessments.detach');
            });

            Route::prefix('lesson-slides')->group(function () {
                Route::post('/{lesson}', [ApiAdminLessonSlideController::class, 'store'])->name('api.v1.admin.lesson-slides.store');
                Route::put('/{slide}', [ApiAdminLessonSlideController::class, 'update'])->name('api.v1.admin.lesson-slides.update');
                Route::delete('/{slide}', [ApiAdminLessonSlideController::class, 'destroy'])->name('api.v1.admin.lesson-slides.destroy');
                Route::post('/{lesson}/reorder', [ApiAdminLessonSlideController::class, 'reorder'])->name('api.v1.admin.lesson-slides.reorder');
                Route::post('/{slide}/duplicate', [ApiAdminLessonSlideController::class, 'duplicate'])->name('api.v1.admin.lesson-slides.duplicate');
                Route::post('/{slide}/blocks', [ApiAdminLessonSlideController::class, 'addBlock'])->name('api.v1.admin.lesson-slides.blocks.add');
                Route::put('/{slide}/blocks/{blockId}', [ApiAdminLessonSlideController::class, 'updateBlock'])->name('api.v1.admin.lesson-slides.blocks.update');
                Route::delete('/{slide}/blocks/{blockId}', [ApiAdminLessonSlideController::class, 'deleteBlock'])->name('api.v1.admin.lesson-slides.blocks.delete');
            });

            Route::post('/upload-image', [ApiImageUploadController::class, 'upload'])->name('api.v1.admin.upload-image');
            Route::prefix('notifications')->group(function () {
                Route::get('/', [ApiAdminNotificationController::class, 'index'])->name('api.v1.admin.notifications.index');
                Route::get('/create-data', [ApiAdminNotificationController::class, 'createData'])->name('api.v1.admin.notifications.create-data');
                Route::post('/', [ApiAdminNotificationController::class, 'store'])->name('api.v1.admin.notifications.store');
                Route::get('/{notification}', [ApiAdminNotificationController::class, 'show'])->name('api.v1.admin.notifications.show');
                Route::put('/{notification}', [ApiAdminNotificationController::class, 'update'])->name('api.v1.admin.notifications.update');
                Route::delete('/{notification}', [ApiAdminNotificationController::class, 'destroy'])->name('api.v1.admin.notifications.destroy');
            });

            Route::prefix('children')->group(function () {
                Route::get('/', [ApiAdminChildController::class, 'index'])->name('api.v1.admin.children.index');
                Route::get('/create-data', [ApiAdminChildController::class, 'createData'])->name('api.v1.admin.children.create-data');
                Route::post('/', [ApiAdminChildController::class, 'store'])->name('api.v1.admin.children.store');
                Route::get('/{child}', [ApiAdminChildController::class, 'show'])->name('api.v1.admin.children.show');
                Route::put('/{child}', [ApiAdminChildController::class, 'update'])->name('api.v1.admin.children.update');
                Route::delete('/{child}', [ApiAdminChildController::class, 'destroy'])->name('api.v1.admin.children.destroy');
            });

            Route::prefix('teachers')->group(function () {
                Route::get('/', [ApiAdminTeacherController::class, 'index'])->name('api.v1.admin.teachers.index');
                Route::get('/assignments', [ApiAdminTeacherController::class, 'assignments'])->name('api.v1.admin.teachers.assignments');
                Route::get('/{teacher}', [ApiAdminTeacherController::class, 'show'])->name('api.v1.admin.teachers.show');
            });

            Route::prefix('teacher-profiles')->group(function () {
                Route::get('/create-data', [ApiAdminTeacherController::class, 'createData'])->name('api.v1.admin.teacher-profiles.create-data');
                Route::post('/', [ApiAdminTeacherController::class, 'storeProfile'])->name('api.v1.admin.teacher-profiles.store');
                Route::get('/{teacher}', [ApiAdminTeacherController::class, 'showProfile'])->name('api.v1.admin.teacher-profiles.show');
                Route::get('/{teacher}/edit-data', [ApiAdminTeacherController::class, 'editData'])->name('api.v1.admin.teacher-profiles.edit-data');
                Route::put('/{teacher}', [ApiAdminTeacherController::class, 'updateProfile'])->name('api.v1.admin.teacher-profiles.update');
                Route::delete('/{teacher}', [ApiAdminTeacherController::class, 'destroyProfile'])->name('api.v1.admin.teacher-profiles.destroy');
            });
        });

        Route::prefix('teacher')->middleware('role:teacher,admin')->group(function () {
            Route::get('/courses', [ApiTeacherCourseController::class, 'index'])->name('api.v1.teacher.courses.index');
        });
    });
});

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    // Question search API for lesson block editor
    Route::get('/questions/search', [QuestionController::class, 'searchApi'])->name('api.questions.search');

    // Batch fetch questions by IDs (for lesson preview & player)
    Route::get('/questions/batch', [QuestionController::class, 'batchFetch'])->name('api.questions.batch');

    // Versioned API routes
        Route::prefix('v1')->group(function () {
            Route::post('/auth/email/resend', [AuthController::class, 'resendVerification'])
                ->name('api.v1.auth.email.resend');
            Route::post('/auth/password/confirm', [AuthController::class, 'confirmPassword'])
                ->name('api.v1.auth.password.confirm');

            Route::get('/questions/search', [QuestionController::class, 'searchApiV1'])->name('api.v1.questions.search');
            Route::get('/questions/batch', [QuestionController::class, 'batchFetchV1'])->name('api.v1.questions.batch');

        Route::post('/checkout', [ApiCheckoutController::class, 'store'])
            ->middleware('role:parent,guest_parent,admin,super_admin')
            ->name('api.v1.checkout.store');
        Route::post('/checkout/guest', [ApiCheckoutController::class, 'guestStore'])
            ->middleware('role:guest_parent,parent,admin,super_admin')
            ->name('api.v1.checkout.guest.store');

        Route::prefix('billing')->middleware('role:parent,guest_parent,admin,super_admin')->group(function () {
            Route::get('/setup', [ApiBillingController::class, 'setup'])->name('api.v1.billing.setup');
            Route::get('/invoices', [ApiBillingController::class, 'invoices'])->name('api.v1.billing.invoices');
            Route::get('/payments', [ApiBillingController::class, 'paymentMethods'])->name('api.v1.billing.payments');
            Route::get('/portal', [ApiBillingController::class, 'portal'])->name('api.v1.billing.portal');
        });

        Route::get('/transactions', [ApiTransactionController::class, 'index'])
            ->middleware('role:parent,guest_parent,admin,super_admin')
            ->name('api.v1.transactions.index');
        Route::post('/transactions', [ApiTransactionController::class, 'store'])
            ->middleware('role:admin,super_admin')
            ->name('api.v1.transactions.store');
        Route::get('/transactions/{transaction}', [ApiTransactionController::class, 'show'])
            ->middleware('role:parent,guest_parent,admin,super_admin')
            ->name('api.v1.transactions.show');
        Route::post('/transactions/{transaction}/autopay', [ApiTransactionController::class, 'autopay'])
            ->middleware('role:parent,guest_parent,admin,super_admin')
            ->name('api.v1.transactions.autopay');

        Route::post('/uploads/images', [ApiImageUploadController::class, 'upload'])
            ->middleware('role:admin,teacher,super_admin')
            ->name('api.v1.uploads.images');

        Route::get('/year-groups', [ApiAdminYearGroupController::class, 'index'])
            ->middleware('role:admin,super_admin')
            ->name('api.v1.year-groups.index');
        Route::post('/year-groups/bulk-update', [ApiAdminYearGroupController::class, 'bulkUpdate'])
            ->middleware('role:admin,super_admin')
            ->name('api.v1.year-groups.bulk-update');

        Route::prefix('attendance')->middleware('role:admin,super_admin')->group(function () {
            Route::get('/', [ApiAdminAttendanceController::class, 'overview'])->name('api.v1.attendance.overview');
            Route::get('/lessons/{lesson}', [ApiAdminAttendanceController::class, 'sheet'])->name('api.v1.attendance.sheet');
            Route::post('/lessons/{lesson}/mark-all', [ApiAdminAttendanceController::class, 'markAll'])->name('api.v1.attendance.mark-all');
            Route::post('/lessons/{lesson}/approve-all', [ApiAdminAttendanceController::class, 'approveAll'])->name('api.v1.attendance.approve-all');
            Route::post('/lessons/{lesson}/mark', [ApiAdminAttendanceController::class, 'mark'])->name('api.v1.attendance.mark');
            Route::post('/{attendance}/approve', [ApiAdminAttendanceController::class, 'approve'])->name('api.v1.attendance.approve');
        });

        Route::prefix('questions')->middleware('role:admin,teacher,super_admin')->group(function () {
            Route::get('/', [ApiQuestionBankController::class, 'index'])->name('api.v1.questions.index');
            Route::post('/', [ApiQuestionBankController::class, 'store'])->name('api.v1.questions.store');
            Route::post('/quick-create', [ApiQuestionBankController::class, 'quickCreate'])->name('api.v1.questions.quick-create');
            Route::get('/types/defaults', [ApiQuestionBankController::class, 'typeDefaults'])->name('api.v1.questions.types.defaults');
            Route::get('/{question}', [ApiQuestionBankController::class, 'show'])->name('api.v1.questions.show');
            Route::put('/{question}', [ApiQuestionBankController::class, 'update'])->name('api.v1.questions.update');
            Route::delete('/{question}', [ApiQuestionBankController::class, 'destroy'])->name('api.v1.questions.destroy');
        });

        Route::middleware('role:admin,teacher,super_admin')->group(function () {
            Route::get('/assessments', [ApiAssessmentController::class, 'index'])->name('api.v1.assessments.index');
            Route::post('/assessments', [ApiAssessmentController::class, 'store'])->name('api.v1.assessments.store');
            Route::get('/assessments/{assessment}', [ApiAssessmentController::class, 'show'])->name('api.v1.assessments.show');
            Route::put('/assessments/{assessment}', [ApiAssessmentController::class, 'update'])->name('api.v1.assessments.update');
            Route::delete('/assessments/{assessment}', [ApiAssessmentController::class, 'destroy'])->name('api.v1.assessments.destroy');

            Route::get('/assessments/{assessment}/questions', [ApiAssessmentQuestionController::class, 'index'])->name('api.v1.assessments.questions.index');
            Route::post('/assessments/{assessment}/questions/attach', [ApiAssessmentQuestionController::class, 'attach'])->name('api.v1.assessments.questions.attach');
            Route::delete('/assessments/{assessment}/questions/{question}', [ApiAssessmentQuestionController::class, 'detach'])->name('api.v1.assessments.questions.detach');
        });

        Route::post('/assessments/{assessment}/attempts', [ApiAssessmentAttemptController::class, 'start'])
            ->middleware('role:parent,guest_parent,admin,super_admin')
            ->name('api.v1.assessments.attempts.start');
        Route::post('/assessments/{assessment}/attempts/submit', [ApiAssessmentAttemptController::class, 'submit'])
            ->middleware('role:parent,guest_parent,admin,super_admin')
            ->name('api.v1.assessments.attempts.submit');

        Route::get('/submissions', [ApiSubmissionController::class, 'index'])->name('api.v1.submissions.index');
        Route::get('/submissions/{submission}', [ApiSubmissionController::class, 'show'])->name('api.v1.submissions.show');
        Route::patch('/submissions/{submission}', [ApiSubmissionController::class, 'update'])
            ->middleware('role:admin,teacher,super_admin')
            ->name('api.v1.submissions.update');

        Route::prefix('flags')->middleware('role:parent,guest_parent')->group(function () {
            Route::post('/', [ApiParentFlagController::class, 'store'])->name('api.v1.flags.store');
        });

        Route::prefix('homework')->group(function () {
            Route::get('/', [ApiHomeworkAssignmentController::class, 'index'])
                ->middleware('role:admin,teacher,super_admin')
                ->name('api.v1.homework.index');
            Route::post('/', [ApiHomeworkAssignmentController::class, 'store'])
                ->middleware('role:admin,teacher,super_admin')
                ->name('api.v1.homework.store');
            Route::get('/{homework}', [ApiHomeworkAssignmentController::class, 'show'])
                ->middleware('role:admin,teacher,super_admin')
                ->name('api.v1.homework.show');
            Route::put('/{homework}', [ApiHomeworkAssignmentController::class, 'update'])
                ->middleware('role:admin,teacher,super_admin')
                ->name('api.v1.homework.update');
            Route::delete('/{homework}', [ApiHomeworkAssignmentController::class, 'destroy'])
                ->middleware('role:admin,teacher,super_admin')
                ->name('api.v1.homework.destroy');

            Route::get('/{homework}/submissions', [ApiHomeworkSubmissionController::class, 'index'])
                ->middleware('role:parent,guest_parent,admin,teacher,super_admin')
                ->name('api.v1.homework.submissions.index');
            Route::post('/{homework}/submissions', [ApiHomeworkSubmissionController::class, 'store'])
                ->middleware('role:parent,guest_parent')
                ->name('api.v1.homework.submissions.store');
        });

        Route::prefix('homework/submissions')->group(function () {
            Route::get('/', [ApiHomeworkSubmissionController::class, 'indexAll'])
                ->middleware('role:admin,teacher,super_admin')
                ->name('api.v1.homework.submissions.index-all');
            Route::get('/{submission}', [ApiHomeworkSubmissionController::class, 'show'])
                ->middleware('role:parent,guest_parent,admin,teacher,super_admin')
                ->name('api.v1.homework.submissions.show');
            Route::put('/{submission}', [ApiHomeworkSubmissionController::class, 'update'])
                ->middleware('role:parent,guest_parent,admin,teacher,super_admin')
                ->name('api.v1.homework.submissions.update');
            Route::delete('/{submission}', [ApiHomeworkSubmissionController::class, 'destroy'])
                ->middleware('role:admin,teacher,super_admin')
                ->name('api.v1.homework.submissions.destroy');
        });

        Route::prefix('year-groups')->middleware('role:parent,guest_parent')->group(function () {
            Route::get('/subscriptions', [ApiYearGroupSubscriptionController::class, 'index'])
                ->name('api.v1.year-groups.subscriptions.index');
            Route::post('/subscriptions/assign', [ApiYearGroupSubscriptionController::class, 'assign'])
                ->name('api.v1.year-groups.subscriptions.assign');
        });

        Route::prefix('feedback')->middleware('role:parent,guest_parent')->group(function () {
            Route::get('/', [ApiFeedbackController::class, 'index'])->name('api.v1.feedback.index');
            Route::post('/', [ApiFeedbackController::class, 'store'])->name('api.v1.feedback.store');
        });

        Route::prefix('ai')->group(function () {
            Route::post('/chat', [ApiParentChatController::class, 'ask'])
                ->middleware(['role:parent,guest_parent,admin,teacher', 'subscription:ai_analysis', 'feature:parent.ai.chatbot'])
                ->name('api.v1.ai.chat.ask');
            Route::get('/chat/open', [ApiParentChatController::class, 'open'])
                ->middleware(['role:parent,guest_parent,admin,teacher', 'subscription:ai_analysis', 'feature:parent.ai.chatbot'])
                ->name('api.v1.ai.chat.open');
            Route::get('/chat/history', [ApiParentChatController::class, 'fetch'])
                ->middleware(['role:parent,guest_parent,admin,teacher', 'subscription:ai_analysis', 'feature:parent.ai.chatbot'])
                ->name('api.v1.ai.chat.history');
            Route::post('/hint-loop', [ApiParentChatController::class, 'hintLoop'])
                ->middleware(['role:parent,guest_parent,admin,teacher', 'subscription:ai_analysis', 'feature:parent.ai.chatbot'])
                ->name('api.v1.ai.hint-loop');

            Route::post('/tutor/chat', [ApiAIAgentController::class, 'tutorChat'])
                ->middleware(['role:parent,admin,teacher', 'feature:parent.ai.chatbot'])
                ->name('api.v1.ai.tutor.chat');
            Route::post('/grading/review', [ApiAIAgentController::class, 'gradingReview'])
                ->middleware('role:parent,admin,teacher')
                ->name('api.v1.ai.grading.review');
            Route::post('/progress/analyze', [ApiAIAgentController::class, 'progressAnalysis'])
                ->middleware(['role:parent,admin,teacher', 'feature:parent.ai.report_generation'])
                ->name('api.v1.ai.progress.analyze');
            Route::post('/hints/generate', [ApiAIAgentController::class, 'generateHint'])
                ->middleware(['role:parent,admin,teacher', 'feature:parent.ai.post_submission_help'])
                ->name('api.v1.ai.hints.generate');
            Route::post('/review/initiate', [ApiAIAgentController::class, 'initiateReviewChat'])
                ->middleware('role:parent,admin,teacher')
                ->name('api.v1.ai.review.initiate');
            Route::post('/review/chat', [ApiAIAgentController::class, 'reviewChat'])
                ->middleware('role:parent,admin,teacher')
                ->name('api.v1.ai.review.chat');

            Route::get('/sessions/{child_id}', [ApiAIAgentController::class, 'getSessions'])
                ->middleware('role:parent,admin,teacher')
                ->name('api.v1.ai.sessions');
            Route::get('/data-options/{child_id}', [ApiAIAgentController::class, 'getDataOptions'])
                ->middleware('role:parent,admin,teacher')
                ->name('api.v1.ai.data-options');
            Route::get('/conversations/{child_id}', [ApiAIAgentController::class, 'getConversations'])
                ->middleware('role:parent,admin,teacher')
                ->name('api.v1.ai.conversations');
            Route::get('/performance/{child_id}', [ApiAIAgentController::class, 'getPerformanceData'])
                ->middleware('role:parent,admin,teacher')
                ->name('api.v1.ai.performance');

            Route::get('/recommendations/{child_id}', [ApiAIAgentController::class, 'getRecommendations'])
                ->middleware('role:parent,admin,teacher')
                ->name('api.v1.ai.recommendations');
            Route::post('/recommendations/execute', [ApiAIAgentController::class, 'executeRecommendation'])
                ->middleware('role:parent,admin,teacher')
                ->name('api.v1.ai.recommendations.execute');
            Route::post('/recommendations/{id}/dismiss', [ApiAIAgentController::class, 'dismissRecommendation'])
                ->middleware('role:parent,admin,teacher')
                ->name('api.v1.ai.recommendations.dismiss');

            Route::get('/weakness-analysis/{child_id}', [ApiAIAgentController::class, 'getWeaknessAnalysis'])
                ->middleware('role:parent,admin,teacher')
                ->name('api.v1.ai.weakness-analysis');
            Route::post('/interventions/execute', [ApiAIAgentController::class, 'executeIntervention'])
                ->middleware('role:parent,admin,teacher')
                ->name('api.v1.ai.interventions.execute');

            Route::post('/contextual-prompts/generate', [ApiAIAgentController::class, 'generateContextualPrompts'])
                ->middleware('role:parent,admin,teacher')
                ->name('api.v1.ai.contextual-prompts.generate');
            Route::get('/prompt-library/{child_id}', [ApiAIAgentController::class, 'getPromptLibrary'])
                ->middleware('role:parent,admin,teacher')
                ->name('api.v1.ai.prompt-library');
            Route::post('/prompts/custom-generate', [ApiAIAgentController::class, 'generateCustomPrompt'])
                ->middleware('role:parent,admin,teacher')
                ->name('api.v1.ai.prompts.custom');
            Route::post('/prompts/execute', [ApiAIAgentController::class, 'executePrompt'])
                ->middleware('role:parent,admin,teacher')
                ->name('api.v1.ai.prompts.execute');

            Route::get('/learning-paths/{child_id}', [ApiAIAgentController::class, 'getLearningPaths'])
                ->middleware('role:parent,admin,teacher')
                ->name('api.v1.ai.learning-paths');
            Route::get('/learning-paths/{child_id}/progress', [ApiAIAgentController::class, 'getPathProgress'])
                ->middleware('role:parent,admin,teacher')
                ->name('api.v1.ai.learning-paths.progress');
            Route::post('/learning-paths/generate', [ApiAIAgentController::class, 'generateLearningPath'])
                ->middleware('role:parent,admin,teacher')
                ->name('api.v1.ai.learning-paths.generate');
            Route::post('/learning-paths/{path_id}/start', [ApiAIAgentController::class, 'startLearningPath'])
                ->middleware('role:parent,admin,teacher')
                ->name('api.v1.ai.learning-paths.start');
            Route::post('/learning-paths/{path_id}/steps/{step_id}/execute', [ApiAIAgentController::class, 'executePathStep'])
                ->middleware('role:parent,admin,teacher')
                ->name('api.v1.ai.learning-paths.step');

            Route::get('/agents/capabilities', [ApiAIAgentController::class, 'getCapabilities'])
                ->middleware('role:parent,admin,teacher')
                ->name('api.v1.ai.capabilities');

            Route::prefix('uploads')->middleware('role:admin,teacher,super_admin')->group(function () {
                Route::get('/', [ApiAdminAIUploadController::class, 'index'])->name('api.v1.ai.uploads.index');
                Route::post('/', [ApiAdminAIUploadController::class, 'store'])->name('api.v1.ai.uploads.store');
                Route::put('/proposals/{proposal}', [ApiAdminAIUploadController::class, 'updateProposal'])->name('api.v1.ai.uploads.proposals.update');
                Route::post('/proposals/{proposal}/refine', [ApiAdminAIUploadController::class, 'refineProposal'])->name('api.v1.ai.uploads.proposals.refine');
                Route::get('/{session}', [ApiAdminAIUploadController::class, 'show'])->name('api.v1.ai.uploads.show');
                Route::post('/{session}/cancel', [ApiAdminAIUploadController::class, 'cancel'])->name('api.v1.ai.uploads.cancel');
                Route::delete('/{session}', [ApiAdminAIUploadController::class, 'destroy'])->name('api.v1.ai.uploads.destroy');
                Route::get('/{session}/logs', [ApiAdminAIUploadController::class, 'logs'])->name('api.v1.ai.uploads.logs');
                Route::post('/{session}/approve', [ApiAdminAIUploadController::class, 'approveProposals'])->name('api.v1.ai.uploads.approve');
                Route::post('/{session}/reject', [ApiAdminAIUploadController::class, 'rejectProposals'])->name('api.v1.ai.uploads.reject');
                Route::post('/{session}/upload', [ApiAdminAIUploadController::class, 'upload'])->name('api.v1.ai.uploads.upload');
            });

            Route::prefix('flags')->middleware('role:admin,teacher,super_admin')->group(function () {
                Route::get('/', [ApiAdminFlagController::class, 'index'])->name('api.v1.ai.flags.index');
                Route::get('/stats', [ApiAdminFlagController::class, 'stats'])->name('api.v1.ai.flags.stats');
                Route::get('/{flag}', [ApiAdminFlagController::class, 'show'])->name('api.v1.ai.flags.show');
                Route::post('/{flag}/resolve', [ApiAdminFlagController::class, 'resolve'])->name('api.v1.ai.flags.resolve');
                Route::post('/bulk-resolve', [ApiAdminFlagController::class, 'bulkResolve'])->name('api.v1.ai.flags.bulk-resolve');
            });
        });

        Route::prefix('portal')->middleware('role:parent,guest_parent,admin,super_admin')->group(function () {
            Route::get('/overview', [ApiAssessmentPortalController::class, 'overview'])->name('api.v1.portal.overview');
            Route::get('/assessments/browse', [ApiAssessmentPortalController::class, 'browse'])->name('api.v1.portal.assessments.browse');
            Route::get('/dashboard', [ApiPortalController::class, 'dashboard'])->name('api.v1.portal.dashboard');
            Route::get('/schedule', [ApiPortalController::class, 'schedule'])->name('api.v1.portal.schedule');
            Route::get('/deadlines', [ApiPortalController::class, 'deadlines'])->name('api.v1.portal.deadlines');
            Route::get('/calendar-feed', [ApiPortalController::class, 'calendarFeed'])->name('api.v1.portal.calendar-feed');
            Route::get('/tracker', [ApiPortalController::class, 'tracker'])->name('api.v1.portal.tracker');
            Route::get('/courses', [ApiPortalCourseController::class, 'browse'])->name('api.v1.portal.courses.browse');
            Route::get('/courses/my', [ApiPortalCourseController::class, 'myCourses'])->name('api.v1.portal.courses.my');
            Route::get('/courses/{course}', [ApiPortalCourseController::class, 'show'])->name('api.v1.portal.courses.show');
            Route::get('/lessons/{lesson}', [ApiPortalLessonController::class, 'show'])->name('api.v1.portal.lessons.show');
            Route::post('/lessons/{lesson}/attendance', [ApiPortalLessonController::class, 'storeAttendance'])->name('api.v1.portal.lessons.attendance.store');
        });

        Route::get('/journeys', [ApiJourneyController::class, 'index'])
            ->middleware('role:parent,guest_parent,admin,super_admin')
            ->name('api.v1.journeys.index');

        Route::prefix('notifications')->middleware('role:parent,guest_parent,admin,super_admin')->group(function () {
            Route::get('/', [ApiNotificationController::class, 'index'])->name('api.v1.notifications.index');
            Route::get('/unread', [ApiNotificationController::class, 'unread'])->name('api.v1.notifications.unread');
            Route::patch('/{id}/read', [ApiNotificationController::class, 'markRead'])->name('api.v1.notifications.read');
            Route::patch('/read-all', [ApiNotificationController::class, 'markAllRead'])->name('api.v1.notifications.read-all');
        });

        Route::prefix('tasks')->middleware('role:parent,guest_parent,admin,super_admin')->group(function () {
            Route::get('/', [ApiTaskController::class, 'index'])->name('api.v1.tasks.index');
            Route::post('/', [ApiTaskController::class, 'store'])->name('api.v1.tasks.store');
            Route::get('/{task}', [ApiTaskController::class, 'show'])->name('api.v1.tasks.show');
            Route::put('/{task}', [ApiTaskController::class, 'update'])->name('api.v1.tasks.update');
            Route::delete('/{task}', [ApiTaskController::class, 'destroy'])->name('api.v1.tasks.destroy');
        });

        Route::prefix('lesson-player')->middleware('role:parent,guest_parent,admin,super_admin')->group(function () {
            Route::post('/{lesson}/start', [ApiLessonPlayerController::class, 'start'])->name('api.v1.lesson-player.start');
            Route::get('/{lesson}', [ApiLessonPlayerController::class, 'show'])->name('api.v1.lesson-player.show');
            Route::get('/{lesson}/summary', [ApiLessonPlayerController::class, 'summary'])->name('api.v1.lesson-player.summary');

            Route::get('/{lesson}/slides/{slide}', [ApiLessonPlayerController::class, 'getSlide'])->name('api.v1.lesson-player.slides.show');
            Route::post('/{lesson}/slides/{slide}/view', [ApiLessonPlayerController::class, 'recordSlideView'])->name('api.v1.lesson-player.slides.view');
            Route::post('/{lesson}/slides/{slide}/interaction', [ApiLessonPlayerController::class, 'recordInteraction'])->name('api.v1.lesson-player.slides.interaction');
            Route::post('/{lesson}/slides/{slide}/confidence', [ApiLessonPlayerController::class, 'submitConfidence'])->name('api.v1.lesson-player.slides.confidence');

            Route::post('/{lesson}/progress', [ApiLessonPlayerController::class, 'updateProgress'])->name('api.v1.lesson-player.progress.update');
            Route::post('/{lesson}/complete', [ApiLessonPlayerController::class, 'complete'])->name('api.v1.lesson-player.complete');

            Route::post('/{lesson}/slides/{slide}/questions/submit', [ApiLessonQuestionController::class, 'submitResponse'])->name('api.v1.lesson-player.questions.submit');
            Route::get('/{lesson}/questions/responses', [ApiLessonQuestionController::class, 'getResponses'])->name('api.v1.lesson-player.questions.responses');
            Route::get('/{lesson}/slides/{slide}/questions/responses', [ApiLessonQuestionController::class, 'getSlideResponses'])->name('api.v1.lesson-player.questions.slide-responses');
            Route::post('/{lesson}/questions/{response}/retry', [ApiLessonQuestionController::class, 'retryQuestion'])->name('api.v1.lesson-player.questions.retry');

            Route::post('/{lesson}/slides/{slide}/upload', [ApiLessonUploadController::class, 'upload'])->name('api.v1.lesson-player.uploads.submit');
            Route::get('/{lesson}/uploads', [ApiLessonUploadController::class, 'index'])->name('api.v1.lesson-player.uploads.index');
            Route::get('/uploads/{upload}', [ApiLessonUploadController::class, 'show'])->name('api.v1.lesson-player.uploads.show');
            Route::delete('/uploads/{upload}', [ApiLessonUploadController::class, 'destroy'])->name('api.v1.lesson-player.uploads.delete');
        });

        Route::middleware('role:parent,guest_parent,admin,super_admin')->group(function () {
            Route::post('/lesson-slides/{slide}/whiteboard/save', [ApiLessonWhiteboardController::class, 'save'])->name('api.v1.lesson-slides.whiteboard.save');
            Route::get('/lesson-slides/{slide}/whiteboard/load', [ApiLessonWhiteboardController::class, 'load'])->name('api.v1.lesson-slides.whiteboard.load');
        });

        Route::prefix('live-sessions')->group(function () {
            Route::middleware('role:parent,guest_parent,admin,super_admin')->group(function () {
                Route::get('/browse', [ApiLiveSessionAccessController::class, 'browse'])->name('api.v1.live-sessions.browse');
                Route::get('/my', [ApiLiveSessionAccessController::class, 'mySessions'])->name('api.v1.live-sessions.my');
                Route::get('/my/{session}', [ApiLiveSessionAccessController::class, 'mySessionShow'])->name('api.v1.live-sessions.my.show');
            });

            Route::middleware('role:admin,teacher,super_admin')->group(function () {
                Route::get('/', [ApiLiveSessionController::class, 'index'])->name('api.v1.live-sessions.index');
                Route::post('/', [ApiLiveSessionController::class, 'store'])->name('api.v1.live-sessions.store');
                Route::get('/{session}', [ApiLiveSessionController::class, 'show'])->name('api.v1.live-sessions.show');
                Route::get('/{session}/teach', [ApiLiveSessionController::class, 'teach'])->name('api.v1.live-sessions.teach');
                Route::put('/{session}', [ApiLiveSessionController::class, 'update'])->name('api.v1.live-sessions.update');
                Route::delete('/{session}', [ApiLiveSessionController::class, 'destroy'])->name('api.v1.live-sessions.destroy');
                Route::post('/{session}/start', [ApiLiveSessionController::class, 'start'])->name('api.v1.live-sessions.start');
                Route::post('/{session}/state', [ApiLiveSessionController::class, 'changeState'])->name('api.v1.live-sessions.state');
                Route::post('/{session}/slide', [ApiLiveSessionController::class, 'changeSlide'])->name('api.v1.live-sessions.slide');
                Route::post('/{session}/highlight', [ApiLiveSessionController::class, 'highlightBlock'])->name('api.v1.live-sessions.highlight');
                Route::post('/{session}/annotation', [ApiLiveSessionController::class, 'sendAnnotation'])->name('api.v1.live-sessions.annotation');
                Route::delete('/{session}/annotation', [ApiLiveSessionController::class, 'clearAnnotations'])->name('api.v1.live-sessions.annotation.clear');
                Route::post('/{session}/navigation-lock', [ApiLiveSessionController::class, 'toggleNavigationLock'])->name('api.v1.live-sessions.navigation-lock');
                Route::get('/{session}/participants', [ApiLiveSessionController::class, 'participants'])->name('api.v1.live-sessions.participants');
            });

            Route::middleware('role:parent,guest_parent,admin,teacher,super_admin')->group(function () {
                Route::post('/{session}/join', [ApiLiveSessionParticipantController::class, 'join'])->name('api.v1.live-sessions.join');
                Route::post('/{session}/leave', [ApiLiveSessionParticipantController::class, 'leave'])->name('api.v1.live-sessions.leave');
                Route::post('/{session}/hand', [ApiLiveSessionParticipantController::class, 'raiseHand'])->name('api.v1.live-sessions.hand');
                Route::post('/{session}/reaction', [ApiLiveSessionParticipantController::class, 'sendReaction'])->name('api.v1.live-sessions.reaction');
                Route::post('/{session}/token', [ApiLiveSessionController::class, 'liveKitToken'])->name('api.v1.live-sessions.token');
                Route::get('/{session}/messages', [ApiLiveSessionMessageController::class, 'index'])->name('api.v1.live-sessions.messages.index');
                Route::post('/{session}/messages', [ApiLiveSessionMessageController::class, 'store'])->name('api.v1.live-sessions.messages.store');
            });

            Route::middleware('role:admin,teacher,super_admin')->group(function () {
                Route::post('/{session}/messages/{message}/answer', [ApiLiveSessionMessageController::class, 'answer'])->name('api.v1.live-sessions.messages.answer');
                Route::post('/{session}/participants/{participant}/mute', [ApiLiveSessionParticipantController::class, 'muteParticipant'])->name('api.v1.live-sessions.participants.mute');
                Route::post('/{session}/participants/{participant}/lower-hand', [ApiLiveSessionParticipantController::class, 'lowerHand'])->name('api.v1.live-sessions.participants.lower-hand');
                Route::post('/{session}/participants/{participant}/camera', [ApiLiveSessionParticipantController::class, 'disableCamera'])->name('api.v1.live-sessions.participants.camera');
                Route::post('/{session}/participants/mute-all', [ApiLiveSessionParticipantController::class, 'muteAll'])->name('api.v1.live-sessions.participants.mute-all');
                Route::post('/{session}/participants/{participant}/kick', [ApiLiveSessionParticipantController::class, 'kick'])->name('api.v1.live-sessions.participants.kick');
            });
        });

        Route::prefix('auth')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');
            Route::get('/me', [AuthController::class, 'me'])->name('api.v1.auth.me');
            Route::post('/email/resend', [AuthController::class, 'resendVerification'])->name('api.v1.auth.email.resend');
            Route::get('/guest/onboarding', [GuestOnboardingController::class, 'show'])->name('api.v1.auth.guest.onboarding.show');
            Route::post('/guest/onboarding', [GuestOnboardingController::class, 'store'])->name('api.v1.auth.guest.onboarding.store');
        });

        Route::get('/me', [ProfileController::class, 'show'])->name('api.v1.me.show');
        Route::patch('/me', [ProfileController::class, 'update'])->name('api.v1.me.update');
        Route::put('/me/password', [ProfileController::class, 'updatePassword'])->name('api.v1.me.password');
        Route::delete('/me', [ProfileController::class, 'destroy'])->name('api.v1.me.destroy');

        Route::prefix('organizations')->group(function () {
            Route::get('/', [OrganizationController::class, 'index'])->name('api.v1.organizations.index');
            Route::post('/', [OrganizationController::class, 'store'])->name('api.v1.organizations.store');
            Route::get('/{organization}', [OrganizationController::class, 'show'])->name('api.v1.organizations.show');
            Route::put('/{organization}', [OrganizationController::class, 'update'])->name('api.v1.organizations.update');
            Route::delete('/{organization}', [OrganizationController::class, 'destroy'])->name('api.v1.organizations.destroy');
            Route::post('/switch', [OrganizationController::class, 'switch'])->name('api.v1.organizations.switch');
            Route::get('/{organization}/users', [OrganizationController::class, 'users'])->name('api.v1.organizations.users');
            Route::post('/{organization}/users', [OrganizationController::class, 'addUser'])->name('api.v1.organizations.users.add');
            Route::put('/{organization}/users/{user}/role', [OrganizationController::class, 'updateUserRole'])->name('api.v1.organizations.users.role');
            Route::delete('/{organization}/users/{user}', [OrganizationController::class, 'removeUser'])->name('api.v1.organizations.users.remove');
            Route::get('/{organization}/features', [OrganizationController::class, 'features'])->name('api.v1.organizations.features');
            Route::put('/{organization}/features', [OrganizationController::class, 'updateFeatures'])->name('api.v1.organizations.features.update');
        });

        Route::prefix('admin')->middleware('role:admin,super_admin')->group(function () {
            Route::get('/dashboard', [ApiAdminDashboardController::class, 'index'])->name('api.v1.admin.dashboard');

            Route::prefix('subscriptions')->group(function () {
                Route::get('/', [ApiAdminSubscriptionController::class, 'index'])->name('api.v1.admin.subscriptions.index');
                Route::post('/', [ApiAdminSubscriptionController::class, 'store'])->name('api.v1.admin.subscriptions.store');
                Route::get('/{subscription}', [ApiAdminSubscriptionController::class, 'show'])->name('api.v1.admin.subscriptions.show');
                Route::put('/{subscription}', [ApiAdminSubscriptionController::class, 'update'])->name('api.v1.admin.subscriptions.update');
                Route::delete('/{subscription}', [ApiAdminSubscriptionController::class, 'destroy'])->name('api.v1.admin.subscriptions.destroy');
            });

            Route::prefix('products')->group(function () {
                Route::get('/', [ApiAdminProductController::class, 'index'])->name('api.v1.admin.products.index');
                Route::post('/', [ApiAdminProductController::class, 'store'])->name('api.v1.admin.products.store');
                Route::get('/{product}', [ApiAdminProductController::class, 'show'])->name('api.v1.admin.products.show');
                Route::put('/{product}', [ApiAdminProductController::class, 'update'])->name('api.v1.admin.products.update');
                Route::delete('/{product}', [ApiAdminProductController::class, 'destroy'])->name('api.v1.admin.products.destroy');
            });

            Route::get('/teachers', [ApiAdminTeacherController::class, 'index'])->name('api.v1.admin.teachers.index');

            Route::prefix('user-subscriptions')->group(function () {
                Route::get('/', [ApiAdminUserSubscriptionController::class, 'index'])->name('api.v1.admin.user-subscriptions.index');
                Route::post('/', [ApiAdminUserSubscriptionController::class, 'store'])->name('api.v1.admin.user-subscriptions.store');
                Route::delete('/{pivot}', [ApiAdminUserSubscriptionController::class, 'destroy'])->name('api.v1.admin.user-subscriptions.destroy');
            });

            Route::prefix('content')->group(function () {
                Route::get('/articles', [ApiAdminArticleController::class, 'index'])->name('api.v1.admin.content.articles.index');
                Route::post('/articles', [ApiAdminArticleController::class, 'store'])->name('api.v1.admin.content.articles.store');
                Route::get('/articles/{article}', [ApiAdminArticleController::class, 'show'])->name('api.v1.admin.content.articles.show');
                Route::put('/articles/{article}', [ApiAdminArticleController::class, 'update'])->name('api.v1.admin.content.articles.update');
                Route::delete('/articles/{article}', [ApiAdminArticleController::class, 'destroy'])->name('api.v1.admin.content.articles.destroy');

                Route::get('/faqs', [ApiAdminFaqController::class, 'index'])->name('api.v1.admin.content.faqs.index');
                Route::post('/faqs', [ApiAdminFaqController::class, 'store'])->name('api.v1.admin.content.faqs.store');
                Route::get('/faqs/{faq}', [ApiAdminFaqController::class, 'show'])->name('api.v1.admin.content.faqs.show');
                Route::put('/faqs/{faq}', [ApiAdminFaqController::class, 'update'])->name('api.v1.admin.content.faqs.update');
                Route::delete('/faqs/{faq}', [ApiAdminFaqController::class, 'destroy'])->name('api.v1.admin.content.faqs.destroy');

                Route::get('/alerts', [ApiAdminAlertController::class, 'index'])->name('api.v1.admin.content.alerts.index');
                Route::post('/alerts', [ApiAdminAlertController::class, 'store'])->name('api.v1.admin.content.alerts.store');
                Route::get('/alerts/{alertId}', [ApiAdminAlertController::class, 'show'])->name('api.v1.admin.content.alerts.show');
                Route::put('/alerts/{alertId}', [ApiAdminAlertController::class, 'update'])->name('api.v1.admin.content.alerts.update');
                Route::delete('/alerts/{alertId}', [ApiAdminAlertController::class, 'destroy'])->name('api.v1.admin.content.alerts.destroy');

                Route::get('/slides', [ApiAdminSlideController::class, 'index'])->name('api.v1.admin.content.slides.index');
                Route::post('/slides', [ApiAdminSlideController::class, 'store'])->name('api.v1.admin.content.slides.store');
                Route::get('/slides/{slideId}', [ApiAdminSlideController::class, 'show'])->name('api.v1.admin.content.slides.show');
                Route::put('/slides/{slideId}', [ApiAdminSlideController::class, 'update'])->name('api.v1.admin.content.slides.update');
                Route::delete('/slides/{slideId}', [ApiAdminSlideController::class, 'destroy'])->name('api.v1.admin.content.slides.destroy');

                Route::get('/testimonials', [ApiAdminTestimonialController::class, 'index'])->name('api.v1.admin.content.testimonials.index');
                Route::post('/testimonials', [ApiAdminTestimonialController::class, 'store'])->name('api.v1.admin.content.testimonials.store');
                Route::get('/testimonials/{testimonial}', [ApiAdminTestimonialController::class, 'show'])->name('api.v1.admin.content.testimonials.show');
                Route::put('/testimonials/{testimonial}', [ApiAdminTestimonialController::class, 'update'])->name('api.v1.admin.content.testimonials.update');
                Route::delete('/testimonials/{testimonial}', [ApiAdminTestimonialController::class, 'destroy'])->name('api.v1.admin.content.testimonials.destroy');

                Route::get('/milestones', [ApiAdminMilestoneController::class, 'index'])->name('api.v1.admin.content.milestones.index');
                Route::post('/milestones', [ApiAdminMilestoneController::class, 'store'])->name('api.v1.admin.content.milestones.store');
                Route::get('/milestones/{milestone}', [ApiAdminMilestoneController::class, 'show'])->name('api.v1.admin.content.milestones.show');
                Route::put('/milestones/{milestone}', [ApiAdminMilestoneController::class, 'update'])->name('api.v1.admin.content.milestones.update');
                Route::delete('/milestones/{milestone}', [ApiAdminMilestoneController::class, 'destroy'])->name('api.v1.admin.content.milestones.destroy');
            });

            Route::prefix('tasks')->group(function () {
                Route::get('/', [ApiAdminTaskController::class, 'index'])->name('api.v1.admin.tasks.index');
                Route::post('/', [ApiAdminTaskController::class, 'store'])->name('api.v1.admin.tasks.store');
                Route::get('/{task}', [ApiAdminTaskController::class, 'show'])->name('api.v1.admin.tasks.show');
                Route::put('/{task}', [ApiAdminTaskController::class, 'update'])->name('api.v1.admin.tasks.update');
                Route::delete('/{task}', [ApiAdminTaskController::class, 'destroy'])->name('api.v1.admin.tasks.destroy');
            });

            Route::prefix('teacher-student-assignments')->group(function () {
                Route::get('/data', [ApiTeacherStudentAssignmentController::class, 'data'])->name('api.v1.admin.teacher-student-assignments.data');
                Route::get('/assignments', [ApiTeacherStudentAssignmentController::class, 'assignments'])->name('api.v1.admin.teacher-student-assignments.assignments');
                Route::post('/assign', [ApiTeacherStudentAssignmentController::class, 'assign'])->name('api.v1.admin.teacher-student-assignments.assign');
                Route::post('/bulk-assign', [ApiTeacherStudentAssignmentController::class, 'bulkAssign'])->name('api.v1.admin.teacher-student-assignments.bulk-assign');
                Route::post('/unassign', [ApiTeacherStudentAssignmentController::class, 'unassign'])->name('api.v1.admin.teacher-student-assignments.unassign');
                Route::delete('/{assignment}', [ApiTeacherStudentAssignmentController::class, 'destroy'])->name('api.v1.admin.teacher-student-assignments.destroy');
            });

            Route::prefix('year-groups')->group(function () {
                Route::get('/', [ApiAdminYearGroupController::class, 'index'])->name('api.v1.admin.year-groups.index');
                Route::post('/bulk-update', [ApiAdminYearGroupController::class, 'bulkUpdate'])->name('api.v1.admin.year-groups.bulk-update');
            });

            Route::prefix('lesson-uploads')->group(function () {
                Route::get('/', [ApiAdminLessonUploadController::class, 'index'])->name('api.v1.admin.lesson-uploads.index');
                Route::get('/{upload}', [ApiAdminLessonUploadController::class, 'show'])->name('api.v1.admin.lesson-uploads.show');
                Route::post('/{upload}/grade', [ApiAdminLessonUploadController::class, 'grade'])->name('api.v1.admin.lesson-uploads.grade');
                Route::post('/{upload}/feedback', [ApiAdminLessonUploadController::class, 'feedback'])->name('api.v1.admin.lesson-uploads.feedback');
                Route::post('/{upload}/return', [ApiAdminLessonUploadController::class, 'returnToStudent'])->name('api.v1.admin.lesson-uploads.return');
                Route::post('/{upload}/ai-analysis', [ApiAdminLessonUploadController::class, 'requestAIAnalysis'])->name('api.v1.admin.lesson-uploads.ai-analysis');
                Route::delete('/{upload}', [ApiAdminLessonUploadController::class, 'destroy'])->name('api.v1.admin.lesson-uploads.destroy');
            });

            Route::prefix('attendance')->group(function () {
                Route::get('/', [ApiAdminAttendanceController::class, 'overview'])->name('api.v1.admin.attendance.overview');
                Route::get('/lesson/{lesson}', [ApiAdminAttendanceController::class, 'sheet'])->name('api.v1.admin.attendance.sheet');
                Route::post('/lessons/{lesson}/mark-all', [ApiAdminAttendanceController::class, 'markAll'])->name('api.v1.admin.attendance.mark-all');
                Route::post('/lessons/{lesson}/approve-all', [ApiAdminAttendanceController::class, 'approveAll'])->name('api.v1.admin.attendance.approve-all');
                Route::post('/{attendance}/approve', [ApiAdminAttendanceController::class, 'approve'])->name('api.v1.admin.attendance.approve');
            });

            Route::prefix('ai-upload')->group(function () {
                Route::get('/', [ApiAdminAIUploadController::class, 'index'])->name('api.v1.admin.ai-upload.index');
                Route::post('/', [ApiAdminAIUploadController::class, 'store'])->name('api.v1.admin.ai-upload.store');
                Route::put('/proposals/{proposal}', [ApiAdminAIUploadController::class, 'updateProposal'])->name('api.v1.admin.ai-upload.proposals.update');
                Route::post('/proposals/{proposal}/refine', [ApiAdminAIUploadController::class, 'refineProposal'])->name('api.v1.admin.ai-upload.proposals.refine');
                Route::get('/{session}', [ApiAdminAIUploadController::class, 'show'])->name('api.v1.admin.ai-upload.show');
                Route::post('/{session}/cancel', [ApiAdminAIUploadController::class, 'cancel'])->name('api.v1.admin.ai-upload.cancel');
                Route::delete('/{session}', [ApiAdminAIUploadController::class, 'destroy'])->name('api.v1.admin.ai-upload.destroy');
                Route::get('/{session}/logs', [ApiAdminAIUploadController::class, 'logs'])->name('api.v1.admin.ai-upload.logs');
                Route::post('/{session}/approve', [ApiAdminAIUploadController::class, 'approveProposals'])->name('api.v1.admin.ai-upload.approve');
                Route::post('/{session}/reject', [ApiAdminAIUploadController::class, 'rejectProposals'])->name('api.v1.admin.ai-upload.reject');
                Route::post('/{session}/upload', [ApiAdminAIUploadController::class, 'upload'])->name('api.v1.admin.ai-upload.upload');
            });

            Route::prefix('flags')->group(function () {
                Route::get('/', [ApiAdminFlagController::class, 'index'])->name('api.v1.admin.flags.index');
                Route::get('/stats', [ApiAdminFlagController::class, 'stats'])->name('api.v1.admin.flags.stats');
                Route::get('/{flag}', [ApiAdminFlagController::class, 'show'])->name('api.v1.admin.flags.show');
                Route::post('/{flag}/resolve', [ApiAdminFlagController::class, 'resolve'])->name('api.v1.admin.flags.resolve');
                Route::post('/bulk-resolve', [ApiAdminFlagController::class, 'bulkResolve'])->name('api.v1.admin.flags.bulk-resolve');
            });

            Route::prefix('applications')->group(function () {
                Route::get('/', [ApiAdminApplicationController::class, 'index'])->name('api.v1.admin.applications.index');
                Route::get('/{application}', [ApiAdminApplicationController::class, 'show'])->name('api.v1.admin.applications.show');
                Route::put('/{application}/review', [ApiAdminApplicationController::class, 'review'])->name('api.v1.admin.applications.review');
                Route::delete('/{application}', [ApiAdminApplicationController::class, 'destroy'])->name('api.v1.admin.applications.destroy');
            });

            Route::prefix('teacher-applications')->group(function () {
                Route::get('/', [ApiTeacherApplicationController::class, 'index'])->name('api.v1.admin.teacher-applications.index');
                Route::post('/{task}/approve', [ApiTeacherApplicationController::class, 'approve'])->name('api.v1.admin.teacher-applications.approve');
                Route::post('/{task}/reject', [ApiTeacherApplicationController::class, 'reject'])->name('api.v1.admin.teacher-applications.reject');
            });

            Route::prefix('portal-feedbacks')->group(function () {
                Route::get('/', [ApiAdminPortalFeedbackController::class, 'index'])->name('api.v1.admin.portal-feedbacks.index');
                Route::get('/{feedback}', [ApiAdminPortalFeedbackController::class, 'show'])->name('api.v1.admin.portal-feedbacks.show');
                Route::put('/{feedback}', [ApiAdminPortalFeedbackController::class, 'update'])->name('api.v1.admin.portal-feedbacks.update');
            });

            Route::get('/access', [AdminAccessController::class, 'index'])->name('api.v1.admin.access.index');
            Route::post('/access/grant', [AdminAccessController::class, 'store'])->name('api.v1.admin.access.grant');
            Route::post('/access', [AdminAccessController::class, 'store'])->name('api.v1.admin.access.store');
            Route::put('/access/{access}', [AdminAccessController::class, 'update'])->name('api.v1.admin.access.update');
        });

        Route::prefix('superadmin')->middleware('role:super_admin')->group(function () {
            Route::get('/dashboard', [ApiSuperAdminDashboardController::class, 'index'])->name('api.v1.superadmin.dashboard');
            Route::get('/stats', [ApiSuperAdminDashboardController::class, 'stats'])->name('api.v1.superadmin.stats');

            Route::prefix('users')->group(function () {
                Route::get('/', [ApiSuperAdminUserController::class, 'index'])->name('api.v1.superadmin.users.index');
                Route::post('/', [ApiSuperAdminUserController::class, 'store'])->name('api.v1.superadmin.users.store');
                Route::get('/{user}', [ApiSuperAdminUserController::class, 'show'])->name('api.v1.superadmin.users.show');
                Route::put('/{user}', [ApiSuperAdminUserController::class, 'update'])->name('api.v1.superadmin.users.update');
                Route::delete('/{user}', [ApiSuperAdminUserController::class, 'destroy'])->name('api.v1.superadmin.users.destroy');
                Route::post('/{user}/change-role', [ApiSuperAdminUserController::class, 'changeRole'])->name('api.v1.superadmin.users.change-role');
                Route::post('/{user}/toggle-status', [ApiSuperAdminUserController::class, 'toggleStatus'])->name('api.v1.superadmin.users.toggle-status');
                Route::post('/{user}/impersonate', [ApiSuperAdminUserController::class, 'impersonate'])->name('api.v1.superadmin.users.impersonate');
                Route::post('/bulk-action', [ApiSuperAdminUserController::class, 'bulkAction'])->name('api.v1.superadmin.users.bulk-action');
            });

            Route::prefix('organizations')->group(function () {
                Route::get('/', [ApiSuperAdminOrganizationController::class, 'index'])->name('api.v1.superadmin.organizations.index');
                Route::post('/', [ApiSuperAdminOrganizationController::class, 'store'])->name('api.v1.superadmin.organizations.store');
                Route::get('/{organization}', [ApiSuperAdminOrganizationController::class, 'show'])->name('api.v1.superadmin.organizations.show');
                Route::put('/{organization}', [ApiSuperAdminOrganizationController::class, 'update'])->name('api.v1.superadmin.organizations.update');
                Route::delete('/{organization}', [ApiSuperAdminOrganizationController::class, 'destroy'])->name('api.v1.superadmin.organizations.destroy');
                Route::get('/{organization}/users', [ApiSuperAdminOrganizationController::class, 'users'])->name('api.v1.superadmin.organizations.users');
                Route::post('/{organization}/users', [ApiSuperAdminOrganizationController::class, 'addUser'])->name('api.v1.superadmin.organizations.users.add');
                Route::delete('/{organization}/users/{user}', [ApiSuperAdminOrganizationController::class, 'removeUser'])->name('api.v1.superadmin.organizations.users.remove');
                Route::put('/{organization}/users/{user}/role', [ApiSuperAdminOrganizationController::class, 'changeUserRole'])->name('api.v1.superadmin.organizations.users.role');

                Route::put('/{organization}/branding', [ApiSuperAdminOrganizationBrandingController::class, 'update'])->name('api.v1.superadmin.organizations.branding.update');
                Route::post('/{organization}/branding/logo', [ApiSuperAdminOrganizationBrandingController::class, 'uploadLogo'])->name('api.v1.superadmin.organizations.branding.logo');
                Route::post('/{organization}/branding/favicon', [ApiSuperAdminOrganizationBrandingController::class, 'uploadFavicon'])->name('api.v1.superadmin.organizations.branding.favicon');
                Route::delete('/{organization}/branding/asset', [ApiSuperAdminOrganizationBrandingController::class, 'deleteAsset'])->name('api.v1.superadmin.organizations.branding.delete-asset');
            });

            Route::prefix('content')->group(function () {
                Route::get('/courses', [ApiSuperAdminContentController::class, 'courses'])->name('api.v1.superadmin.content.courses');
                Route::get('/lessons', [ApiSuperAdminContentController::class, 'lessons'])->name('api.v1.superadmin.content.lessons');
                Route::get('/assessments', [ApiSuperAdminContentController::class, 'assessments'])->name('api.v1.superadmin.content.assessments');
                Route::get('/services', [ApiSuperAdminContentController::class, 'services'])->name('api.v1.superadmin.content.services');
                Route::get('/articles', [ApiSuperAdminContentController::class, 'articles'])->name('api.v1.superadmin.content.articles');
                Route::get('/moderation', [ApiSuperAdminContentController::class, 'moderation'])->name('api.v1.superadmin.content.moderation');
                Route::post('/{type}/{id}/feature', [ApiSuperAdminContentController::class, 'feature'])->name('api.v1.superadmin.content.feature');
                Route::post('/{type}/{id}/unfeature', [ApiSuperAdminContentController::class, 'unfeature'])->name('api.v1.superadmin.content.unfeature');
                Route::delete('/{type}/{id}', [ApiSuperAdminContentController::class, 'delete'])->name('api.v1.superadmin.content.delete');
            });

            Route::prefix('system')->group(function () {
                Route::get('/settings', [ApiSuperAdminSystemSettingsController::class, 'index'])->name('api.v1.superadmin.system.settings');
                Route::post('/settings', [ApiSuperAdminSystemSettingsController::class, 'update'])->name('api.v1.superadmin.system.settings.update');
                Route::get('/features', [ApiSuperAdminSystemSettingsController::class, 'featureFlags'])->name('api.v1.superadmin.system.features');
                Route::post('/features/{flag}/toggle', [ApiSuperAdminSystemSettingsController::class, 'toggleFeature'])->name('api.v1.superadmin.system.features.toggle');
                Route::get('/integrations', [ApiSuperAdminSystemSettingsController::class, 'integrations'])->name('api.v1.superadmin.system.integrations');
                Route::get('/email-templates', [ApiSuperAdminSystemSettingsController::class, 'emailTemplates'])->name('api.v1.superadmin.system.email-templates');
                Route::get('/api-keys', [ApiSuperAdminSystemSettingsController::class, 'apiKeys'])->name('api.v1.superadmin.system.api-keys');
                Route::post('/backup', [ApiSuperAdminSystemSettingsController::class, 'backup'])->name('api.v1.superadmin.system.backup');
                Route::post('/restore', [ApiSuperAdminSystemSettingsController::class, 'restore'])->name('api.v1.superadmin.system.restore');
            });

            Route::prefix('billing')->group(function () {
                Route::get('/overview', [ApiSuperAdminBillingController::class, 'overview'])->name('api.v1.superadmin.billing.overview');
                Route::get('/transactions', [ApiSuperAdminBillingController::class, 'transactions'])->name('api.v1.superadmin.billing.transactions');
                Route::get('/subscriptions', [ApiSuperAdminBillingController::class, 'subscriptions'])->name('api.v1.superadmin.billing.subscriptions');
                Route::get('/revenue', [ApiSuperAdminBillingController::class, 'revenue'])->name('api.v1.superadmin.billing.revenue');
                Route::get('/invoices', [ApiSuperAdminBillingController::class, 'invoices'])->name('api.v1.superadmin.billing.invoices');
                Route::post('/refund/{transaction}', [ApiSuperAdminBillingController::class, 'refund'])->name('api.v1.superadmin.billing.refund');
                Route::get('/export', [ApiSuperAdminBillingController::class, 'export'])->name('api.v1.superadmin.billing.export');
            });

            Route::prefix('analytics')->group(function () {
                Route::get('/dashboard', [ApiSuperAdminAnalyticsController::class, 'dashboard'])->name('api.v1.superadmin.analytics.dashboard');
                Route::get('/users', [ApiSuperAdminAnalyticsController::class, 'users'])->name('api.v1.superadmin.analytics.users');
                Route::get('/content', [ApiSuperAdminAnalyticsController::class, 'content'])->name('api.v1.superadmin.analytics.content');
                Route::get('/engagement', [ApiSuperAdminAnalyticsController::class, 'engagement'])->name('api.v1.superadmin.analytics.engagement');
                Route::post('/custom-report', [ApiSuperAdminAnalyticsController::class, 'customReport'])->name('api.v1.superadmin.analytics.custom-report');
            });

            Route::prefix('logs')->group(function () {
                Route::get('/system', [ApiSuperAdminLogsController::class, 'system'])->name('api.v1.superadmin.logs.system');
                Route::get('/user-activity', [ApiSuperAdminLogsController::class, 'userActivity'])->name('api.v1.superadmin.logs.user-activity');
                Route::get('/errors', [ApiSuperAdminLogsController::class, 'errors'])->name('api.v1.superadmin.logs.errors');
                Route::get('/audit', [ApiSuperAdminLogsController::class, 'audit'])->name('api.v1.superadmin.logs.audit');
                Route::get('/performance', [ApiSuperAdminLogsController::class, 'performance'])->name('api.v1.superadmin.logs.performance');
            });
        });

        Route::prefix('teacher')->middleware('role:teacher,admin')->group(function () {
            Route::get('/dashboard', [ApiTeacherDashboardController::class, 'index'])->name('api.v1.teacher.dashboard');
            Route::get('/students', [ApiTeacherStudentController::class, 'index'])->name('api.v1.teacher.students.index');
            Route::get('/students/{child}', [ApiTeacherStudentController::class, 'show'])->name('api.v1.teacher.students.show');
            Route::get('/assignments', [ApiTeacherStudentController::class, 'index'])->name('api.v1.teacher.assignments');
            Route::get('/revenue', [ApiTeacherRevenueController::class, 'index'])
                ->middleware('feature:teacher.revenue_dashboard')
                ->name('api.v1.teacher.revenue');

            Route::prefix('lesson-uploads')->group(function () {
                Route::get('/', [ApiTeacherLessonUploadController::class, 'index'])->name('api.v1.teacher.lesson-uploads.index');
                Route::get('/{upload}', [ApiTeacherLessonUploadController::class, 'show'])->name('api.v1.teacher.lesson-uploads.show');
                Route::post('/{upload}/grade', [ApiTeacherLessonUploadController::class, 'grade'])->name('api.v1.teacher.lesson-uploads.grade');
                Route::post('/{upload}/feedback', [ApiTeacherLessonUploadController::class, 'feedback'])->name('api.v1.teacher.lesson-uploads.feedback');
            });

            Route::prefix('attendance')->group(function () {
                Route::get('/', [ApiTeacherAttendanceController::class, 'overview'])->name('api.v1.teacher.attendance.overview');
                Route::get('/lesson/{lesson}', [ApiTeacherAttendanceController::class, 'sheet'])->name('api.v1.teacher.attendance.sheet');
                Route::post('/lessons/{lesson}/mark-all', [ApiTeacherAttendanceController::class, 'markAll'])->name('api.v1.teacher.attendance.mark-all');
                Route::post('/lessons/{lesson}/approve-all', [ApiTeacherAttendanceController::class, 'approveAll'])->name('api.v1.teacher.attendance.approve-all');
                Route::post('/lessons/{lesson}/mark', [ApiTeacherAttendanceController::class, 'mark'])->name('api.v1.teacher.attendance.mark');
            });

            Route::prefix('tasks')->group(function () {
                Route::get('/', [ApiTeacherTaskController::class, 'index'])->name('api.v1.teacher.tasks.index');
                Route::get('/pending-count', [ApiTeacherTaskController::class, 'pendingCount'])->name('api.v1.teacher.tasks.pending-count');
                Route::get('/{task}', [ApiTeacherTaskController::class, 'show'])->name('api.v1.teacher.tasks.show');
                Route::put('/{task}/status', [ApiTeacherTaskController::class, 'updateStatus'])->name('api.v1.teacher.tasks.update-status');
            });

            Route::prefix('year-groups')->group(function () {
                Route::get('/', [ApiTeacherYearGroupController::class, 'index'])->name('api.v1.teacher.year-groups.index');
                Route::post('/bulk-update', [ApiTeacherYearGroupController::class, 'bulkUpdate'])->name('api.v1.teacher.year-groups.bulk-update');
            });
        });
    });
});
