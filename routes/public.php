<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AboutController;
use App\Models\Faq;


use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Inertia\Inertia;
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

Route::get('/payment-widget', function (\Illuminate\Http\Request $request) {
    $apiKey = $request->query('api_key');
    $customerId = $request->query('customer_id');
    $invoiceId = $request->query('invoice_id');
    $returnTo = $request->query('return_to') ?? $request->query('returnTo') ?? route('checkout.show');

    return Inertia::render('@public/Payments/PaymentWidget', [
        'apiKey' => $apiKey,
        'customerId' => $customerId,
        'invoiceId' => $invoiceId,
        'returnTo' => $returnTo,
    ]);
});

Route::get('/widget-test', function () {
    return Inertia::render('@public/Main/WidgetTestPage');
})->name('widget.test');

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




Route::get('/login', [AuthenticatedSessionController::class, 'create'])
     ->middleware('guest')
     ->name('login');

// handle login submission
Route::post('/login', [AuthenticatedSessionController::class, 'store'])
     ->middleware('guest');

     Route::get('/billing/setup',          [BillingController::class,'setup'])->name('billing.setup');
Route::get('/billing/pay/{invoice}',  [BillingController::class,'pay'])->name('billing.pay');
Route::get('/billing/invoice',        [BillingController::class,'createInvoice'])
    ->middleware('role:admin')->name('billing.invoice');
Route::get('/billing/subs',           [BillingController::class,'subscriptions'])->name('billing.subs');
Route::get('/billing/receipt/{invoice}', [BillingController::class,'receipt'])->name('billing.receipt');


// Webhook endpoint for billing provider to notify invoice/payment events
Route::post('/webhooks/billing', [\App\Http\Controllers\BillingWebhookController::class, 'handleInvoice'])
    ->name('webhooks.billing');

// Public "My Purchases" page for logged-in parents / guest_parent accounts
Route::get('/my-purchases', [\App\Http\Controllers\PortalController::class, 'transactionsIndex'])
    ->middleware('auth')
    ->name('my.purchases');
Route::get('/billing/portal',         [BillingController::class,'portal'])->name('billing.portal');
Route::post('/transaction/{transaction}/enable-autopay', [TransactionController::class, 'enableAutopay']);

Route::post('/ai/public/ask', [AIChatController::class, 'ask']);

Route::post('/feedbacks', [FeedbackController::class, 'store'])->name('feedbacks.store');

Route::get('/feedback/success/{feedback}', [FeedbackController::class, 'success'])->name('feedback.success');

Route::get('/cart', [CartController::class, 'getCart'])->name('cart.index');
Route::post('/cart/add/{type}/{id}', [CartController::class, 'addToCart'])
     ->whereIn('type', ['service','product'])->name('cart.add');
Route::post('/cart/add-flexible', [CartController::class, 'addFlexibleService'])->name('cart.add.flexible');
Route::delete('/cart/remove/{id}', [CartController::class, 'removeFromCart'])->name('cart.remove');
Route::post('/cart/update/{cartItemId}', [CartController::class, 'updateQuantity'])->name('cart.update');

Route::get('/portal/notifications', [NotificationController::class, 'portalIndex'])
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
Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout.show');

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

Route::get('/testimonials/{id}', [TestimonialController::class, 'show'])->name('testimonials.show');


Route::resource('transactions', TransactionController::class)
     ->only(['index', 'show']);   // expose more actions if needed
    // expose more actions if needed

Route::post('checkout', [CheckoutController::class, 'store'])
     ->name('checkout.store');

Route::resource('assessments', AssessmentController::class)
     ->names([
         'index'   => 'assessments.index',
         'create'  => 'assessments.create',
         'store'   => 'assessments.store',
         'show'    => 'assessments.show',
         'edit'    => 'assessments.edit',
         'update'  => 'assessments.update',
         'destroy' => 'assessments.destroy',
     ])->middleware('role:parent,admin');   // uses {assessment} param automatically; restrict to parents/admins (guest_parent allowed only for whitelisted routes)

/* ---------- attempt & submit ---------- */
Route::prefix('assessments/{assessment}')->group(function () {
    Route::get('attempt',  [AssessmentController::class, 'attempt'])
         ->middleware('role:parent,admin')
         ->name('assessments.attempt');

    Route::post('attempt', [AssessmentController::class, 'attemptSubmit'])
         ->middleware('role:parent,admin')
         ->name('assessments.attemptSubmit');   // <-- matches React code
});
// Route::get('/portal/assessments', [AssessmentController::class, 'portalIndex'])
//          ->middleware('role:parent,admin')
//          ->name('portal.assessments.index');

/* ---------- result screen ---------- */
Route::get('submissions/{submission}', [SubmissionsController::class, 'show'])
     ->middleware('role:parent,admin')
     ->name('submissions.show');


Route::resource('products', ProductController::class);

// Old lesson routes disabled - conflicts with new ContentLesson player routes
// Route::resource('lessons', LessonController::class);

Route::resource('children', ChildController::class);


