<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class NotificationEndpointsTest extends TestCase
{
    public function test_notification_endpoints_require_auth(): void
    {
        $this->getJson('/api/v1/notifications')->assertStatus(401);
        $this->getJson('/api/v1/notifications/unread')->assertStatus(401);
        $this->patchJson('/api/v1/notifications/1/read')->assertStatus(401);
        $this->patchJson('/api/v1/notifications/read-all')->assertStatus(401);
    }
}
