<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MediaAssetController extends Controller
{
    use ApiResponse;

    /**
     * List media assets for the current org with filters, search, pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id');

        $query = MediaAsset::query()
            ->with(['uploader:id,name', 'organization:id,name']);

        // Org scoping
        if ($user?->isSuperAdmin() && $request->filled('organization_id')) {
            $query->where('organization_id', $request->integer('organization_id'));
        } elseif ($orgId) {
            $query->where('organization_id', $orgId);
        }

        // Search
        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->search($search);
        }

        // Type filter
        if ($request->filled('type')) {
            $query->ofType($request->query('type'));
        }

        // Visibility filter
        if ($request->filled('visibility')) {
            $query->withVisibility($request->query('visibility'));
        }

        // Status filter
        if ($request->filled('status')) {
            $status = $request->query('status');
            if ($status === 'archived') {
                $query->where(function ($q) {
                    $q->where('status', MediaAsset::STATUS_ARCHIVED)
                      ->orWhereNotNull('archived_at');
                });
            } else {
                $query->where('status', $status)->whereNull('archived_at');
            }
        } else {
            // Default: exclude archived
            $query->notArchived();
        }

        // Tags filter
        if ($request->filled('tag')) {
            $query->whereJsonContains('tags', $request->query('tag'));
        }

        // Uploader filter
        if ($request->filled('uploaded_by')) {
            $query->where('uploaded_by', $request->integer('uploaded_by'));
        }

        // Usage filter: unlinked assets
        if ($request->query('unlinked') === 'true') {
            $query->whereDoesntHave('questions')
                  ->whereDoesntHave('contentLessons')
                  ->whereDoesntHave('assessments')
                  ->whereDoesntHave('courses');
        }

        ApiQuery::applySort(
            $query,
            $request,
            ['created_at', 'title', 'size_bytes', 'type', 'updated_at'],
            '-created_at'
        );

        $assets = $query->paginate(ApiPagination::perPage($request, 24));

        $data = collect($assets->items())->map(fn ($asset) => $this->transformAsset($asset));

        // Stats for the filter bar
        $statsOrgId = ($user?->isSuperAdmin() && $request->filled('organization_id'))
            ? $request->integer('organization_id')
            : $orgId;

        $stats = $this->getStats($statsOrgId);

        return $this->paginated($assets, $data, [
            'stats' => $stats,
            'types' => MediaAsset::TYPES,
            'visibilities' => MediaAsset::VISIBILITIES,
        ]);
    }

    /**
     * Show a single asset with all relationships and usage info.
     */
    public function show(MediaAsset $mediaAsset, Request $request): JsonResponse
    {
        if ($response = $this->ensureOrgScope($request, $mediaAsset)) {
            return $response;
        }

        $mediaAsset->load([
            'uploader:id,name',
            'organization:id,name',
            'questions:id,title,question_type,status',
            'contentLessons:id,title,status',
            'assessments:id,title,status',
            'courses:id,title,status',
            'journeyCategories:id,journey_id,topic,name',
        ]);

        $data = $this->transformAsset($mediaAsset, true);

        return $this->success($data);
    }

    /**
     * Upload one or more files and create media asset records.
     */
    public function store(Request $request): JsonResponse
    {
        $hasFile = $request->hasFile('files') || $request->hasFile('file');

        $request->validate([
            'files' => $hasFile ? 'nullable' : 'required_without:source_url',
            'files.*' => 'file|max:102400', // 100MB max per file
            'file' => 'nullable|file|max:102400', // single file upload
            'source_url' => $hasFile ? 'nullable|url' : 'required_without:files|nullable|url',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'visibility' => ['nullable', Rule::in(MediaAsset::VISIBILITIES)],
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:100',
            'journey_category_ids' => 'nullable|array',
            'journey_category_ids.*' => 'integer|exists:journey_categories,id',
        ]);

        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?? $user->current_organization_id;

        $created = [];

        // Handle multi-file uploads (files[])
        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $asset = $this->storeUploadedFile($file, $orgId, $user->id, $request);
                if ($asset) {
                    $created[] = $asset;
                }
            }
        }

        // Handle single file upload (file)
        if ($request->hasFile('file')) {
            $asset = $this->storeUploadedFile($request->file('file'), $orgId, $user->id, $request);
            if ($asset) {
                $created[] = $asset;
            }
        }

        // Handle external URL
        if ($request->filled('source_url') && empty($created)) {
            $asset = MediaAsset::create([
                'organization_id' => $orgId,
                'uploaded_by' => $user->id,
                'type' => $this->guessTypeFromUrl($request->input('source_url')),
                'title' => $request->input('title') ?: $this->titleFromUrl($request->input('source_url')),
                'description' => $request->input('description'),
                'storage_disk' => 'public',
                'storage_path' => '',
                'original_filename' => '',
                'mime_type' => 'text/uri-list',
                'size_bytes' => 0,
                'visibility' => $request->input('visibility', MediaAsset::VISIBILITY_ORG),
                'status' => MediaAsset::STATUS_READY,
                'source_type' => 'external_link',
                'source_url' => $request->input('source_url'),
                'tags' => $request->input('tags'),
            ]);

            if ($request->filled('journey_category_ids')) {
                $asset->journeyCategories()->sync($request->input('journey_category_ids'));
            }

            $created[] = $asset;
        }

        if (empty($created)) {
            return $this->error('No files were uploaded.', [], 422);
        }

        $data = collect($created)->map(fn ($a) => $this->transformAsset($a));

        return $this->success($data, [], 201);
    }

    /**
     * Update asset metadata (not the file itself).
     */
    public function update(MediaAsset $mediaAsset, Request $request): JsonResponse
    {
        if ($response = $this->ensureOrgScope($request, $mediaAsset)) {
            return $response;
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:2000',
            'visibility' => ['sometimes', Rule::in(MediaAsset::VISIBILITIES)],
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:100',
            'source_url' => 'nullable|url',
            'journey_category_ids' => 'nullable|array',
            'journey_category_ids.*' => 'integer|exists:journey_categories,id',
        ]);

        $journeyCategoryIds = $validated['journey_category_ids'] ?? null;
        unset($validated['journey_category_ids']);

        $mediaAsset->update($validated);

        if ($journeyCategoryIds !== null) {
            $mediaAsset->journeyCategories()->sync($journeyCategoryIds);
        }

        $mediaAsset->load(['uploader:id,name', 'organization:id,name', 'journeyCategories:id,topic,name']);

        return $this->success($this->transformAsset($mediaAsset));
    }

    /**
     * Archive an asset. Blocked if the file is used in content.
     */
    public function archive(MediaAsset $mediaAsset, Request $request): JsonResponse
    {
        if ($response = $this->ensureOrgScope($request, $mediaAsset)) {
            return $response;
        }

        if ($mediaAsset->is_linked) {
            return $this->error(
                'This asset is used in content (lessons, assessments, articles, etc.) and cannot be archived or deleted.',
                [],
                409
            );
        }

        $mediaAsset->archive();

        return $this->success(['message' => 'Asset archived successfully.']);
    }

    /**
     * Restore an archived asset.
     */
    public function restore(MediaAsset $mediaAsset, Request $request): JsonResponse
    {
        if ($response = $this->ensureOrgScope($request, $mediaAsset)) {
            return $response;
        }

        $mediaAsset->restore();

        return $this->success($this->transformAsset($mediaAsset));
    }

    /**
     * "Delete" — actually archives. Blocked if linked to content.
     */
    public function destroy(MediaAsset $mediaAsset, Request $request): JsonResponse
    {
        if ($response = $this->ensureOrgScope($request, $mediaAsset)) {
            return $response;
        }

        if ($mediaAsset->is_linked) {
            return $this->error(
                'This asset is used in content and cannot be removed.',
                [],
                409
            );
        }

        $mediaAsset->archive();

        return $this->success(['message' => 'Asset removed.']);
    }

    /**
     * Attach asset to one or more entities.
     */
    public function attach(MediaAsset $mediaAsset, Request $request): JsonResponse
    {
        if ($response = $this->ensureOrgScope($request, $mediaAsset)) {
            return $response;
        }

        $validated = $request->validate([
            'question_ids' => 'nullable|array',
            'question_ids.*' => 'integer|exists:questions,id',
            'content_lesson_ids' => 'nullable|array',
            'content_lesson_ids.*' => 'integer|exists:new_lessons,id',
            'assessment_ids' => 'nullable|array',
            'assessment_ids.*' => 'integer|exists:assessments,id',
            'course_ids' => 'nullable|array',
            'course_ids.*' => 'integer|exists:courses,id',
            'context' => 'nullable|string|max:50',
        ]);

        $context = $validated['context'] ?? null;

        if (!empty($validated['question_ids'])) {
            $sync = collect($validated['question_ids'])->mapWithKeys(fn ($id) => [$id => ['context' => $context]])->all();
            $mediaAsset->questions()->syncWithoutDetaching($sync);
        }

        if (!empty($validated['content_lesson_ids'])) {
            $sync = collect($validated['content_lesson_ids'])->mapWithKeys(fn ($id) => [$id => ['context' => $context]])->all();
            $mediaAsset->contentLessons()->syncWithoutDetaching($sync);
        }

        if (!empty($validated['assessment_ids'])) {
            $sync = collect($validated['assessment_ids'])->mapWithKeys(fn ($id) => [$id => ['context' => $context]])->all();
            $mediaAsset->assessments()->syncWithoutDetaching($sync);
        }

        if (!empty($validated['course_ids'])) {
            $sync = collect($validated['course_ids'])->mapWithKeys(fn ($id) => [$id => ['context' => $context]])->all();
            $mediaAsset->courses()->syncWithoutDetaching($sync);
        }

        $mediaAsset->load(['questions', 'contentLessons', 'assessments', 'courses']);

        return $this->success($this->transformAsset($mediaAsset, true));
    }

    /**
     * Detach asset from entities.
     */
    public function detach(MediaAsset $mediaAsset, Request $request): JsonResponse
    {
        if ($response = $this->ensureOrgScope($request, $mediaAsset)) {
            return $response;
        }

        $validated = $request->validate([
            'question_ids' => 'nullable|array',
            'content_lesson_ids' => 'nullable|array',
            'assessment_ids' => 'nullable|array',
            'course_ids' => 'nullable|array',
        ]);

        if (!empty($validated['question_ids'])) {
            $mediaAsset->questions()->detach($validated['question_ids']);
        }
        if (!empty($validated['content_lesson_ids'])) {
            $mediaAsset->contentLessons()->detach($validated['content_lesson_ids']);
        }
        if (!empty($validated['assessment_ids'])) {
            $mediaAsset->assessments()->detach($validated['assessment_ids']);
        }
        if (!empty($validated['course_ids'])) {
            $mediaAsset->courses()->detach($validated['course_ids']);
        }

        return $this->success(['message' => 'Detached successfully.']);
    }

    /**
     * Replace the file for an existing asset (new version).
     */
    public function replaceFile(MediaAsset $mediaAsset, Request $request): JsonResponse
    {
        if ($response = $this->ensureOrgScope($request, $mediaAsset)) {
            return $response;
        }

        $request->validate([
            'file' => 'required|file|max:102400',
        ]);

        $file = $request->file('file');

        // Delete old file
        if ($mediaAsset->storage_path && $mediaAsset->source_type === 'upload') {
            Storage::disk($mediaAsset->storage_disk)->delete($mediaAsset->storage_path);
        }

        $disk = $mediaAsset->storage_disk;
        $directory = "media/{$mediaAsset->organization_id}";
        $path = $file->store($directory, $disk);

        $mediaAsset->update([
            'storage_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'type' => MediaAsset::resolveTypeFromMime($file->getMimeType()),
            'source_type' => 'upload',
        ]);

        // Generate thumbnail for images
        if (str_starts_with($file->getMimeType(), 'image/')) {
            $this->generateImageThumbnail($mediaAsset, $disk, $path);
        }

        return $this->success($this->transformAsset($mediaAsset->fresh()));
    }

    // ── Private helpers ──

    private function storeUploadedFile($file, int $orgId, int $userId, Request $request): ?MediaAsset
    {
        try {
            $disk = 'public';
            $directory = "media/{$orgId}";
            $path = $file->store($directory, $disk);

            $mime = $file->getMimeType();
            $type = MediaAsset::resolveTypeFromMime($mime);
            $originalName = $file->getClientOriginalName();

            $asset = MediaAsset::create([
                'organization_id' => $orgId,
                'uploaded_by' => $userId,
                'type' => $type,
                'title' => $request->input('title') ?: pathinfo($originalName, PATHINFO_FILENAME),
                'description' => $request->input('description'),
                'storage_disk' => $disk,
                'storage_path' => $path,
                'original_filename' => $originalName,
                'mime_type' => $mime,
                'size_bytes' => $file->getSize(),
                'visibility' => $request->input('visibility', MediaAsset::VISIBILITY_ORG),
                'status' => MediaAsset::STATUS_READY,
                'source_type' => 'upload',
                'tags' => $request->input('tags'),
                'metadata' => $this->extractMetadata($file, $type),
            ]);

            // Generate thumbnail for images
            if ($type === MediaAsset::TYPE_IMAGE) {
                $this->generateImageThumbnail($asset, $disk, $path);
            }

            if ($request->filled('journey_category_ids')) {
                $asset->journeyCategories()->sync($request->input('journey_category_ids'));
            }

            return $asset;
        } catch (\Throwable $e) {
            Log::error('Media asset upload failed', [
                'error' => $e->getMessage(),
                'org_id' => $orgId,
                'filename' => $file->getClientOriginalName(),
            ]);
            return null;
        }
    }

    private function extractMetadata($file, string $type): array
    {
        $meta = [];

        if ($type === MediaAsset::TYPE_IMAGE) {
            $imageInfo = @getimagesize($file->getPathname());
            if ($imageInfo) {
                $meta['width'] = $imageInfo[0];
                $meta['height'] = $imageInfo[1];
            }
        }

        return $meta;
    }

    private function generateImageThumbnail(MediaAsset $asset, string $disk, string $path): void
    {
        // Use the original image as thumbnail for now.
        // A queue job can later generate proper resized thumbnails.
        $asset->update(['thumbnail_path' => $path]);
    }

    private function guessTypeFromUrl(string $url): string
    {
        $lower = strtolower($url);

        if (preg_match('/(youtube\.com|youtu\.be|vimeo\.com|loom\.com)/', $lower)) {
            return MediaAsset::TYPE_VIDEO;
        }

        $ext = pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION);
        $extMap = [
            'pdf' => MediaAsset::TYPE_PDF,
            'doc' => MediaAsset::TYPE_DOCUMENT,
            'docx' => MediaAsset::TYPE_DOCUMENT,
            'xls' => MediaAsset::TYPE_SPREADSHEET,
            'xlsx' => MediaAsset::TYPE_SPREADSHEET,
            'ppt' => MediaAsset::TYPE_PRESENTATION,
            'pptx' => MediaAsset::TYPE_PRESENTATION,
            'mp4' => MediaAsset::TYPE_VIDEO,
            'mov' => MediaAsset::TYPE_VIDEO,
            'webm' => MediaAsset::TYPE_VIDEO,
            'mp3' => MediaAsset::TYPE_AUDIO,
            'wav' => MediaAsset::TYPE_AUDIO,
            'png' => MediaAsset::TYPE_IMAGE,
            'jpg' => MediaAsset::TYPE_IMAGE,
            'jpeg' => MediaAsset::TYPE_IMAGE,
            'gif' => MediaAsset::TYPE_IMAGE,
            'svg' => MediaAsset::TYPE_IMAGE,
            'webp' => MediaAsset::TYPE_IMAGE,
        ];

        return $extMap[$ext] ?? MediaAsset::TYPE_OTHER;
    }

    private function titleFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $filename = pathinfo($path, PATHINFO_FILENAME);
        return $filename ?: parse_url($url, PHP_URL_HOST) ?? 'External Link';
    }

    private function ensureOrgScope(Request $request, MediaAsset $asset): ?JsonResponse
    {
        $user = $request->user();
        if ($user?->isSuperAdmin()) {
            return null;
        }

        $orgId = $request->attributes->get('organization_id') ?? $user->current_organization_id;
        if ((int) $asset->organization_id !== (int) $orgId) {
            return $this->error('Asset not found.', [], 404);
        }

        return null;
    }

    private function transformAsset(MediaAsset $asset, bool $detailed = false): array
    {
        $data = [
            'id' => $asset->id,
            'organization_id' => $asset->organization_id,
            'organization' => $asset->relationLoaded('organization') ? $asset->organization : null,
            'uploaded_by' => $asset->uploaded_by,
            'uploader' => $asset->relationLoaded('uploader') ? $asset->uploader : null,
            'type' => $asset->type,
            'title' => $asset->title,
            'description' => $asset->description,
            'original_filename' => $asset->original_filename,
            'mime_type' => $asset->mime_type,
            'size_bytes' => $asset->size_bytes,
            'formatted_size' => $asset->formatted_size,
            'visibility' => $asset->visibility,
            'status' => $asset->status,
            'source_type' => $asset->source_type,
            'source_url' => $asset->source_url,
            'url' => $asset->url,
            'thumbnail_url' => $asset->thumbnail_url,
            'duration_seconds' => $asset->duration_seconds,
            'tags' => $asset->tags ?? [],
            'metadata' => $asset->metadata,
            'is_archived' => $asset->is_archived,
            'is_linked' => $asset->is_linked,
            'created_at' => $asset->created_at?->toISOString(),
            'updated_at' => $asset->updated_at?->toISOString(),
            'archived_at' => $asset->archived_at?->toISOString(),
        ];

        if ($detailed) {
            $data['transcript_text'] = $asset->transcript_text;
            $data['usage'] = [
                'questions' => $asset->relationLoaded('questions')
                    ? $asset->questions->map(fn ($q) => [
                        'id' => $q->id,
                        'title' => $q->title,
                        'type' => $q->question_type,
                        'status' => $q->status,
                        'context' => $q->pivot->context,
                    ])
                    : [],
                'content_lessons' => $asset->relationLoaded('contentLessons')
                    ? $asset->contentLessons->map(fn ($l) => [
                        'id' => $l->id,
                        'title' => $l->title,
                        'status' => $l->status,
                        'context' => $l->pivot->context,
                    ])
                    : [],
                'assessments' => $asset->relationLoaded('assessments')
                    ? $asset->assessments->map(fn ($a) => [
                        'id' => $a->id,
                        'title' => $a->title,
                        'status' => $a->status,
                        'context' => $a->pivot->context,
                    ])
                    : [],
                'courses' => $asset->relationLoaded('courses')
                    ? $asset->courses->map(fn ($c) => [
                        'id' => $c->id,
                        'title' => $c->title,
                        'status' => $c->status,
                        'context' => $c->pivot->context,
                    ])
                    : [],
                'journey_categories' => $asset->relationLoaded('journeyCategories')
                    ? $asset->journeyCategories->map(fn ($jc) => [
                        'id' => $jc->id,
                        'topic' => $jc->topic,
                        'name' => $jc->name,
                    ])
                    : [],
            ];

            // Find path-based references (files embedded in content JSON/columns)
            if ($asset->storage_path) {
                $path = $asset->storage_path;
                $escapedPath = addcslashes($path, '%_');

                // Lesson slides containing this file path
                $slideRefs = DB::table('lesson_slides')
                    ->join('new_lessons', 'lesson_slides.lesson_id', '=', 'new_lessons.id')
                    ->where('lesson_slides.blocks', 'like', "%{$escapedPath}%")
                    ->select('new_lessons.id', 'new_lessons.title', 'new_lessons.status')
                    ->distinct()
                    ->get();
                if ($slideRefs->isNotEmpty()) {
                    $existing = collect($data['usage']['content_lessons'])->pluck('id')->all();
                    foreach ($slideRefs as $ref) {
                        if (!in_array($ref->id, $existing)) {
                            $data['usage']['content_lessons'][] = [
                                'id' => $ref->id,
                                'title' => $ref->title,
                                'status' => $ref->status,
                                'context' => 'slide content',
                            ];
                        }
                    }
                }

                // Articles
                $articleRefs = DB::table('articles')
                    ->where(fn ($q) => $q
                        ->where('thumbnail', $path)
                        ->orWhere('pdf', $path)
                        ->orWhere('images', 'like', "%{$escapedPath}%")
                    )
                    ->select('id', 'title')
                    ->get();
                if ($articleRefs->isNotEmpty()) {
                    $data['usage']['articles'] = $articleRefs->map(fn ($a) => [
                        'id' => $a->id,
                        'title' => $a->title,
                        'status' => 'published',
                        'context' => 'embedded',
                    ])->all();
                }

                // Products
                $productRefs = DB::table('products')
                    ->where('image_path', $path)
                    ->select('id', 'name as title')
                    ->get();
                if ($productRefs->isNotEmpty()) {
                    $data['usage']['products'] = $productRefs->map(fn ($p) => [
                        'id' => $p->id,
                        'title' => $p->title,
                        'status' => 'active',
                        'context' => 'product image',
                    ])->all();
                }

                // Services
                $serviceRefs = DB::table('services')
                    ->where('media', 'like', "%{$escapedPath}%")
                    ->select('id', 'service_name as title')
                    ->get();
                if ($serviceRefs->isNotEmpty()) {
                    $data['usage']['services'] = $serviceRefs->map(fn ($s) => [
                        'id' => $s->id,
                        'title' => $s->title,
                        'status' => 'active',
                        'context' => 'service media',
                    ])->all();
                }
            }

            $data['usage_count'] = collect($data['usage'])->flatten(1)->count();
        }

        return $data;
    }

    private function getStats(?int $orgId): array
    {
        if (!$orgId) {
            return [];
        }

        $base = MediaAsset::where('organization_id', $orgId)->notArchived();

        return [
            'total' => (clone $base)->count(),
            'images' => (clone $base)->ofType('image')->count(),
            'videos' => (clone $base)->ofType('video')->count(),
            'pdfs' => (clone $base)->ofType('pdf')->count(),
            'documents' => (clone $base)->ofType('document')->count(),
            'audio' => (clone $base)->ofType('audio')->count(),
            'other' => (clone $base)->whereNotIn('type', ['image', 'video', 'pdf', 'document', 'audio'])->count(),
            'archived' => MediaAsset::where('organization_id', $orgId)
                ->where(fn ($q) => $q->where('status', 'archived')->orWhereNotNull('archived_at'))
                ->count(),
        ];
    }
}
