<?php

namespace Tests\Feature\Api\SuperAdmin;

use App\Models\Organization;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\TransactionLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_view_user_activity_logs(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $user = User::factory()->create(['role' => 'parent']);
        Sanctum::actingAs($superAdmin);

        $organization = Organization::create([
            'name' => 'Test Org',
            'slug' => 'test-org',
            'status' => 'active',
            'owner_id' => $superAdmin->id,
            'settings' => [],
        ]);

        $organization->users()->attach($user->id, [
            'role' => 'parent',
            'status' => 'active',
            'invited_by' => $superAdmin->id,
            'joined_at' => now(),
        ]);

        $plan = Subscription::create([
            'name' => 'Starter',
            'slug' => 'starter',
            'features' => [],
            'content_filters' => [],
        ]);

        $user->subscriptions()->attach($plan->id, [
            'starts_at' => now(),
            'ends_at' => null,
            'status' => 'active',
            'source' => 'manual',
        ]);

        $response = $this->getJson('/api/v1/superadmin/logs/user-activity?limit=10');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'logs',
            ],
            'meta' => ['status', 'request_id'],
            'errors',
        ]);
    }

    public function test_superadmin_can_view_error_logs(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($superAdmin);

        $transaction = Transaction::create([
            'user_id' => $superAdmin->id,
            'user_email' => $superAdmin->email,
            'type' => 'purchase',
            'status' => 'pending',
            'payment_method' => 'manual',
            'subtotal' => 10,
            'discount' => 0,
            'tax' => 0,
            'total' => 10,
        ]);

        TransactionLog::create([
            'transaction_id' => $transaction->id,
            'log_message' => 'Payment failed',
            'log_type' => 'error',
        ]);

        $response = $this->getJson('/api/v1/superadmin/logs/errors?limit=10');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'logs',
            ],
            'meta' => ['status', 'request_id'],
            'errors',
        ]);
    }
}
