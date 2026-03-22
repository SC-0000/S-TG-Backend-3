<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TermsCondition extends Model
{
    protected $fillable = [
        'owner_type',
        'organization_id',
        'title',
        'content',
        'version',
        'applies_to',
        'is_active',
        'published_at',
        'created_by',
    ];

    protected $casts = [
        'applies_to' => 'array',
        'is_active' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function acceptances()
    {
        return $this->hasMany(TermsAcceptance::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePlatform($query)
    {
        return $query->where('owner_type', 'platform');
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('owner_type', 'organization')->where('organization_id', $organizationId);
    }

    public function scopeForRole($query, string $role)
    {
        return $query->whereJsonContains('applies_to', $role);
    }

    public static function nextVersion(string $ownerType, ?int $organizationId = null): int
    {
        $query = static::where('owner_type', $ownerType);

        if ($ownerType === 'organization' && $organizationId) {
            $query->where('organization_id', $organizationId);
        }

        return ($query->max('version') ?? 0) + 1;
    }
}
