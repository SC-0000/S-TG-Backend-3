<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunicationTemplate extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'system_key',
        'channel',
        'subject',
        'body_text',
        'body_html',
        'variables',
        'category',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'variables' => 'array',
        'is_active' => 'boolean',
    ];

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_SMS = 'sms';
    public const CHANNEL_WHATSAPP = 'whatsapp';
    public const CHANNEL_MULTI = 'multi';

    public const CATEGORY_REMINDER = 'reminder';
    public const CATEGORY_MARKETING = 'marketing';
    public const CATEGORY_TRANSACTIONAL = 'transactional';
    public const CATEGORY_FOLLOWUP = 'followup';

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Render template body with variable substitution.
     */
    public function render(array $data, string $format = 'text'): string
    {
        $body = $format === 'html' && $this->body_html
            ? $this->body_html
            : $this->body_text;

        foreach ($data as $key => $value) {
            $body = str_replace('{{' . $key . '}}', (string) $value, $body);
        }

        return $body;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeForChannel($query, string $channel)
    {
        return $query->whereIn('channel', [$channel, self::CHANNEL_MULTI]);
    }

    public function scopeForSystemKey($query, string $key)
    {
        return $query->where('system_key', $key);
    }
}
