<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Journey extends Model
{
    protected $fillable = [
        'organization_id',
        'title',
        'description',
        'exam_end_date',
        'exam_board',
        'curriculum_level',
        'year_groups',
        'exam_dates',
        'exam_website_url',
        'specification_reference',
        'cover_image',
    ];

    protected $casts = [
        'year_groups' => 'array',
        'exam_dates' => 'array',
    ];

    public function categories(): HasMany
    {
        return $this->hasMany(JourneyCategory::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }
}
