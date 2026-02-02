# Error Pages Implementation

## Overview

Professional error pages have been implemented for the application using Inertia.js and your brand design language. These pages provide a consistent, user-friendly experience when errors occur.

## Implemented Error Pages

### 1. **404 - Page Not Found** ⭐⭐⭐
- **Location**: `resources/js/Pages/Errors/404.jsx`
- **Design**: Primary purple and accent blue gradient background
- **Icon**: Large "404" text
- **Actions**: Go Home, Go Back
- **Quick Links**: Browse Courses, My Portal, Contact Support

### 2. **500 - Server Error** ⭐⭐⭐
- **Location**: `resources/js/Pages/Errors/500.jsx`
- **Design**: Red and primary purple gradient background
- **Icon**: Warning triangle (Heroicon)
- **Actions**: Try Again, Go Home
- **Features**: Shows debug info in development mode
- **Support**: Link to contact support team

### 3. **503 - Service Unavailable** ⭐⭐
- **Location**: `resources/js/Pages/Errors/503.jsx`
- **Design**: Primary purple and accent blue gradient background
- **Icon**: Wrench/screwdriver (Heroicon)
- **Actions**: Check Again, Go Home
- **Info**: Displays maintenance message

### 4. **403 - Forbidden** ⭐⭐
- **Location**: `resources/js/Pages/Errors/403.jsx`
- **Design**: Yellow and primary purple gradient background
- **Icon**: Lock (Heroicon)
- **Actions**: Go Home, Go Back
- **Support**: Link to contact support if error is unexpected

### 5. **419 - Page Expired** ⭐
- **Location**: `resources/js/Pages/Errors/419.jsx`
- **Design**: Orange and primary purple gradient background
- **Icon**: Clock (Heroicon)
- **Actions**: Refresh Page, Go Home
- **Info**: Explains CSRF token expiration

## Design Language

All error pages follow your brand design system:

### Colors
- **Primary**: `#411183` (Rich Purple) - Brand identity
- **Accent**: `#1F6DF2` (Vivid Blue) - Primary CTAs
- **Accent-soft**: `#f77052` (Coral) - Secondary actions
- **Gray-900**: `#111827` - Headings
- **Gray-600**: `#4B5563` - Body text
- **Gray-100**: `#F5F7FC` - Light backgrounds

### Typography
- **Headings**: Poppins font family
- **Body**: Nunito font family
- **Clean, professional tone** (no emojis)

### UI Elements
- **Icons**: Heroicons (outline variant)
- **Buttons**: Rounded corners with subtle shadows
- **Backgrounds**: Gradient backgrounds using brand colors
- **Transitions**: Smooth color transitions (200ms)
- **Responsive**: Mobile-first design

## Configuration

Error handling is configured in `bootstrap/app.php`:

```php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->respond(function ($response, $exception, $request) {
        $status = $response->getStatusCode();
        
        // Only handle these specific error codes for Inertia requests
        if ($request->header('X-Inertia') && in_array($status, [403, 404, 419, 500, 503])) {
            $message = match($status) {
                403 => "You don't have permission to access this resource.",
                404 => "The page you're looking for doesn't exist.",
                419 => 'Your session has expired. Please refresh and try again.',
                500 => "We're experiencing technical difficulties. Our team has been notified.",
                503 => "We're currently under maintenance. We'll be back soon!",
                default => 'An error occurred.'
            };
            
            return \Inertia\Inertia::render("Errors/{$status}", [
                'status' => $status,
                'message' => $message,
                'debug' => config('app.debug') && $status === 500 ? $exception->getMessage() : null,
            ])
            ->toResponse($request)
            ->setStatusCode($status);
        }
        
        return $response;
    });
})
```

## Features

### User-Friendly
- Clear error messages in plain language
- Helpful action buttons (Go Home, Go Back, Refresh)
- Quick links to important pages
- Professional, non-technical language

### Responsive Design
- Works on mobile, tablet, and desktop
- Flexible layouts that adapt to screen size
- Touch-friendly buttons

### Brand Consistent
- Uses your Tailwind color palette
- Follows your typography system
- Professional, clean design (no poppy elements)
- Heroicons instead of emojis

### Developer-Friendly
- Debug information shown in development mode (500 errors only)
- Proper HTTP status codes
- Works seamlessly with Inertia.js
- Easy to customize messages

## Testing

To test error pages in development:

```php
// In routes/web.php or any route file
Route::get('/test-404', function() { abort(404); });
Route::get('/test-500', function() { throw new \Exception('Test error'); });
Route::get('/test-403', function() { abort(403); });
Route::get('/test-503', function() { abort(503); });
Route::get('/test-419', function() { abort(419); });
```

Visit these routes in your browser to see the error pages in action.

## Notes

- **Inertia-only**: These error pages only work for Inertia.js requests (when `X-Inertia` header is present)
- **Non-Inertia requests**: Will use Laravel's default error handling
- **Debug mode**: 500 errors show exception messages when `APP_DEBUG=true`
- **Production**: Debug info is hidden in production for security

## File Structure

```
resources/js/Pages/Errors/
├── 403.jsx   (Forbidden)
├── 404.jsx   (Not Found)
├── 419.jsx   (CSRF Expired)
├── 500.jsx   (Server Error)
└── 503.jsx   (Maintenance)

bootstrap/
└── app.php   (Exception handling configuration)
```

## Future Enhancements

Consider adding:
- **429 - Too Many Requests** (Rate limiting)
- **401 - Unauthorized** (Authentication required)
- Custom error tracking/logging
- Error page analytics
- Multilingual support

## Maintenance

When updating error pages:
1. Keep messages user-friendly and non-technical
2. Maintain brand design consistency
3. Test on multiple devices
4. Ensure proper HTTP status codes
5. Update documentation if adding new error pages
