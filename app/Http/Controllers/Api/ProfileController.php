<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\ProfileUpdateRequest;
use App\Http\Requests\Api\PasswordUpdateRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileController extends ApiController
{
    public function show(): JsonResponse
    {
        $user = request()->user();

        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $user->load('currentOrganization');

        return $this->success([
            'user' => new UserResource($user),
        ]);
    }

    public function update(ProfileUpdateRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        // Handle avatar upload
        if ($request->hasFile('avatar')) {
            // Delete old avatar if exists
            if ($user->avatar_path && Storage::disk('public')->exists($user->avatar_path)) {
                Storage::disk('public')->delete($user->avatar_path);
            }

            $data['avatar_path'] = $request->file('avatar')->store("users/{$user->id}", 'public');
        }

        // Map phone to mobile_number
        if (array_key_exists('phone', $data)) {
            $data['mobile_number'] = $data['phone'];
            unset($data['phone']);
        }

        // Remove avatar from $data — already handled above
        unset($data['avatar']);

        if (array_key_exists('password', $data)) {
            $data['password'] = Hash::make($data['password']);
        }

        $user->fill($data);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();
        $user->load('currentOrganization');

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
