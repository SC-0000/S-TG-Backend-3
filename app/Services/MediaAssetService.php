<?php

namespace App\Services;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MediaAssetService
{
    /**
     * Track an already-stored file in the media library.
     * Call this after any file has been stored to disk via other upload flows.
     * Safe to call — catches all exceptions so it never breaks the caller.
     */
    public static function track(
        string $storagePath,
        int    $organizationId,
        int    $uploadedBy,
        string $disk = 'public',
        array  $extra = [],
    ): ?MediaAsset {
        try {
            // Don't double-track
            $existing = MediaAsset::where('storage_path', $storagePath)
                ->where('storage_disk', $disk)
                ->first();

            if ($existing) {
                return $existing;
            }

            $fullPath = Storage::disk($disk)->path($storagePath);
            $mime = mime_content_type($fullPath) ?: 'application/octet-stream';
            $size = Storage::disk($disk)->size($storagePath);
            $originalFilename = $extra['original_filename'] ?? basename($storagePath);

            $asset = MediaAsset::create([
                'organization_id' => $organizationId,
                'uploaded_by' => $uploadedBy,
                'type' => MediaAsset::resolveTypeFromMime($mime),
                'title' => $extra['title'] ?? pathinfo($originalFilename, PATHINFO_FILENAME),
                'description' => $extra['description'] ?? null,
                'storage_disk' => $disk,
                'storage_path' => $storagePath,
                'original_filename' => $originalFilename,
                'mime_type' => $mime,
                'size_bytes' => $size,
                'visibility' => $extra['visibility'] ?? MediaAsset::VISIBILITY_ORG,
                'status' => MediaAsset::STATUS_READY,
                'source_type' => 'upload',
                'tags' => $extra['tags'] ?? null,
                'metadata' => $extra['metadata'] ?? null,
                'thumbnail_path' => $extra['thumbnail_path'] ?? (str_starts_with($mime, 'image/') ? $storagePath : null),
            ]);

            return $asset;
        } catch (\Throwable $e) {
            Log::warning('MediaAssetService::track failed', [
                'path' => $storagePath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
