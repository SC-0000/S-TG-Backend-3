<?php

namespace App\Services\Communications\Drivers;

use App\DTOs\SendMessageDTO;
use App\Models\CommunicationMessage;
use App\Models\Organization;
use App\Models\TelnyxPhoneNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsDriver
{
    /**
     * Send an SMS via Telnyx Messaging API.
     *
     * @return string|null Telnyx message ID
     */
    public function send(Organization $org, CommunicationMessage $message, SendMessageDTO $dto): ?string
    {
        $recipientPhone = $message->recipient_address;
        if (!$recipientPhone) {
            throw new \RuntimeException('No phone number for SMS recipient');
        }

        $apiKey = $this->resolveApiKey($org);
        $fromNumber = $this->resolveFromNumber($org);

        $response = Http::withToken($apiKey)
            ->post('https://api.telnyx.com/v2/messages', [
                'from' => $fromNumber,
                'to' => $recipientPhone,
                'text' => $dto->bodyText,
                'type' => 'SMS',
            ]);

        if (!$response->successful()) {
            $error = $response->json('errors.0.detail') ?? $response->body();
            Log::error('[SmsDriver] Telnyx SMS failed', [
                'organization_id' => $org->id,
                'status' => $response->status(),
                'error' => $error,
            ]);
            throw new \RuntimeException("Telnyx SMS failed: {$error}");
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
        $phoneRecord = TelnyxPhoneNumber::getDefaultForOrg($org->id);
        if ($phoneRecord) {
            return $phoneRecord->phone_number;
        }

        $fallback = $org->getSetting('telnyx.phone_number') ?? config('telnyx.default_phone_number');
        if (!$fallback) {
            throw new \RuntimeException('No Telnyx phone number configured');
        }

        return $fallback;
    }
}
