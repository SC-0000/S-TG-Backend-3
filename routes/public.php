<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AboutController;
use App\Models\Faq;


use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
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
use App\Http\Controllers\AIChatController;
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
use App\Http\Controllers\BillingController;
use App\Http\Controllers\PublicService;
use App\Http\Controllers\PublicServiceController;

Route::get('/billing-widget-demo', function (\Illuminate\Http\Request $request) {
    $apiKey = $request->query('api_key');
    $customerId = $request->query('customer_id');
    return view('billing-widget-demo', [
        'apiKey' => $apiKey,
        'customerId' => $customerId,
    ]);
});

Route::get('/subscription-widget-demo', function (\Illuminate\Http\Request $request) {
    $apiKey = $request->query('api_key');
    $customerId = $request->query('customer_id');
    return view('subscription-widget-demo', [
        'apiKey' => $apiKey,
        'customerId' => $customerId,
    ]);
});

Route::get('/portal-widget-demo', function (\Illuminate\Http\Request $request) {
    $apiKey = $request->query('api_key');
    $customerId = $request->query('customer_id');
    return view('portal-widget-demo', [
        'apiKey' => $apiKey,
        'customerId' => $customerId,
    ]);
});

Route::get('/payment-widget-demo', function (\Illuminate\Http\Request $request) {
    $apiKey = $request->query('api_key');
    $customerId = $request->query('customer_id');
    $invoiceId = $request->query('invoice_id');
    return view('payment-widget-demo', [
        'apiKey' => $apiKey,
        'customerId' => $customerId,
        'invoiceId' => $invoiceId,
    ]);
});

Route::view('/payment-widget', 'app-api');

Route::view('/widget-test', 'app-api')->name('widget.test');

//route for receipt-widget-demo
Route::get('/receipt-widget-demo', function (\Illuminate\Http\Request $request) {
    $apiKey = $request->query('api_key');
    $customerId = $request->query('customer_id');
    $invoiceId = $request->query('invoice_id'); // Add this line
    return view('receipt-widget-demo', [
        'apiKey' => $apiKey,
        'customerId' => $customerId,
        'invoiceId' => $invoiceId, // Pass the invoice ID to the view
    ]);
});




Route::view('/login', 'app-api')
     ->middleware('guest')
     ->name('login');

// handle login submission
Route::post('/login', [AuthenticatedSessionController::class, 'store'])
     ->middleware('guest');

Route::view('/billing/setup', 'app-api')->name('billing.setup');
Route::view('/billing/pay/{invoice}', 'app-api')->name('billing.pay');
Route::view('/billing/invoice', 'app-api')->name('billing.invoice');
Route::view('/billing/subs', 'app-api')->name('billing.subs');
Route::view('/billing/receipt/{invoice}', 'app-api')->name('billing.receipt');


// Webhook endpoint for billing provider to notify invoice/payment events
Route::post('/webhooks/billing', [\App\Http\Controllers\BillingWebhookController::class, 'handleInvoice'])
    ->name('webhooks.billing');

// Public "My Purchases" page for logged-in parents / guest_parent accounts
Route::view('/my-purchases', 'app-api')
    ->name('my.purchases');
Route::view('/billing/portal', 'app-api')->name('billing.portal');
Route::post('/transaction/{transaction}/enable-autopay', [TransactionController::class, 'enableAutopay']);

Route::post('/ai/public/ask', [AIChatController::class, 'ask']);

Route::post('/feedbacks', [FeedbackController::class, 'store'])->name('feedbacks.store');

Route::view('/feedback/success/{feedback}', 'app-api')->name('feedback.success');

Route::get('/cart', [CartController::class, 'getCart'])->name('cart.index');
Route::post('/cart/add/{type}/{id}', [CartController::class, 'addToCart'])
     ->whereIn('type', ['service','product'])->name('cart.add');
Route::post('/cart/add-flexible', [CartController::class, 'addFlexibleService'])->name('cart.add.flexible');
Route::delete('/cart/remove/{id}', [CartController::class, 'removeFromCart'])->name('cart.remove');
Route::post('/cart/update/{cartItemId}', [CartController::class, 'updateQuantity'])->name('cart.update');

Route::view('/portal/notifications', 'app-api')
         ->name('notifications.portal.index');

    // mark one as read
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markRead'])
         ->name('notifications.read');

    // (optional) mark all as read
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead'])
         ->name('notifications.readAll');
         Route::get('/notifications/unread', [NotificationController::class, 'unread'])
     ->name('notifications.unread');


Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');
Route::view('/checkout', 'app-api')->name('checkout.show');

// Pre-check endpoint: create a guest user + child before showing the checkout page.
// The frontend should call this when the user opens the checkout (pre-check modal).
Route::post('/checkout/create-guest', [CheckoutController::class, 'createGuest'])
    ->middleware('throttle:6,1') // limit to 6 requests per minute per IP/email to reduce abuse
    ->name('checkout.createGuest');

