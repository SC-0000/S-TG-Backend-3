<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\ApiController;
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

        $path = $validated['image']->store('lessons/images', 'public');

        return $this->success([
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ]);
    }
}