Route::get('/applications/create', [ApplicationController::class, 'create'])->name('applications.create');
Route::post('/applications', [ApplicationController::class, 'store'])->name('applications.store');
Route::get('/applications/verify/{token}', [ApplicationController::class, 'verifyEmail'])->name('application.verify');
Route::post('/application/resend-verification', [ApplicationController::class, 'resendVerificationEmail'])->name('application.resend_verification');
Route::get('/application/verification', [ApplicationController::class, 'verificationPage'])->name('application.verification');
Route::get('/email/verified', [ApplicationController::class, 'emailVerified'])->name('email.verified');
Route::get('/applications', [ApplicationController::class, 'index'])->name('applications.index');
Route::get('/applications/{id}/edit', [ApplicationController::class, 'edit'])->name('applications.edit');
Route::put('/applications/{id}', [ApplicationController::class, 'reviewApplication'])->name('applications.review');
Route::delete('/applications/{id}', [ApplicationController::class, 'destroy'])->name('applications.destroy');
Route::get('/applications/{id}', [ApplicationController::class, 'show'])->name('applications.show');

Route::get('/feedbacks/{id}', [FeedbackController::class, 'show'])->name('feedbacks.show');

Route::get('/faqs/{id}', [FaqController::class, 'show'])->name('faqs.show');

Route::get('/slides/{slide_id}', [SlideController::class, 'show'])->name('slides.show');


Route::get('/alerts/{id}', [AlertController::class, 'show'])->name('alerts.show');

Route::get('/articles/{id}', [ArticleController::class, 'show'])->name('articles.show');



Route::get('/about', [AboutController::class, 'index'])
     ->name('about');
Route::get('/contact', function () {
    $faqs = Faq::where('published', true)->get();
    return inertia('@public/Main/ContactUs', [
        'faqs' => $faqs,
    ]);
})->name('contact');
// Route::get('/contact', [MilestoneController::class, 'aboutUs'])->name('milestones.index');



    Route::get('/login', function () {
        return Inertia::render('@public/Auth/PreLogin');
    })->name('login');


        Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    Route::post('register', [RegisteredUserController::class, 'store']);

    // Teacher Registration Routes (with OTP verification)
    Route::get('/teacher/register', function () {
        return inertia('@public/Teacher/Register');
    })->name('teacher.register.form');
    
    Route::post('/teacher/send-otp', [\App\Http\Controllers\TeacherController::class, 'sendOtp'])
        ->name('teacher.sendOtp');
    Route::post('/teacher/verify-otp', [\App\Http\Controllers\TeacherController::class, 'verifyOtp'])
        ->name('teacher.verifyOtp');
    Route::post('/teacher/register', [\App\Http\Controllers\TeacherController::class, 'register'])
        ->name('teacher.register');



    Route::get('authenticate-user', [AuthenticatedSessionController::class, 'create'])
        ->name('authenticate-user');

    Route::post('authenticate-user', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');



    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');





Route::get('/portal/assessments/browse', [AssessmentController::class, 'browseIndex'])
         ->middleware('role:parent,admin')
         ->name('portal.assessments.browse');

//
// Guest onboarding: when a guest_parent attempts to access a parent-only page they will be
// redirected to /guest/complete-profile. The controller will show a form to complete the
// remaining parent + children details and then upgrade their role to 'parent'.
Route::get('/guest/complete-profile', [\App\Http\Controllers\GuestOnboardingController::class, 'show'])
    ->middleware('auth')
    ->name('guest.complete_profile');

Route::post('/guest/complete-profile', [\App\Http\Controllers\GuestOnboardingController::class, 'store'])
    ->middleware('auth')
    ->name('guest.complete_profile.store');

Route::get('/portal/products', [ProductController::class, 'portalIndex'])
     ->name('portal.products.index');

// Old lesson routes disabled - conflicts with new ContentLesson player routes
// Route::resource('lessons', LessonController::class);
Route::get('/portal/lessons/browse', [LessonController::class, 'browseIndex'])
         ->name('portal.lessons.browse');

Route::get('/portal/faqs', [FaqController::class, 'portalIndex'])
         ->name('portal.faqs.index');

        Route::get('/portal/services', [ServiceController::class, 'portalindex'])
     ->name('portal.services.index');
  Route::get('/portal/services/{service}', [PublicServiceController::class, 'portalShow'])->name('portal.services.show');


Route::get('/', [HomeController::class, 'index'])->name('home');

Route::get('/services', [ServiceController::class, 'index'])->name('services.index');
Route::get('/services/{service}', [ServiceController::class, 'publicShow'])->name('services.show');

Route::get('/articles', [ArticleController::class, 'index'])->name('articles.index');

// Subscription Catalog - Public page showing all subscriptions with course previews
Route::get('/subscription-plans', [\App\Http\Controllers\SubscriptionCatalogController::class, 'index'])
     ->name('subscriptions.catalog');

// API: Check if the user has an active payment method set up
Route::get('/api/check-payment-method-setup', [BillingController::class, 'checkPaymentMethodSetup'])->middleware('auth');