// Send verification code (AJAX) before creating guest account
Route::post('/checkout/send-code', [CheckoutController::class, 'sendGuestCode'])
    ->middleware('throttle:6,1')
    ->name('checkout.sendGuestCode');

// Verify code and create guest account + children in a single operation (AJAX)
Route::post('/checkout/verify-code', [CheckoutController::class, 'verifyGuestCode'])
    // ->middleware('throttle:10,1')
    ->name('checkout.verifyGuestCode');

// Guest checkout flow: create transaction + invoice and present billing widget for guest payment.
// This route is used when a guest_parent wants to complete checkout and pay via billing widget.
Route::post('/checkout/guest-store', [CheckoutController::class, 'guestStore'])
    ->name('checkout.guestStore');


// Route::resource('milestones', MilestoneController::class);

Route::view('/testimonials/{id}', 'app-api')
    ->name('testimonials.show');


Route::view('/transactions', 'app-api')->name('transactions.index');
Route::view('/transactions/{transaction}', 'app-api')->name('transactions.show');

Route::post('checkout', [CheckoutController::class, 'store'])
     ->name('checkout.store');

Route::view('/assessments', 'app-api')
     ->name('assessments.index');
Route::view('/assessments/create', 'app-api')
     ->name('assessments.create');
Route::view('/assessments/{assessment}', 'app-api')
     ->name('assessments.show');
Route::view('/assessments/{assessment}/edit', 'app-api')
     ->name('assessments.edit');
Route::view('/assessments/{assessment}/attempt', 'app-api')
     ->name('assessments.attempt');
// Route::get('/portal/assessments', [AssessmentController::class, 'portalIndex'])
//          ->middleware('role:parent,admin')
//          ->name('portal.assessments.index');

/* ---------- result screen ---------- */
Route::view('submissions/{submission}', 'app-api')
     ->name('submissions.show');


Route::view('/products', 'app-api')->name('products.index');
Route::view('/products/create', 'app-api')->name('products.create');
Route::view('/products/{product}', 'app-api')->name('products.show');
Route::view('/products/{product}/edit', 'app-api')->name('products.edit');

// Old lesson routes disabled - conflicts with new ContentLesson player routes
// Route::resource('lessons', LessonController::class);

Route::view('/children', 'app-api')->name('children.index');
Route::view('/children/create', 'app-api')->name('children.create');
Route::view('/children/{child}', 'app-api')->name('children.show');
Route::view('/children/{child}/edit', 'app-api')->name('children.edit');


Route::view('/applications/create', 'app-api')->name('applications.create');
Route::post('/applications', [ApplicationController::class, 'store'])->middleware('throttle:6,1')->name('applications.store');
Route::view('/applications/verify/{token}', 'app-api')->name('application.verify');
Route::post('/application/resend-verification', [ApplicationController::class, 'resendVerificationEmail'])->middleware('throttle:6,1')->name('application.resend_verification');
Route::view('/application/verification', 'app-api')->name('application.verification');
Route::view('/email/verified', 'app-api')->name('email.verified');
Route::middleware(['auth', 'role:super_admin'])->group(function () {
    Route::view('/applications', 'app-api')
        ->name('applications.index');
    Route::view('/applications/{id}/edit', 'app-api')
        ->name('applications.edit');
    Route::put('/applications/{id}', [ApplicationController::class, 'reviewApplication'])->name('applications.review');
    Route::delete('/applications/{id}', [ApplicationController::class, 'destroy'])->name('applications.destroy');
    Route::view('/applications/{id}', 'app-api')
        ->name('applications.show');
});

Route::view('/feedbacks/{id}', 'app-api')
    ->name('feedbacks.show');

Route::view('/faqs/{id}', 'app-api')
    ->name('faqs.show');

Route::view('/slides/{slide_id}', 'app-api')
    ->name('slides.show');


Route::view('/alerts/{id}', 'app-api')
    ->name('alerts.show');

Route::view('/articles/{id}', 'app-api')->name('articles.show');
Route::view('/articles/{id}/edit', 'app-api')
    ->name('articles.edit');



Route::view('/about', 'app-api')->name('about');
Route::view('/contact', 'app-api')->name('contact');
// Route::get('/contact', [MilestoneController::class, 'aboutUs'])->name('milestones.index');



    Route::middleware(['auth', 'role:super_admin'])->group(function () {
        Route::view('register', 'app-api')->name('register');

        Route::post('register', [RegisteredUserController::class, 'store']);
    });

    // Teacher Registration Routes (with OTP verification)
    Route::view('/teacher/register', 'app-api')->name('teacher.register.form');
    
    Route::post('/teacher/send-otp', [\App\Http\Controllers\TeacherController::class, 'sendOtp'])
        ->name('teacher.sendOtp');
    Route::post('/teacher/verify-otp', [\App\Http\Controllers\TeacherController::class, 'verifyOtp'])
        ->name('teacher.verifyOtp');
    Route::post('/teacher/register', [\App\Http\Controllers\TeacherController::class, 'register'])
        ->name('teacher.register');



    Route::view('authenticate-user', 'app-api')
        ->name('authenticate-user');

    Route::post('authenticate-user', [AuthenticatedSessionController::class, 'store']);

    Route::view('forgot-password', 'app-api')
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::view('reset-password/{token}', 'app-api')
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');



    Route::view('confirm-password', 'app-api')
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');





