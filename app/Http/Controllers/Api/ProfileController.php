<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\ProfileUpdateRequest;
use App\Http\Requests\Api\PasswordUpdateRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class ProfileController extends ApiController
{
    public function show(): JsonResponse
    {
        $user = request()->user();

        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        return $this->success([
            'user' => new UserResource($user),
        ]);
    }

    public function update(ProfileUpdateRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if (array_key_exists('password', $data)) {
            $data['password'] = Hash::make($data['password']);
        }

        $user->fill($data);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return $this->success([
            'user' => new UserResource($user),
        ]);
    }

    public function destroy(): JsonResponse
    {
        $payload = request()->validate([
            'password' => ['required', 'string'],
        ]);

        $user = request()->user();
        if (!$user || !Hash::check($payload['password'], $user->password)) {
            return $this->error('Password is incorrect.', [], 422);
        }

        if ($user?->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        Auth::logout();
        $user->delete();

        return $this->success(['message' => 'Account deleted.']);
    }

    public function updatePassword(PasswordUpdateRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->password = Hash::make($request->input('password'));
        $user->save();

        return $this->success(['message' => 'Password updated.']);
    }
}
