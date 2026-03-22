<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Application extends Model
{
    // Set the UUID as the primary key
    public $incrementing = false;
    protected $primaryKey = 'application_id';
    protected $keyType = 'string';

    protected $table = 'applications';

    // Pipeline status constants
    public const PIPELINE_NEW          = 'new';
    public const PIPELINE_VERIFIED     = 'verified';
    public const PIPELINE_CONTACTED    = 'contacted';
    public const PIPELINE_FOLLOW_UP    = 'follow_up';
    public const PIPELINE_TRIAL_PENDING = 'trial_pending';
    public const PIPELINE_APPROVED     = 'approved';
    public const PIPELINE_REJECTED     = 'rejected';

    public const PIPELINE_STATUSES = [
        self::PIPELINE_NEW,
        self::PIPELINE_VERIFIED,
        self::PIPELINE_CONTACTED,
        self::PIPELINE_FOLLOW_UP,
        self::PIPELINE_TRIAL_PENDING,
        self::PIPELINE_APPROVED,
        self::PIPELINE_REJECTED,
    ];

    protected $fillable = [
        'application_id',
        'applicant_name',
        'email',
        'phone_number',
        'application_status',
        'pipeline_status',
        'pipeline_status_changed_at',
        'submitted_date',
        'application_type',
        'signature_path',
        'admin_feedback',
        'reviewer_id',
        'verification_token',
        'verified_at',
        'children_data',
        'referral_source',
        'tracking_code',
        'affiliate_id',
        'address_line1',
        'address_line2',
        'mobile_number',
        'user_id',
        'organization_id',
    ];

    protected $casts = [
        'submitted_date'             => 'datetime',
        'verified_at'                => 'datetime',
        'pipeline_status_changed_at' => 'datetime',
    ];

    public function children()
    {
        return $this->hasMany(Child::class, 'application_id', 'application_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function affiliate()
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function activities()
    {
        return $this->hasMany(ApplicationActivity::class, 'application_id', 'application_id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopePipelineStatus($query, string $status)
    {
        return $query->where('pipeline_status', $status);
    }

    /**
     * Number of days the application has been in its current pipeline stage.
     */
    public function daysInCurrentStage(): int
    {
        $since = $this->pipeline_status_changed_at ?? $this->updated_at ?? $this->created_at;

        return (int) $since->diffInDays(now());
    }
}