Route::view('/portal/assessments/browse', 'app-api')
         ->name('portal.assessments.browse');

//
// Guest onboarding: when a guest_parent attempts to access a parent-only page they will be
// redirected to /guest/complete-profile. The controller will show a form to complete the
// remaining parent + children details and then upgrade their role to 'parent'.
Route::view('/guest/complete-profile', 'app-api')
    ->name('guest.complete_profile');

Route::post('/guest/complete-profile', [\App\Http\Controllers\GuestOnboardingController::class, 'store'])
    ->name('guest.complete_profile.store');

Route::view('/portal/products', 'app-api')
     ->name('portal.products.index');

// Old lesson routes disabled - conflicts with new ContentLesson player routes
// Route::resource('lessons', LessonController::class);
Route::view('/portal/lessons/browse', 'app-api')
         ->name('portal.lessons.browse');

Route::view('/portal/faqs', 'app-api')
         ->name('portal.faqs.index');

        Route::view('/portal/services', 'app-api')
     ->name('portal.services.index');
  Route::view('/portal/services/{service}', 'app-api')
     ->name('portal.services.show');


Route::view('/', 'app-api')->name('home');

Route::view('/services', 'app-api')->name('services.index');
Route::view('/services/{service}', 'app-api')->name('services.show');

Route::view('/articles', 'app-api')->name('articles.index');

// Subscription Catalog - Public page showing all subscriptions with course previews
Route::view('/subscription-plans', 'app-api')
     ->name('subscriptions.catalog');

// SPA entrypoints for authenticated areas (Phase 6)
Route::view('/portal/{path?}', 'app-api')->where('path', '.*');
Route::view('/courses/{path?}', 'app-api')->where('path', '.*');
Route::view('/lessons/{path?}', 'app-api')->where('path', '.*');
Route::view('/assessments/{path?}', 'app-api')->where('path', '.*');
Route::view('/submissions/{path?}', 'app-api')->where('path', '.*');
Route::view('/live-sessions/{path?}', 'app-api')->where('path', '.*');
Route::view('/my-live-sessions/{path?}', 'app-api')->where('path', '.*');
Route::view('/flags/{path?}', 'app-api')->where('path', '.*');

Route::view('/admin-dashboard', 'app-api')->name('admin.dashboard');
Route::view('/admin-dashboard/debug', 'app-api')->name('admin.dashboard.debug');
Route::view('/admin-tasks/{path?}', 'app-api')->where('path', '.*');
Route::view('/admin/{path?}', 'app-api')->where('path', '.*');
Route::view('/notifications/{path?}', 'app-api')->where('path', '.*');
Route::view('/subscriptions/{path?}', 'app-api')->where('path', '.*');
Route::view('/teachers/{path?}', 'app-api')->where('path', '.*');
Route::view('/organizations/{path?}', 'app-api')->where('path', '.*');
Route::view('/journeys/{path?}', 'app-api')->where('path', '.*');
Route::view('/journey-categories/{path?}', 'app-api')->where('path', '.*');
Route::view('/attendance/{path?}', 'app-api')->where('path', '.*');
Route::view('/homework/{path?}', 'app-api')->where('path', '.*');
Route::view('/admin/homework/{path?}', 'app-api')->where('path', '.*');
Route::view('/homework/submissions/{path?}', 'app-api')->where('path', '.*');
Route::view('/admin/homework-submissions/{path?}', 'app-api')->where('path', '.*');
Route::view('/tasks/{path?}', 'app-api')->where('path', '.*');
Route::view('/feedbacks/{path?}', 'app-api')->where('path', '.*');
Route::view('/faqs/{path?}', 'app-api')->where('path', '.*');
Route::view('/alerts/{path?}', 'app-api')->where('path', '.*');
Route::view('/slides/{path?}', 'app-api')->where('path', '.*');
Route::view('/milestones/{path?}', 'app-api')->where('path', '.*');
Route::view('/testimonials/{path?}', 'app-api')->where('path', '.*');
Route::view('/user-subscriptions/{path?}', 'app-api')->where('path', '.*');
Route::view('/users/{user}/subscriptions', 'app-api');
Route::view('/access/{path?}', 'app-api')->where('path', '.*');
Route::view('/questions/{path?}', 'app-api')->where('path', '.*');

Route::view('/teacher/{path?}', 'app-api')->where('path', '.*');

Route::view('/superadmin/{path?}', 'app-api')->where('path', '.*');

// API: Check if the user has an active payment method set up
Route::get('/api/check-payment-method-setup', [BillingController::class, 'checkPaymentMethodSetup'])->middleware('auth');
