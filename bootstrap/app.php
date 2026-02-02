<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            // Super Admin Routes
            Route::middleware('web')
                ->group(base_path('routes/superadmin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            \App\Http\Middleware\ShareUnassignedSubscriptions::class,
        ]);
        $middleware->api(append: [
            \App\Http\Middleware\SetRequestId::class,
            \App\Http\Middleware\SetOrganizationContext::class,
        ]);
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'subscription' => \App\Http\Middleware\CheckSubscriptionFeature::class,
            'redirect.incomplete.guests' => \App\Http\Middleware\RedirectIncompleteGuests::class,
            'superadmin' => \App\Http\Middleware\SuperAdminAccess::class,
            'feature' => \App\Http\Middleware\EnsureFeatureEnabled::class,
            'org.context' => \App\Http\Middleware\SetOrganizationContext::class,
            'request.id' => \App\Http\Middleware\SetRequestId::class,
        ]);
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->respond(function ($response, $exception, $request) {
            $status = $response->getStatusCode();

            if ($request->is('api/*') || $request->expectsJson()) {
                if ($response instanceof \Illuminate\Http\JsonResponse) {
                    $payload = $response->getData(true);
                    if (isset($payload['data'], $payload['meta'], $payload['errors'])) {
                        return $response;
                    }
                }

                $payloadErrors = [];
                $message = $exception?->getMessage();

                if ($response instanceof \Illuminate\Http\JsonResponse) {
                    $payload = $response->getData(true);
                    $message = $payload['message'] ?? $message;

                    if (isset($payload['errors']) && is_array($payload['errors'])) {
                        foreach ($payload['errors'] as $field => $messages) {
                            foreach ((array) $messages as $errorMessage) {
                                $payloadErrors[] = [
                                    'field' => (string) $field,
                                    'message' => $errorMessage,
                                ];
                            }
                        }
                    }
                }

                if (!$message) {
                    $message = $status === 404 ? 'Not Found' : 'Request failed';
                }

                if (!$payloadErrors) {
                    $payloadErrors[] = ['message' => $message];
                }

                return response()->json([
                    'data' => null,
                    'meta' => [
                        'status' => $status,
                        'request_id' => $request->attributes->get('request_id'),
                    ],
                    'errors' => $payloadErrors,
                ], $status);
            }
            
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
    })->create();
