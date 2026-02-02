<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class TaskEndpointsTest extends TestCase
{
    public function test_task_endpoints_require_auth(): void
    {
        $this->getJson('/api/v1/tasks')->assertStatus(401);
        $this->postJson('/api/v1/tasks', [])->assertStatus(401);
        $this->getJson('/api/v1/tasks/1')->assertStatus(401);
        $this->putJson('/api/v1/tasks/1', [])->assertStatus(401);
        $this->deleteJson('/api/v1/tasks/1')->assertStatus(401);
    }
}
