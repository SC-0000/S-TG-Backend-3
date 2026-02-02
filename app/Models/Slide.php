<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Slide extends Model
{
    protected $primaryKey = 'slide_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'slide_id',
        'organization_id',
        'title',
        'content',
        'template_id',
        'order',
        'tags',
        'schedule',
        'status',
        'last_modified',
        'created_by',
        'version',
        'images',
    ];

    protected $casts = [
        'content'     => 'array',
        'template_id' => 'array',
        'tags'        => 'array',
        'schedule'    => 'array',
        'images'      => 'array',
        'last_modified' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }
}
