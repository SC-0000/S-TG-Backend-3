<?php

namespace App\Http\Controllers;

use App\Services\MediaAssetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageUploadController extends Controller
{
    public function upload(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240', // 10MB max
        ]);

        try {
            $image = $request->file('image');

            // Generate unique filename
            $filename = Str::random(40) . '.' . $image->getClientOriginalExtension();

            // Store in public disk
            $path = $image->storeAs('lesson-images', $filename, 'public');

            // Track in media library
            $user = $request->user();
            $orgId = $request->attributes->get('organization_id') ?? $user?->current_organization_id;
            if ($orgId && $user) {
                MediaAssetService::track($path, $orgId, $user->id, 'public', [
                    'original_filename' => $image->getClientOriginalName(),
                ]);
            }

            // Return the URL
            $url = Storage::disk('public')->url($path);

            return response()->json([
                'success' => true,
                'url' => $url,
                'path' => $path,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Image upload failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
