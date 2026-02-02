<?php

namespace Tests\Feature\Api\SuperAdmin;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_update_system_settings(): void
    {
        $user = User::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($user);

        $payload = [
            'settings' => [
                'app_name' => 'API Test',
                'timezone' => 'UTC',
                'support_email' => 'support@example.com',
            ],
        ];

        $response = $this->postJson('/api/v1/superadmin/system/settings', $payload);

        $response->assertOk();
        $response->assertJsonPath('data.settings.app_name', 'API Test');

        $stored = SystemSetting::getValue('system_settings', []);
        $this->assertSame('API Test', $stored['app_name'] ?? null);
        $this->assertSame('UTC', $stored['timezone'] ?? null);
    }

    public function test_system_settings_rejects_unknown_keys(): void
    {
        $user = User::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($user);

        $payload = [
            'settings' => [
                'unknown_key' => 'value',
            ],
        ];

        $response = $this->postJson('/api/v1/superadmin/system/settings', $payload);

        $response->assertStatus(422);
        $response->assertJsonStructure([
            'data',
            'meta' => ['status', 'request_id'],
            'errors',
        ]);
    }

    public function test_superadmin_can_toggle_feature_flag(): void
    {
        $user = User::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/superadmin/system/features/parent.ai.chatbot/toggle', [
            'enabled' => false,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.flag', 'parent.ai.chatbot');
        $response->assertJsonPath('data.enabled', false);

        $overrides = SystemSetting::getValue('feature_overrides', []);
        $this->assertFalse(data_get($overrides, 'parent.ai.chatbot'));
    }
}
