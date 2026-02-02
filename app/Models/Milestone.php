<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Milestone extends Model
{
    protected $table = 'milestones';

    // Use MilestoneID as the primary key.
    protected $primaryKey = 'MilestoneID';

    protected $fillable = [
        'organization_id',
        'Title',
        'Date',
        'Description',
        'Image',
        'DisplayOrder',
    ];

    protected $casts = [
         'Date' => 'date',
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
