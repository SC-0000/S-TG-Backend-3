<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Api\ApiController;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemSettingsController extends ApiController
{
    public function index(): JsonResponse
    {
        $settings = SystemSetting::getValue('system_settings', []);

        return $this->success([
            'settings' => $settings,
            'defaults' => [
                'app_name' => config('app.name'),
                'environment' => config('app.env'),
                'timezone' => config('app.timezone'),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'settings' => 'required|array',
            'merge' => 'nullable|boolean',
        ]);

        $existing = SystemSetting::getValue('system_settings', []);
        $incoming = $request->input('settings', []);
        $allowedKeys = config('system_settings.allowed_keys', []);

        $incomingKeys = $this->flattenKeys($incoming);
        $unknown = array_values(array_diff($incomingKeys, $allowedKeys));
        if (count($unknown) > 0) {
            return $this->error(
                'Unknown setting keys provided.',
                array_map(fn ($key) => ['message' => "Unknown setting: {$key}"], $unknown),
                422
            );
        }

        $rules = config('system_settings.rules', []);
        if (!empty($rules)) {
            $prefixedRules = [];
            foreach ($rules as $key => $rule) {
                $prefixedRules["settings.{$key}"] = "sometimes|{$rule}";
            }
            $request->validate($prefixedRules);
        }

        $settings = $request->boolean('merge')
            ? array_replace_recursive($existing, $incoming)
            : $incoming;

        $saved = SystemSetting::setValue('system_settings', $settings, $request->user()?->id);

        return $this->success([
            'message' => 'Settings updated.',
            'settings' => $saved->value ?? $settings,
        ]);
    }

    public function featureFlags(): JsonResponse
    {
        $defaults = config('features.defaults', []);
        $configOverrides = config('features.overrides', []);
        $dbOverrides = SystemSetting::getValue('feature_overrides', []);
        $mergedOverrides = array_replace_recursive($dbOverrides, $configOverrides);

        return $this->success([
            'defaults' => $defaults,
            'overrides' => $mergedOverrides,
            'sources' => [
                'database' => $dbOverrides,
                'config' => $configOverrides,
            ],
        ]);
    }

    public function toggleFeature(Request $request, string $flag): JsonResponse
    {
        $request->validate([
            'enabled' => 'nullable|boolean',
        ]);

        $defaults = config('features.defaults', []);
        $configOverrides = config('features.overrides', []);
        $dbOverrides = SystemSetting::getValue('feature_overrides', []);
        $effectiveOverrides = array_replace_recursive($dbOverrides, $configOverrides);
        $knownFlags = array_unique(array_merge(
            $this->flattenKeys($defaults),
            $this->flattenKeys($configOverrides)
        ));

        if (!in_array($flag, $knownFlags, true)) {
            return $this->error('Unknown feature flag.', [
                ['message' => "Unknown feature flag: {$flag}"],
            ], 422);
        }

        $current = data_get($effectiveOverrides, $flag, data_get($defaults, $flag, false));
        $enabled = $request->has('enabled') ? $request->boolean('enabled') : !$current;

        data_set($dbOverrides, $flag, $enabled);
        SystemSetting::setValue('feature_overrides', $dbOverrides, $request->user()?->id);

        return $this->success([
            'flag' => $flag,
            'enabled' => $enabled,
            'overrides' => $dbOverrides,
        ]);
    }

    private function flattenKeys(array $data, string $prefix = ''): array
    {
        $keys = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (is_array($value)) {
                $keys = array_merge($keys, $this->flattenKeys($value, $path));
            } else {
                $keys[] = $path;
            }
        }
        return $keys;
    }

    public function integrations(): JsonResponse
    {
        return $this->success([
            'integrations' => [],
        ]);
    }

    public function emailTemplates(): JsonResponse
    {
        return $this->success([
            'templates' => [],
        ]);
    }

    public function apiKeys(): JsonResponse
    {
        return $this->success([
            'keys' => [],
        ]);
    }

    public function backup(): JsonResponse
    {
        return $this->success(['message' => 'Backup initiated.']);
    }

    public function restore(Request $request): JsonResponse
    {
        return $this->success(['message' => 'Restore initiated.']);
    }
}
