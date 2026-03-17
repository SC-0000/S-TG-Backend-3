<?php

namespace App\Services\AI;

use App\Models\MediaAsset;
use App\Models\Organization;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;

class AIUtilityService
{
    protected TokenBillingService $billing;

    public function __construct(TokenBillingService $billing)
    {
        $this->billing = $billing;
    }

    /**
     * Generate text using OpenAI. Returns text + usage data.
     */
    public function generateText(
        string $prompt,
        string $systemPrompt = '',
        array $options = []
    ): array {
        $model = $options['model'] ?? 'gpt-5-nano';
        $maxTokens = $options['max_tokens'] ?? 2000;
        $temperature = $options['temperature'] ?? 0.7;

        $messages = [];
        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        try {
            $payload = [
                'model' => $model,
                'messages' => $messages,
                'max_completion_tokens' => $maxTokens,
            ];

            if ($this->supportsTemperature($model)) {
                $payload['temperature'] = $temperature;
            }

            $response = OpenAI::chat()->create($payload);

            $text = $response->choices[0]->message->content ?? '';
            $usage = [
                'prompt_tokens' => $response->usage->promptTokens ?? 0,
                'completion_tokens' => $response->usage->completionTokens ?? 0,
                'total_tokens' => $response->usage->totalTokens ?? 0,
            ];

            return [
                'text' => $text,
                'usage' => $usage,
                'model' => $model,
            ];
        } catch (\Exception $e) {
            Log::error('[AIUtility] Text generation failed', [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Generate structured JSON output using OpenAI.
     */
    public function generateStructuredOutput(
        string $prompt,
        string $systemPrompt = '',
        array $options = []
    ): array {
        $model = $options['model'] ?? 'gpt-5-nano';
        $maxTokens = $options['max_tokens'] ?? 2000;

        $messages = [];
        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        try {
            $payload = [
                'model' => $model,
                'messages' => $messages,
                'max_completion_tokens' => $maxTokens,
                'response_format' => ['type' => 'json_object'],
            ];

            $response = OpenAI::chat()->create($payload);

            $text = $response->choices[0]->message->content ?? '{}';
            $usage = [
                'prompt_tokens' => $response->usage->promptTokens ?? 0,
                'completion_tokens' => $response->usage->completionTokens ?? 0,
                'total_tokens' => $response->usage->totalTokens ?? 0,
            ];

            $decoded = json_decode($text, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $decoded = ['raw' => $text];
            }

            return [
                'data' => $decoded,
                'usage' => $usage,
                'model' => $model,
            ];
        } catch (\Exception $e) {
            Log::error('[AIUtility] Structured output failed', [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Generate an image using Nana Banana Pro API.
     * Stores result as MediaAsset and returns the asset.
     */
    public function generateImage(
        string $prompt,
        ?Organization $org = null,
        array $options = []
    ): array {
        $width = $options['width'] ?? 1024;
        $height = $options['height'] ?? 1024;

        try {
            $apiKey = config('services.banana.api_key', env('BANANA_API_KEY'));

            $response = Http::timeout(120)
                ->withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'Content-Type' => 'application/json',
                ])
                ->post(config('services.banana.endpoint', 'https://api.nanabanana.pro/v1/images/generate'), [
                    'prompt' => $prompt,
                    'width' => $width,
                    'height' => $height,
                    'model' => $options['model'] ?? 'nana-banana-pro',
                    'num_images' => 1,
                ]);

            if (!$response->successful()) {
                throw new \RuntimeException('Image generation failed: ' . $response->body());
            }

            $imageData = $response->json();
            $imageUrl = $imageData['data'][0]['url'] ?? $imageData['url'] ?? null;

            if (!$imageUrl) {
                throw new \RuntimeException('No image URL in response');
            }

            // Download and store the image
            $imageContent = Http::timeout(30)->get($imageUrl)->body();
            $filename = 'ai-generated/' . Str::uuid() . '.png';
            Storage::disk('public')->put($filename, $imageContent);

            // Create MediaAsset if org provided
            $mediaAsset = null;
            if ($org) {
                $mediaAsset = MediaAsset::create([
                    'organization_id' => $org->id,
                    'uploaded_by' => $org->owner_id,
                    'type' => 'image',
                    'title' => Str::limit($prompt, 100),
                    'description' => 'AI-generated image',
                    'storage_disk' => 'public',
                    'storage_path' => $filename,
                    'original_filename' => basename($filename),
                    'mime_type' => 'image/png',
                    'size_bytes' => strlen($imageContent),
                    'visibility' => 'org',
                    'status' => 'ready',
                    'metadata' => [
                        'source' => 'ai_generated',
                        'model' => 'nana-banana-pro',
                        'prompt' => $prompt,
                    ],
                ]);
            }

            return [
                'url' => Storage::disk('public')->url($filename),
                'storage_path' => $filename,
                'media_asset_id' => $mediaAsset?->id,
                'usage' => [
                    'model' => 'nana-banana-pro',
                    'operation' => 'image_generation',
                    'images' => 1,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('[AIUtility] Image generation failed', [
                'error' => $e->getMessage(),
                'prompt' => Str::limit($prompt, 200),
            ]);
            throw $e;
        }
    }

    /**
     * Check if a model supports the temperature parameter.
     * Most newer OpenAI reasoning / nano models only support temperature=1.
     */
    protected function supportsTemperature(string $model): bool
    {
        $noTempModels = [
            'o1', 'o1-mini', 'o1-preview', 'o3', 'o3-mini', 'o4-mini',
            'gpt-5-nano',
        ];

        foreach ($noTempModels as $prefix) {
            if ($model === $prefix || str_starts_with($model, $prefix . '-')) {
                return false;
            }
        }

        return true;
    }
}
