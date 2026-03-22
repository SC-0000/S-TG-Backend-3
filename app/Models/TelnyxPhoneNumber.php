<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelnyxPhoneNumber extends Model
{
    protected $fillable = [
        'organization_id',
        'phone_number',
        'messaging_profile_id',
        'capabilities',
        'is_default',
        'status',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'is_default' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function supportsSms(): bool
    {
        return (bool) data_get($this->capabilities, 'sms', false);
    }

    public function supportsWhatsApp(): bool
    {
        return (bool) data_get($this->capabilities, 'whatsapp', false);
    }

    public static function getDefaultForOrg(int $organizationId): ?self
    {
        return self::where('organization_id', $organizationId)
            ->where('is_default', true)
            ->where('status', 'active')
            ->first();
    }
}
