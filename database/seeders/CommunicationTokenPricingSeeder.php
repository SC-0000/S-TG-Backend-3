<?php

namespace Database\Seeders;

use App\Models\AgentTokenPricing;
use Illuminate\Database\Seeder;

class CommunicationTokenPricingSeeder extends Seeder
{
    public function run(): void
    {
        $pricings = [
            [
                'name' => 'SMS Send',
                'ai_model' => 'telnyx',
                'operation_type' => 'sms_send',
                'platform_tokens_per_1k_input' => 0,
                'platform_tokens_per_1k_output' => 0,
                'platform_tokens_flat' => 2,
                'is_active' => true,
                'effective_from' => now()->toDateString(),
                'notes' => 'Flat rate per outbound SMS message',
            ],
            [
                'name' => 'WhatsApp Send',
                'ai_model' => 'telnyx',
                'operation_type' => 'whatsapp_send',
                'platform_tokens_per_1k_input' => 0,
                'platform_tokens_per_1k_output' => 0,
                'platform_tokens_flat' => 1,
                'is_active' => true,
                'effective_from' => now()->toDateString(),
                'notes' => 'Flat rate per outbound WhatsApp message',
            ],
            [
                'name' => 'WhatsApp AI Response',
                'ai_model' => 'telnyx',
                'operation_type' => 'whatsapp_ai_response',
                'platform_tokens_per_1k_input' => 0,
                'platform_tokens_per_1k_output' => 0,
                'platform_tokens_flat' => 3,
                'is_active' => true,
                'effective_from' => now()->toDateString(),
                'notes' => 'WhatsApp message + AI processing (1 msg + 2 AI)',
            ],
            [
                'name' => 'Voice Call (per minute)',
                'ai_model' => 'telnyx',
                'operation_type' => 'voice_minute',
                'platform_tokens_per_1k_input' => 0,
                'platform_tokens_per_1k_output' => 0,
                'platform_tokens_flat' => 5,
                'is_active' => true,
                'effective_from' => now()->toDateString(),
                'notes' => 'Flat rate per minute of voice call',
            ],
            [
                'name' => 'Call Transcription',
                'ai_model' => 'openai',
                'operation_type' => 'call_transcription',
                'platform_tokens_per_1k_input' => 0,
                'platform_tokens_per_1k_output' => 0,
                'platform_tokens_flat' => 5,
                'is_active' => true,
                'effective_from' => now()->toDateString(),
                'notes' => 'Flat rate for Whisper transcription per call',
            ],
        ];

        foreach ($pricings as $pricing) {
            AgentTokenPricing::updateOrCreate(
                [
                    'ai_model' => $pricing['ai_model'],
                    'operation_type' => $pricing['operation_type'],
                ],
                $pricing
            );
        }
    }
}
