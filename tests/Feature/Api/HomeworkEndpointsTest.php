<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class HomeworkEndpointsTest extends TestCase
{
    public function test_homework_endpoints_require_auth(): void
    {
        $this->getJson('/api/v1/homework')->assertStatus(401);
        $this->postJson('/api/v1/homework', [])->assertStatus(401);
        $this->getJson('/api/v1/homework/1')->assertStatus(401);
        $this->putJson('/api/v1/homework/1', [])->assertStatus(401);
        $this->deleteJson('/api/v1/homework/1')->assertStatus(401);

        $this->getJson('/api/v1/homework/1/submissions')->assertStatus(401);
        $this->postJson('/api/v1/homework/1/submissions', [])->assertStatus(401);

        $this->getJson('/api/v1/homework/submissions/1')->assertStatus(401);
        $this->putJson('/api/v1/homework/submissions/1', [])->assertStatus(401);
        $this->deleteJson('/api/v1/homework/submissions/1')->assertStatus(401);
    }
}
