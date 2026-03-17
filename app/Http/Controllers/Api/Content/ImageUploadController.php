<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\ApiController;
use App\Services\MediaAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ImageUploadController extends ApiController
{
    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => 'required|file|image|max:5120',
        ]);

        $file = $validated['image'];
        $path = $file->store('lessons/images', 'public');

        // Track in media library
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?? $user?->current_organization_id;
        if ($orgId && $user) {
            MediaAssetService::track($path, $orgId, $user->id, 'public', [
                'original_filename' => $file->getClientOriginalName(),
            ]);
        }

        return $this->success([
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ]);
    }
}
