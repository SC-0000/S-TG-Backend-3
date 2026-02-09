<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Requests\Api\Auth\RegisterRequest;
use App\Http\Requests\Api\Auth\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends ApiController
{
    public function login(LoginRequest $request): JsonResponse
    {
        $request->ensureIsNotRateLimited();

        $email = Str::lower($request->input('email'));
        $user = User::whereRaw('lower(email) = ?', [$email])->first();

        if (!$user || !Hash::check($request->input('password'), $user->password)) {
            RateLimiter::hit($request->throttleKey());
            return $this->error('Invalid credentials.', [], 401);
        }

        RateLimiter::clear($request->throttleKey());

        $tokenName = $request->input('device_name', 'api');
        $token = $user->createToken($tokenName)->plainTextToken;

        return $this->success([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
        ]);
    }

    public function logout(): JsonResponse
    {
        $user = request()->user();
        if ($user?->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        return $this->success(['message' => 'Logged out.']);
    }

    public function me(): JsonResponse
    {
        $user = request()->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }
        
        // Eager load current organization with settings
        $user->load('currentOrganization');
        
        $features = $user
            ->subscriptions
            ->flatMap->features
            ->filter(fn ($value) => $value === true)
            ->keys()
            ->values()
            ->all();

        return $this->success([
            'user' => new UserResource($user),
            'organization_id' => request()->attributes->get('organization_id'),
            'features' => $features,
        ]);
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'password' => Hash::make($request->input('password')),
            'role' => $request->input('role', User::ROLE_PARENT),
        ]);

        event(new Registered($user));

        $token = $user->createToken($request->input('device_name', 'api'))->plainTextToken;

        return $this->success([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
        ], status: 201);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink($request->only('email'));

        if ($status !== Password::RESET_LINK_SENT) {
            return $this->error(__($status), [], 422);
        }

        return $this->success(['message' => __($status)]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return $this->error(__($status), [], 422);
        }

        return $this->success(['message' => __($status)]);
    }

    public function resendVerification(): JsonResponse
    {
        $user = request()->user();

        if ($user->hasVerifiedEmail()) {
            return $this->success(['message' => 'Email already verified.']);
        }

        $user->sendEmailVerificationNotification();

        return $this->success(['message' => 'Verification email sent.']);
    }

    public function confirmPassword(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            return $this->error('Invalid password.', [], 422);
        }

        return $this->success(['message' => 'Password confirmed.']);
    }
}
