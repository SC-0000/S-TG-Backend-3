<?php

namespace Database\Seeders;

use App\Models\AgentTokenPricing;
use Illuminate\Database\Seeder;

class AgentTokenPricingSeeder extends Seeder
{
    public function run(): void
    {
        $pricing = [
            [
                'name' => 'GPT-5 Nano Text',
                'ai_model' => 'gpt-5-nano',
                'operation_type' => 'text_generation',
                'platform_tokens_per_1k_input' => 2,
                'platform_tokens_per_1k_output' => 4,
                'platform_tokens_flat' => null,
                'effective_from' => '2026-01-01',
                'notes' => 'Default pricing for GPT-5 Nano text generation',
            ],
            [
                'name' => 'GPT-5 Text',
                'ai_model' => 'gpt-5',
                'operation_type' => 'text_generation',
                'platform_tokens_per_1k_input' => 10,
                'platform_tokens_per_1k_output' => 20,
                'platform_tokens_flat' => null,
                'effective_from' => '2026-01-01',
                'notes' => 'Default pricing for GPT-5 full model text generation',
            ],
            [
                'name' => 'Nana Banana Pro Image',
                'ai_model' => 'nana-banana-pro',
                'operation_type' => 'image_generation',
                'platform_tokens_per_1k_input' => 0,
                'platform_tokens_per_1k_output' => 0,
                'platform_tokens_flat' => 50,
                'effective_from' => '2026-01-01',
                'notes' => 'Flat rate per image generation',
            ],
            [
                'name' => 'PDF Generation',
                'ai_model' => 'pdf',
                'operation_type' => 'pdf_generation',
                'platform_tokens_per_1k_input' => 0,
                'platform_tokens_per_1k_output' => 0,
                'platform_tokens_flat' => 5,
                'effective_from' => '2026-01-01',
                'notes' => 'Flat rate per PDF report generated',
            ],
        ];

        foreach ($pricing as $rule) {
            AgentTokenPricing::updateOrCreate(
                [
                    'ai_model' => $rule['ai_model'],
                    'operation_type' => $rule['operation_type'],
                    'effective_from' => $rule['effective_from'],
                ],
                $rule
            );
        }
    }
}
