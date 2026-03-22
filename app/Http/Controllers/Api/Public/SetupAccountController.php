<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Api\ApiController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SetupAccountController extends ApiController
{
    /**
     * Verify a setup token is valid (before showing the password form).
     */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !$user->verifySetupToken($validated['token'])) {
            return $this->error('This link is invalid or has expired. Please request a password reset instead.', [], 422);
        }

        return $this->success([
            'valid' => true,
            'name'  => $user->name,
            'email' => $user->email,
        ]);
    }

    /**
     * Set the user's password using a valid setup token.
     */
    public function setPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token'    => 'required|string',
            'email'    => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !$user->verifySetupToken($validated['token'])) {
            return $this->error('This link is invalid or has expired. Please request a password reset instead.', [], 422);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        $user->clearSetupToken();

        return $this->success([
            'message' => 'Your password has been set successfully. You can now log in.',
        ]);
    }
}
