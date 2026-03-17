<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BackgroundAgentAction extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'run_id',
        'action_type',
        'target_type',
        'target_id',
        'description',
        'before_value',
        'after_value',
        'platform_tokens_used',
        'status',
        'error_message',
        'created_at',
    ];

    protected $casts = [
        'before_value' => 'array',
        'after_value' => 'array',
        'platform_tokens_used' => 'integer',
        'created_at' => 'datetime',
    ];

    public const ACTION_CHECK = 'check';
    public const ACTION_AUTO_FIX = 'auto_fix';
    public const ACTION_GENERATE_TEXT = 'generate_text';
    public const ACTION_GENERATE_IMAGE = 'generate_image';
    public const ACTION_SEND_EMAIL = 'send_email';
    public const ACTION_CREATE_RECORD = 'create_record';
    public const ACTION_UPDATE_RECORD = 'update_record';

    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public function run(): BelongsTo
    {
        return $this->belongsTo(BackgroundAgentRun::class, 'run_id');
    }

    public function target(): MorphTo
    {
        return $this->morphTo();
    }
}
