<?php

namespace App\Services\Communications\Drivers;

use App\DTOs\SendMessageDTO;
use App\Models\CommunicationMessage;
use App\Models\Organization;
use App\Models\TelnyxPhoneNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppDriver
{
    /**
     * Send a WhatsApp message via Telnyx WhatsApp Business API.
     *
     * Uses pre-approved templates for transactional messages (outside 24h window)
     * and free-form text within the 24h conversation window.
     *
     * @return string|null Telnyx message ID
     */
    public function send(Organization $org, CommunicationMessage $message, SendMessageDTO $dto): ?string
    {
        $recipientPhone = $message->recipient_address;
        if (!$recipientPhone) {
            throw new \RuntimeException('No phone number for WhatsApp recipient');
        }

        $apiKey = $this->resolveApiKey($org);
        $fromNumber = $this->resolveFromNumber($org);

        $payload = [
            'from' => $fromNumber,
            'to' => $recipientPhone,
            'text' => $dto->bodyText,
            'messaging_profile_id' => $this->resolveMessagingProfileId($org),
            'type' => 'whatsapp',
            'use_profile_webhooks' => true,
        ];

        // If metadata contains a WhatsApp template name, use template messaging
        $templateName = data_get($dto->metadata, 'whatsapp_template');
        if ($templateName) {
            $payload['whatsapp'] = [
                'template' => [
                    'name' => $templateName,
                    'language' => ['code' => data_get($dto->metadata, 'whatsapp_language', 'en')],
                    'components' => data_get($dto->metadata, 'whatsapp_components', []),
                ],
            ];
            unset($payload['text']);
        }

        $response = Http::withToken($apiKey)
            ->post('https://api.telnyx.com/v2/messages', $payload);

        if (!$response->successful()) {
            $error = $response->json('errors.0.detail') ?? $response->body();
            Log::error('[WhatsAppDriver] Telnyx WhatsApp failed', [
                'organization_id' => $org->id,
                'status' => $response->status(),
                'error' => $error,
            ]);
            throw new \RuntimeException("Telnyx WhatsApp failed: {$error}");
        }

        return $response->json('data.id');
    }

    protected function resolveApiKey(Organization $org): string
    {
        $key = $org->getApiKey('telnyx') ?? config('telnyx.api_key');
        if (!$key) {
            throw new \RuntimeException('No Telnyx API key configured');
        }
        return $key;
    }

    protected function resolveFromNumber(Organization $org): string
    {
        // Prefer a WhatsApp-capable number
        $phoneRecord = TelnyxPhoneNumber::where('organization_id', $org->id)
            ->where('status', 'active')
            ->where('capabilities->whatsapp', true)
            ->where('is_default', true)
            ->first();

        if ($phoneRecord) {
            return $phoneRecord->phone_number;
        }

        $phoneRecord = TelnyxPhoneNumber::getDefaultForOrg($org->id);
        if ($phoneRecord) {
            return $phoneRecord->phone_number;
        }

        $fallback = $org->getSetting('telnyx.whatsapp_phone_number')
            ?? $org->getSetting('telnyx.phone_number')
            ?? config('telnyx.default_phone_number');

        if (!$fallback) {
            throw new \RuntimeException('No Telnyx WhatsApp phone number configured');
        }

        return $fallback;
    }

    protected function resolveMessagingProfileId(Organization $org): ?string
    {
        $phoneRecord = TelnyxPhoneNumber::getDefaultForOrg($org->id);

        return $phoneRecord?->messaging_profile_id
            ?? $org->getSetting('telnyx.messaging_profile_id')
            ?? config('telnyx.messaging_profile_id');
    }
}
