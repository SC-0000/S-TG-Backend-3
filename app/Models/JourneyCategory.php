<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JourneyCategory extends Model
{
    protected $fillable = [
        'organization_id',
        'journey_id',
        'topic',
        'name',
        'description',
        'ai_context',
        'learning_objectives',
        'key_topics',
        'difficulty_weighting',
        'estimated_hours',
        'specification_reference',
        'parent_summary',
        'sort_order',
    ];

    protected $casts = [
        'learning_objectives' => 'array',
        'key_topics' => 'array',
        'difficulty_weighting' => 'integer',
        'estimated_hours' => 'decimal:1',
        'sort_order' => 'integer',
    ];

    // ── Relationships ──

    public function journey(): BelongsTo
    {
        return $this->belongsTo(Journey::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class, 'journey_category_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class, 'journey_category_id');
    }

    public function contentLessons(): HasMany
    {
        return $this->hasMany(ContentLesson::class, 'journey_category_id');
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'journey_category_id');
    }

    public function mediaAssets(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'media_asset_journey_category')
            ->withTimestamps();
    }

    // ── Scopes ──

    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    // ── Helpers ──

    /**
     * Build a combined AI context string from this category's metadata
     * and its parent journey. Used for AI prompt enrichment.
     */
    public function getAIContext(): string
    {
        $parts = [];

        $journey = $this->relationLoaded('journey') ? $this->journey : $this->journey()->first();

        if ($journey) {
            $parts[] = "Journey: {$journey->title}";
            if ($journey->curriculum_level) {
                $parts[] = "Level: {$journey->curriculum_level}";
            }
            if ($journey->exam_board) {
                $parts[] = "Exam Board: {$journey->exam_board}";
            }
        }

        $parts[] = "Subject: {$this->topic}";
        $parts[] = "Category: {$this->name}";

        if ($this->ai_context) {
            $parts[] = $this->ai_context;
        }

        if ($this->description && !$this->ai_context) {
            $parts[] = $this->description;
        }

        if (!empty($this->learning_objectives)) {
            $parts[] = "Learning Objectives: " . implode('; ', $this->learning_objectives);
        }

        if (!empty($this->key_topics)) {
            $parts[] = "Key Topics: " . implode(', ', $this->key_topics);
        }

        if ($this->difficulty_weighting) {
            $parts[] = "Difficulty: {$this->difficulty_weighting}/10";
        }

        return implode("\n", $parts);
    }
}
