<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Organization;
use App\Models\TelnyxPhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PhoneNumberController extends ApiController
{
    /**
     * Get the organization's phone numbers and channel setup status.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;
        $org = Organization::findOrFail($orgId);

        $numbers = TelnyxPhoneNumber::where('organization_id', $orgId)
            ->orderByDesc('is_default')
            ->get();

        // Channel readiness status
        $channels = [
            'sms' => [
                'ready' => $numbers->contains(fn ($n) => $n->supportsSms()),
                'number' => $numbers->first(fn ($n) => $n->supportsSms())?->phone_number,
                'description' => 'Send and receive SMS text messages',
                'requires' => 'A Telnyx phone number with SMS capability',
            ],
            'whatsapp' => [
                'ready' => $numbers->contains(fn ($n) => $n->supportsWhatsApp()) && $org->getSetting('telnyx.messaging_profile_id'),
                'number' => $numbers->first(fn ($n) => $n->supportsWhatsApp())?->phone_number,
                'messaging_profile_id' => $org->getSetting('telnyx.messaging_profile_id'),
                'description' => 'Send and receive WhatsApp messages + AI agent',
                'requires' => 'A Telnyx number + WhatsApp Business profile linked via Telnyx',
            ],
            'voice' => [
                'ready' => $numbers->isNotEmpty() && $org->getSetting('telnyx.connection_id'),
                'number' => $numbers->first()?->phone_number,
                'connection_id' => $org->getSetting('telnyx.connection_id'),
                'description' => 'Inbound and outbound phone calls with recording',
                'requires' => 'A Telnyx number + Call Control connection ID',
            ],
            'email' => [
                'ready' => true,
                'description' => 'Email via your configured mail provider',
                'requires' => 'Already configured via email settings',
            ],
        ];

        $orgKey = $org->getApiKey('telnyx');
        $envKey = config('telnyx.api_key');
        $telnyxConfigured = (bool) ($orgKey ?? $envKey);

        return $this->success([
            'numbers' => $numbers,
            'channels' => $channels,
            'telnyx_configured' => $telnyxConfigured,
            'telnyx_api_key_set' => (bool) $orgKey,
            'telnyx_api_key_preview' => $orgKey ? (substr($orgKey, 0, 8) . '...') : ($envKey ? 'ENV: ' . substr($envKey, 0, 8) . '...' : null),
            'settings' => [
                'messaging_profile_id' => $org->getSetting('telnyx.messaging_profile_id'),
                'connection_id' => $org->getSetting('telnyx.connection_id'),
                'whatsapp_business_id' => $org->getSetting('telnyx.whatsapp_business_id'),
            ],
        ]);
    }

    /**
     * Search available phone numbers via Telnyx API.
     */
    public function searchNumbers(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;
        $org = Organization::findOrFail($orgId);

        $validated = $request->validate([
            'country_code' => 'required|string|size:2',
            'type' => 'nullable|in:local,toll_free,national',
            'area_code' => 'nullable|string|max:10',
            'contains' => 'nullable|string|max:15',
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        $apiKey = trim((string) ($org->getApiKey('telnyx') ?? config('telnyx.api_key', '')));
        if (!$apiKey) {
            return $this->error('Telnyx API key not configured. Ask your platform administrator to add it in the organisation integrations settings.', [], 422);
        }

        try {
            $params = [
                'filter[country_code]' => $validated['country_code'],
                'filter[limit]' => $validated['limit'] ?? 10,
            ];

            if (!empty($validated['type'])) {
                $params['filter[number_type]'] = $validated['type'];
            }

            // Area code filter — Telnyx uses national_destination_code (numeric only)
            if (!empty($validated['area_code'])) {
                $areaCode = preg_replace('/[^0-9]/', '', $validated['area_code']);
                if ($areaCode) {
                    $params['filter[national_destination_code]'] = $areaCode;
                }
            }

            // Contains filter — search for numbers containing a specific string
            if (!empty($validated['contains'])) {
                $params['filter[phone_number][contains]'] = $validated['contains'];
            }

            $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->get('https://api.telnyx.com/v2/available_phone_numbers', $params);

            if (!$response->successful()) {
                $error = $response->json('errors.0.detail') ?? $response->json('errors.0.title') ?? 'Failed to search numbers';
                Log::warning('[PhoneNumber] Telnyx search failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                    'params' => $params,
                ]);
                return $this->error($error, [], 422);
            }

            $numbers = collect($response->json('data', []))->map(function ($n) {
                // Extract feature names safely (Telnyx returns nested structures)
                $features = [];
                foreach ((array) ($n['features'] ?? []) as $f) {
                    if (is_string($f)) {
                        $features[] = $f;
                    } elseif (is_array($f) && isset($f['name'])) {
                        $features[] = $f['name'];
                    }
                }

                $regions = $n['region_information'] ?? [];
                $regionName = '';
                if (is_array($regions) && count($regions) > 0) {
                    $regionName = $regions[0]['region_name'] ?? '';
                }

                return [
                    'phone_number' => $n['phone_number'] ?? '',
                    'region' => $regionName,
                    'type' => $n['phone_number_type'] ?? 'local',
                    'features' => $features,
                    'cost' => $n['cost_information']['monthly_cost'] ?? null,
                    'currency' => $n['cost_information']['currency'] ?? 'USD',
                    'upfront_cost' => $n['cost_information']['upfront_cost'] ?? null,
                ];
            });

            return $this->success($numbers);
        } catch (\Throwable $e) {
            return $this->error('Failed to search: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * Purchase a phone number via Telnyx and save it.
     */
    public function purchaseNumber(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;
        $org = Organization::findOrFail($orgId);

        $validated = $request->validate([
            'phone_number' => 'required|string|max:20',
        ]);

        $apiKey = $org->getApiKey('telnyx') ?? config('telnyx.api_key');
        if (!$apiKey) {
            return $this->error('Telnyx API key not configured.', [], 422);
        }

        // Check if already owned
        if (TelnyxPhoneNumber::where('organization_id', $orgId)->where('phone_number', $validated['phone_number'])->exists()) {
            return $this->error('You already own this number.', [], 422);
        }

        try {
            $response = Http::withToken($apiKey)
                ->post('https://api.telnyx.com/v2/number_orders', [
                    'phone_numbers' => [['phone_number' => $validated['phone_number']]],
                    'messaging_profile_id' => $org->getSetting('telnyx.messaging_profile_id'),
                    'connection_id' => $org->getSetting('telnyx.connection_id'),
                ]);

            if (!$response->successful()) {
                $error = $response->json('errors.0.detail') ?? 'Purchase failed';
                return $this->error($error, [], 422);
            }

            $isFirst = TelnyxPhoneNumber::where('organization_id', $orgId)->count() === 0;

            $phoneRecord = TelnyxPhoneNumber::create([
                'organization_id' => $orgId,
                'phone_number' => $validated['phone_number'],
                'messaging_profile_id' => $org->getSetting('telnyx.messaging_profile_id'),
                'capabilities' => ['sms' => true, 'whatsapp' => false, 'voice' => true],
                'is_default' => $isFirst,
                'status' => 'active',
            ]);

            Log::info('[PhoneNumber] Purchased', [
                'organization_id' => $orgId,
                'phone_number' => $validated['phone_number'],
            ]);

            return $this->success($phoneRecord, [], 201);
        } catch (\Throwable $e) {
            return $this->error('Purchase failed: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * Update Telnyx integration settings.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;
        $org = Organization::findOrFail($orgId);

        $validated = $request->validate([
            'telnyx_api_key' => 'nullable|string|max:200',
            'messaging_profile_id' => 'nullable|string|max:200',
            'connection_id' => 'nullable|string|max:200',
            'whatsapp_business_id' => 'nullable|string|max:200',
        ]);

        if (isset($validated['telnyx_api_key'])) {
            $settings = $org->settings ?? [];
            data_set($settings, 'api_keys.telnyx', $validated['telnyx_api_key']);
            $org->settings = $settings;
            $org->save();
        }

        if (isset($validated['messaging_profile_id'])) {
            $org->setSetting('telnyx.messaging_profile_id', $validated['messaging_profile_id']);
        }
        if (isset($validated['connection_id'])) {
            $org->setSetting('telnyx.connection_id', $validated['connection_id']);
        }
        if (isset($validated['whatsapp_business_id'])) {
            $org->setSetting('telnyx.whatsapp_business_id', $validated['whatsapp_business_id']);
        }

        return $this->success(['message' => 'Settings updated.']);
    }

    /**
     * Test the Telnyx connection — verifies the API key works.
     */
    public function testConnection(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;
        $org = Organization::findOrFail($orgId);

        $apiKey = trim((string) ($org->getApiKey('telnyx') ?? config('telnyx.api_key', '')));

        if (!$apiKey) {
            return $this->error('No Telnyx API key found.', [], 422);
        }

        try {
            // Hit the Telnyx balance endpoint — lightweight way to verify credentials
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])->get('https://api.telnyx.com/v2/balance');

            if ($response->successful()) {
                $balance = $response->json('data.balance') ?? '?';
                $currency = $response->json('data.currency') ?? 'USD';
                return $this->success([
                    'connected' => true,
                    'balance' => $balance,
                    'currency' => $currency,
                    'key_preview' => substr($apiKey, 0, 12) . '...',
                    'key_length' => strlen($apiKey),
                ]);
            }

            return $this->error('Telnyx rejected the API key: ' . ($response->json('errors.0.detail') ?? $response->body()), [], 422);
        } catch (\Throwable $e) {
            return $this->error('Connection failed: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * Delete a phone number.
     */
    public function deleteNumber(Request $request, TelnyxPhoneNumber $phoneNumber): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;

        if ($phoneNumber->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $phoneNumber->delete();

        return $this->success(['message' => 'Number removed.']);
    }

    /**
     * Set a number as default.
     */
    public function setDefault(Request $request, TelnyxPhoneNumber $phoneNumber): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;

        if ($phoneNumber->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        TelnyxPhoneNumber::where('organization_id', $orgId)->update(['is_default' => false]);
        $phoneNumber->update(['is_default' => true]);

        return $this->success($phoneNumber->fresh());
    }
}
