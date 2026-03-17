<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentTokenPricing extends Model
{
    protected $table = 'agent_token_pricing';

    protected $fillable = [
        'name',
        'ai_model',
        'operation_type',
        'platform_tokens_per_1k_input',
        'platform_tokens_per_1k_output',
        'platform_tokens_flat',
        'is_active',
        'effective_from',
        'notes',
    ];

    protected $casts = [
        'platform_tokens_per_1k_input' => 'integer',
        'platform_tokens_per_1k_output' => 'integer',
        'platform_tokens_flat' => 'integer',
        'is_active' => 'boolean',
        'effective_from' => 'date',
    ];

    public const OP_TEXT_GENERATION = 'text_generation';
    public const OP_IMAGE_GENERATION = 'image_generation';
    public const OP_EMBEDDING = 'embedding';
    public const OP_PDF_GENERATION = 'pdf_generation';

    public static function getActivePricing(string $model, string $operationType): ?self
    {
        return self::where('ai_model', $model)
            ->where('operation_type', $operationType)
            ->where('is_active', true)
            ->where('effective_from', '<=', now()->toDateString())
            ->orderByDesc('effective_from')
            ->first();
    }

    public function calculateTokens(int $inputTokens = 0, int $outputTokens = 0): int
    {
        if ($this->platform_tokens_flat) {
            return $this->platform_tokens_flat;
        }

        $inputCost = (int) ceil($inputTokens / 1000) * $this->platform_tokens_per_1k_input;
        $outputCost = (int) ceil($outputTokens / 1000) * $this->platform_tokens_per_1k_output;

        return max(1, $inputCost + $outputCost);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where('effective_from', '<=', now()->toDateString());
    }
}
