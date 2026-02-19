<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Models\SystemSetting;

class Organization extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'public_domain',
        'portal_domain',
        'status',
        'owner_id',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    /**
     * Generate slug automatically when creating organization
     */
    protected static function booted()
    {
        static::creating(function ($organization) {
            if (!$organization->slug) {
                $organization->slug = Str::slug($organization->name);
                
                // Ensure slug is unique
                $originalSlug = $organization->slug;
                $counter = 1;
                while (static::where('slug', $organization->slug)->exists()) {
                    $organization->slug = $originalSlug . '-' . $counter;
                    $counter++;
                }
            }
        });
    }

    /**
     * Get the owner of the organization
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Get all users belonging to this organization
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_users')
                    ->withPivot(['role', 'status', 'invited_by', 'joined_at'])
                    ->withTimestamps();
    }

    /**
     * Get active users only
     */
    public function activeUsers(): BelongsToMany
    {
        return $this->users()->wherePivot('status', 'active');
    }

    /**
     * Get users with specific role
     */
    public function getUsersByRole(string $role): BelongsToMany
    {
        return $this->users()->wherePivot('role', $role);
    }

    /**
     * Get organization's articles
     */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    /**
     * Get organization's assessments
     */
    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    /**
     * Get organization's live lesson sessions (scheduled/live lessons)
     */
    public function liveLessonSessions(): HasMany
    {
        return $this->hasMany(LiveLessonSession::class);
    }

    /**
     * Get organization's content lessons (block-based lessons)
     */
    public function contentLessons(): HasMany
    {
        return $this->hasMany(ContentLesson::class);
    }

    /**
     * Get organization's courses
     */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    /**
     * Get ALL lessons (both live and content) - combined count
     */
    public function getAllLessonsCountAttribute(): int
    {
        return $this->liveLessonSessions()->count() + $this->contentLessons()->count();
    }

    /**
     * Get organization's services
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * Get organization's children
     */
    public function children(): HasMany
    {
        return $this->hasMany(Child::class);
    }

    /**
     * Get organization's transactions
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Get organization's applications
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /**
     * Get a setting value
     */
    public function getSetting(string $key, $default = null)
    {
        return data_get($this->settings, $key, $default);
    }

    /**
     * Set a setting value
     */
    public function setSetting(string $key, $value): void
    {
        $settings = $this->settings ?? [];
        data_set($settings, $key, $value);
        $this->settings = $settings;
        $this->save();
    }

    /**
     * Resolve a feature flag value with defaults and overrides.
     */
    public function featureEnabled(string $path, bool $default = false): bool
    {
        $defaults = config('features.defaults', []);
        $overrides = config('features.overrides', []);
        $systemOverrides = SystemSetting::getValue('feature_overrides', []);
        $orgFeatures = $this->settings['features'] ?? [];

        // Merge defaults -> org -> overrides (overrides win)
        $merged = array_replace_recursive($defaults, $orgFeatures);
        $merged = array_replace_recursive($merged, $systemOverrides);
        $merged = array_replace_recursive($merged, $overrides);

        $value = data_get($merged, $path);

        return is_null($value) ? $default : (bool) $value;
    }

    /**
     * Persist a feature flag under settings.features.*
     */
    public function setFeature(string $path, bool $value): void
    {
        $settings = $this->settings ?? [];
        data_set($settings, "features.{$path}", $value);
        $this->settings = $settings;
        $this->save();
    }

    /**
     * Check if organization has a specific feature enabled
     */
    public function hasFeature(string $feature): bool
    {
        return (bool) $this->getSetting("features.{$feature}", false);
    }

    /**
     * Get API key for a service
     */
    public function getApiKey(string $service): ?string
    {
        return $this->getSetting("api_keys.{$service}");
    }

    /**
     * Check if organization is within limits for a metric
     */
    public function withinLimits(string $metric): bool
    {
        $limit = $this->getSetting("limits.{$metric}");
        if (!$limit) {
            return true; // No limit set
        }

        // You can implement specific logic for different metrics
        // For now, return true as placeholder
        return true;
    }

    /**
     * Scope to get active organizations
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Check if organization is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
