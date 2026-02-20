<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ApiController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class EmailVerificationController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $userId = $request->route('id');
        $hash = (string) $request->route('hash');

        if (!$userId || !$hash) {
            return $this->error('Invalid verification link.', [], 400);
        }

        $user = User::find($userId);
        if (!$user) {
            return $this->error('User not found.', [], 404);
        }

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return $this->error('Invalid verification link.', [], 403);
        }

        if ($user->hasVerifiedEmail()) {
            return $this->success(['message' => 'Email already verified.']);
        }

        $user->markEmailAsVerified();

        return $this->success(['message' => 'Email verified.']);
    }
}
