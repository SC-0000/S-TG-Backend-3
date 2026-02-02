<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\SuperAdmin\{
    DashboardController,
    UserManagementController,
    OrganizationController,
    ContentManagementController,
    SystemSettingsController,
    BillingManagementController,
    AnalyticsController,
    LogsController
};
use App\Http\Controllers\Admin\OrganizationBrandingController;

/*
|--------------------------------------------------------------------------
| SUPER ADMIN ROUTES
|--------------------------------------------------------------------------
|
| These routes are for platform-wide super administrators with unrestricted
| access. All routes are protected by the 'superadmin' middleware which
| verifies the user has ROLE_SUPER_ADMIN role.
|
*/

Route::middleware(['auth', 'superadmin'])->prefix('superadmin')->name('superadmin.')->group(function () {
    
    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/stats', [DashboardController::class, 'stats'])->name('stats');
    
    /*
    |--------------------------------------------------------------------------
    | Site Administration Hub
    |--------------------------------------------------------------------------
    */
    Route::get('/site-admin', function () {
        return Inertia::render('@superadmin/SiteAdmin/Index');
    })->name('site-admin');
    
    /*
    |--------------------------------------------------------------------------
    | User Management (Platform-Wide)
    |--------------------------------------------------------------------------
    */
    Route::prefix('users')->name('users.')->group(function () {
        Route::get('/', [UserManagementController::class, 'index'])->name('index');
        Route::get('/create', [UserManagementController::class, 'create'])->name('create');
        Route::post('/', [UserManagementController::class, 'store'])->name('store');
        Route::get('/{user}', [UserManagementController::class, 'show'])->name('show');
        Route::get('/{user}/edit', [UserManagementController::class, 'edit'])->name('edit');
        Route::put('/{user}', [UserManagementController::class, 'update'])->name('update');
        Route::delete('/{user}', [UserManagementController::class, 'destroy'])->name('destroy');
        
        // Special actions
        Route::post('/{user}/change-role', [UserManagementController::class, 'changeRole'])->name('change-role');
        Route::post('/{user}/toggle-status', [UserManagementController::class, 'toggleStatus'])->name('toggle-status');
        Route::post('/{user}/impersonate', [UserManagementController::class, 'impersonate'])->name('impersonate');
        Route::post('/bulk-action', [UserManagementController::class, 'bulkAction'])->name('bulk-action');
    });
    
    /*
    |--------------------------------------------------------------------------
    | Organization Management (Full Platform Control)
    |--------------------------------------------------------------------------
    */
    Route::prefix('organizations')->name('organizations.')->group(function () {
        Route::get('/', [OrganizationController::class, 'index'])->name('index');
        Route::get('/create', [OrganizationController::class, 'create'])->name('create');
        Route::post('/', [OrganizationController::class, 'store'])->name('store');
        Route::get('/{organization}', [OrganizationController::class, 'show'])->name('show');
        Route::get('/{organization}/edit', [OrganizationController::class, 'edit'])->name('edit');
        Route::put('/{organization}', [OrganizationController::class, 'update'])->name('update');
        Route::delete('/{organization}', [OrganizationController::class, 'destroy'])->name('destroy');
        
        // Organization-specific views
        Route::get('/{organization}/analytics', [OrganizationController::class, 'analytics'])->name('analytics');
        Route::get('/{organization}/content', [OrganizationController::class, 'content'])->name('content');
        Route::get('/{organization}/users', [OrganizationController::class, 'users'])->name('users');
        
        // User management within organization
        Route::post('/{organization}/add-user', [OrganizationController::class, 'addUser'])->name('add-user');
        Route::delete('/{organization}/remove-user/{user}', [OrganizationController::class, 'removeUser'])->name('remove-user');
        Route::post('/{organization}/change-user-role/{user}', [OrganizationController::class, 'changeUserRole'])->name('change-user-role');
        
        // Branding management
        Route::get('/{organization}/branding', [OrganizationController::class, 'branding'])->name('branding');
        Route::post('/{organization}/branding', [OrganizationBrandingController::class, 'update'])->name('branding.update');
        Route::post('/{organization}/branding/upload-logo', [OrganizationBrandingController::class, 'uploadLogo'])->name('branding.upload-logo');
        Route::post('/{organization}/branding/upload-favicon', [OrganizationBrandingController::class, 'uploadFavicon'])->name('branding.upload-favicon');
        Route::delete('/{organization}/branding/delete-asset', [OrganizationBrandingController::class, 'deleteAsset'])->name('branding.delete-asset');
    });
    
    /*
    |--------------------------------------------------------------------------
    | Content Management (Platform-Wide Access)
    |--------------------------------------------------------------------------
    */
    Route::prefix('content')->name('content.')->group(function () {
        Route::get('/courses', [ContentManagementController::class, 'courses'])->name('courses');
        Route::get('/lessons', [ContentManagementController::class, 'lessons'])->name('lessons');
        Route::get('/assessments', [ContentManagementController::class, 'assessments'])->name('assessments');
        Route::get('/services', [ContentManagementController::class, 'services'])->name('services');
        Route::get('/articles', [ContentManagementController::class, 'articles'])->name('articles');
        Route::get('/moderation', [ContentManagementController::class, 'moderation'])->name('moderation');
        
        // Content actions
        Route::post('/{type}/{id}/feature', [ContentManagementController::class, 'feature'])->name('feature');
        Route::post('/{type}/{id}/unfeature', [ContentManagementController::class, 'unfeature'])->name('unfeature');
        Route::delete('/{type}/{id}', [ContentManagementController::class, 'delete'])->name('delete');
    });
    
    /*
    |--------------------------------------------------------------------------
    | System Management (Platform Configuration)
    |--------------------------------------------------------------------------
    */
    Route::prefix('system')->name('system.')->group(function () {
        Route::get('/settings', [SystemSettingsController::class, 'index'])->name('settings');
        Route::post('/settings', [SystemSettingsController::class, 'update'])->name('settings.update');
        
        Route::get('/features', [SystemSettingsController::class, 'featureFlags'])->name('features');
        Route::post('/features/{flag}/toggle', [SystemSettingsController::class, 'toggleFeature'])->name('features.toggle');
        
        Route::get('/integrations', [SystemSettingsController::class, 'integrations'])->name('integrations');
        Route::get('/email-templates', [SystemSettingsController::class, 'emailTemplates'])->name('email-templates');
        Route::get('/api-keys', [SystemSettingsController::class, 'apiKeys'])->name('api-keys');
        
        Route::post('/backup', [SystemSettingsController::class, 'backup'])->name('backup');
        Route::post('/restore', [SystemSettingsController::class, 'restore'])->name('restore');
    });
    
    /*
    |--------------------------------------------------------------------------
    | Billing & Revenue (Financial Overview)
    |--------------------------------------------------------------------------
    */
    Route::prefix('billing')->name('billing.')->group(function () {
        Route::get('/overview', [BillingManagementController::class, 'overview'])->name('overview');
        Route::get('/transactions', [BillingManagementController::class, 'transactions'])->name('transactions');
        Route::get('/subscriptions', [BillingManagementController::class, 'subscriptions'])->name('subscriptions');
        Route::get('/revenue', [BillingManagementController::class, 'revenue'])->name('revenue');
        Route::get('/invoices', [BillingManagementController::class, 'invoices'])->name('invoices');
        
        Route::post('/refund/{transaction}', [BillingManagementController::class, 'refund'])->name('refund');
        Route::get('/export', [BillingManagementController::class, 'export'])->name('export');
    });
    
    /*
    |--------------------------------------------------------------------------
    | Analytics & Reporting (Platform Metrics)
    |--------------------------------------------------------------------------
    */
    Route::prefix('analytics')->name('analytics.')->group(function () {
        Route::get('/dashboard', [AnalyticsController::class, 'dashboard'])->name('dashboard');
        Route::get('/users', [AnalyticsController::class, 'users'])->name('users');
        Route::get('/content', [AnalyticsController::class, 'content'])->name('content');
        Route::get('/engagement', [AnalyticsController::class, 'engagement'])->name('engagement');
        
        Route::post('/custom-report', [AnalyticsController::class, 'customReport'])->name('custom-report');
    });
    
    /*
    |--------------------------------------------------------------------------
    | Logs & Monitoring (System Health)
    |--------------------------------------------------------------------------
    */
    Route::prefix('logs')->name('logs.')->group(function () {
        Route::get('/system', [LogsController::class, 'system'])->name('system');
        Route::get('/user-activity', [LogsController::class, 'userActivity'])->name('user-activity');
        Route::get('/errors', [LogsController::class, 'errors'])->name('errors');
        Route::get('/audit', [LogsController::class, 'audit'])->name('audit');
        Route::get('/performance', [LogsController::class, 'performance'])->name('performance');
    });
    
});
