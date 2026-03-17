<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class MediaAsset extends Model
{
    protected $table = 'media_assets';

    protected $fillable = [
        'organization_id',
        'uploaded_by',
        'type',
        'title',
        'description',
        'storage_disk',
        'storage_path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'visibility',
        'status',
        'thumbnail_path',
        'duration_seconds',
        'transcript_text',
        'source_type',
        'source_url',
        'metadata',
        'tags',
        'archived_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'tags' => 'array',
        'size_bytes' => 'integer',
        'duration_seconds' => 'integer',
        'archived_at' => 'datetime',
    ];

    // ── File type constants ──

    const TYPE_IMAGE = 'image';
    const TYPE_VIDEO = 'video';
    const TYPE_PDF = 'pdf';
    const TYPE_DOCUMENT = 'document';
    const TYPE_AUDIO = 'audio';
    const TYPE_SPREADSHEET = 'spreadsheet';
    const TYPE_PRESENTATION = 'presentation';
    const TYPE_ARCHIVE = 'archive';
    const TYPE_OTHER = 'other';

    const TYPES = [
        self::TYPE_IMAGE,
        self::TYPE_VIDEO,
        self::TYPE_PDF,
        self::TYPE_DOCUMENT,
        self::TYPE_AUDIO,
        self::TYPE_SPREADSHEET,
        self::TYPE_PRESENTATION,
        self::TYPE_ARCHIVE,
        self::TYPE_OTHER,
    ];

    const VISIBILITY_PRIVATE = 'private';
    const VISIBILITY_ORG = 'org';
    const VISIBILITY_TEACHERS_ONLY = 'teachers_only';
    const VISIBILITY_PARENTS_ONLY = 'parents_only';
    const VISIBILITY_STUDENTS = 'students';
    const VISIBILITY_PUBLIC = 'public';

    const VISIBILITIES = [
        self::VISIBILITY_PRIVATE,
        self::VISIBILITY_ORG,
        self::VISIBILITY_TEACHERS_ONLY,
        self::VISIBILITY_PARENTS_ONLY,
        self::VISIBILITY_STUDENTS,
        self::VISIBILITY_PUBLIC,
    ];

    const STATUS_PROCESSING = 'processing';
    const STATUS_READY = 'ready';
    const STATUS_ARCHIVED = 'archived';
    const STATUS_FAILED = 'failed';

    const STATUSES = [
        self::STATUS_PROCESSING,
        self::STATUS_READY,
        self::STATUS_ARCHIVED,
        self::STATUS_FAILED,
    ];

    // ── Relationships ──

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(Question::class, 'media_asset_question')
            ->withPivot(['context', 'order_position'])
            ->withTimestamps();
    }

    public function contentLessons(): BelongsToMany
    {
        return $this->belongsToMany(ContentLesson::class, 'media_asset_content_lesson')
            ->withPivot(['context', 'order_position'])
            ->withTimestamps();
    }

    public function assessments(): BelongsToMany
    {
        return $this->belongsToMany(Assessment::class, 'media_asset_assessment')
            ->withPivot(['context', 'order_position'])
            ->withTimestamps();
    }

    public function journeyCategories(): BelongsToMany
    {
        return $this->belongsToMany(JourneyCategory::class, 'media_asset_journey_category')
            ->withTimestamps();
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'media_asset_course')
            ->withPivot(['context', 'order_position'])
            ->withTimestamps();
    }

    // ── Scopes ──

    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeReady($query)
    {
        return $query->where('status', self::STATUS_READY);
    }

    public function scopeNotArchived($query)
    {
        return $query->whereNull('archived_at')->where('status', '!=', self::STATUS_ARCHIVED);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeWithVisibility($query, string $visibility)
    {
        return $query->where('visibility', $visibility);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('title', 'like', "%{$term}%")
              ->orWhere('description', 'like', "%{$term}%")
              ->orWhere('original_filename', 'like', "%{$term}%")
              ->orWhereJsonContains('tags', $term);
        });
    }

    // ── Accessors ──

    public function getUrlAttribute(): ?string
    {
        if ($this->source_type === 'external_link') {
            return $this->source_url;
        }

        return Storage::disk($this->storage_disk)->url($this->storage_path);
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        if (!$this->thumbnail_path) {
            return null;
        }

        return Storage::disk($this->storage_disk)->url($this->thumbnail_path);
    }

    public function getFormattedSizeAttribute(): string
    {
        $bytes = $this->size_bytes;
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0) . ' KB';
        }
        return $bytes . ' B';
    }

    public function getIsArchivedAttribute(): bool
    {
        return $this->archived_at !== null || $this->status === self::STATUS_ARCHIVED;
    }

    public function getUsageCountAttribute(): int
    {
        return $this->questions()->count()
             + $this->contentLessons()->count()
             + $this->assessments()->count()
             + $this->courses()->count();
    }

    /**
     * Check if this asset's file is referenced anywhere in the system.
     * Checks both pivot table links and direct path references in content tables.
     */
    public function getIsLinkedAttribute(): bool
    {
        // Pivot table links
        if ($this->usage_count > 0) {
            return true;
        }

        // No storage path to check (external links)
        if (!$this->storage_path) {
            return false;
        }

        $path = $this->storage_path;
        $url = Storage::disk($this->storage_disk)->url($path);

        // Check if the path or URL appears in lesson slide blocks (JSON content)
        $inSlides = \Illuminate\Support\Facades\DB::table('lesson_slides')
            ->where('blocks', 'like', '%' . addcslashes($path, '%_') . '%')
            ->exists();
        if ($inSlides) return true;

        // Check articles
        $inArticles = \Illuminate\Support\Facades\DB::table('articles')
            ->where(function ($q) use ($path) {
                $q->where('thumbnail', $path)
                  ->orWhere('pdf', $path)
                  ->orWhere('author_photo', $path)
                  ->orWhere('images', 'like', '%' . addcslashes($path, '%_') . '%');
            })->exists();
        if ($inArticles) return true;

        // Check products
        $inProducts = \Illuminate\Support\Facades\DB::table('products')
            ->where('image_path', $path)
            ->exists();
        if ($inProducts) return true;

        // Check services
        $inServices = \Illuminate\Support\Facades\DB::table('services')
            ->where('media', 'like', '%' . addcslashes($path, '%_') . '%')
            ->exists();
        if ($inServices) return true;

        return false;
    }

    // ── Methods ──

    public function archive(): void
    {
        $this->update([
            'status' => self::STATUS_ARCHIVED,
            'archived_at' => now(),
        ]);
    }

    public function restore(): void
    {
        $this->update([
            'status' => self::STATUS_READY,
            'archived_at' => null,
        ]);
    }

    public function canBeDeleted(): bool
    {
        return !$this->is_linked;
    }

    public static function resolveTypeFromMime(string $mime): string
    {
        $map = [
            'image/' => self::TYPE_IMAGE,
            'video/' => self::TYPE_VIDEO,
            'audio/' => self::TYPE_AUDIO,
            'application/pdf' => self::TYPE_PDF,
            'application/msword' => self::TYPE_DOCUMENT,
            'application/vnd.openxmlformats-officedocument.wordprocessingml' => self::TYPE_DOCUMENT,
            'text/plain' => self::TYPE_DOCUMENT,
            'text/rtf' => self::TYPE_DOCUMENT,
            'application/rtf' => self::TYPE_DOCUMENT,
            'application/vnd.ms-excel' => self::TYPE_SPREADSHEET,
            'application/vnd.openxmlformats-officedocument.spreadsheetml' => self::TYPE_SPREADSHEET,
            'text/csv' => self::TYPE_SPREADSHEET,
            'application/vnd.ms-powerpoint' => self::TYPE_PRESENTATION,
            'application/vnd.openxmlformats-officedocument.presentationml' => self::TYPE_PRESENTATION,
            'application/zip' => self::TYPE_ARCHIVE,
            'application/x-rar' => self::TYPE_ARCHIVE,
            'application/gzip' => self::TYPE_ARCHIVE,
        ];

        foreach ($map as $prefix => $type) {
            if (str_starts_with($mime, $prefix)) {
                return $type;
            }
        }

        return self::TYPE_OTHER;
    }
}
