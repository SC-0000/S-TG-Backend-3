<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class AdminSubscriptionsEndpointsTest extends TestCase
{
    public function test_admin_subscription_endpoints_require_auth(): void
    {
        $this->getJson('/api/v1/admin/subscriptions')->assertStatus(401);
        $this->postJson('/api/v1/admin/subscriptions', [])->assertStatus(401);
        $this->getJson('/api/v1/admin/subscriptions/1')->assertStatus(401);
        $this->putJson('/api/v1/admin/subscriptions/1', [])->assertStatus(401);
        $this->deleteJson('/api/v1/admin/subscriptions/1')->assertStatus(401);

        $this->getJson('/api/v1/admin/user-subscriptions')->assertStatus(401);
        $this->postJson('/api/v1/admin/user-subscriptions', [])->assertStatus(401);
        $this->deleteJson('/api/v1/admin/user-subscriptions/1')->assertStatus(401);
    }
}
