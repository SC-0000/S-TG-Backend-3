<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackgroundAgentConfig extends Model
{
    protected $fillable = [
        'organization_id',
        'agent_type',
        'is_enabled',
        'schedule_override',
        'settings',
        'last_run_at',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'settings' => 'array',
        'last_run_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    public function setSetting(string $key, mixed $value): void
    {
        $settings = $this->settings ?? [];
        data_set($settings, $key, $value);
        $this->update(['settings' => $settings]);
    }

    public static function getOrCreate(int $organizationId, string $agentType): self
    {
        return self::firstOrCreate(
            ['organization_id' => $organizationId, 'agent_type' => $agentType],
            ['is_enabled' => true, 'settings' => []]
        );
    }

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    public function scopeForAgent($query, string $agentType)
    {
        return $query->where('agent_type', $agentType);
    }
}
