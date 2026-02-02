<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class AuthEndpointsTest extends TestCase
{
    public function test_register_validation_errors_are_enveloped(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure([
            'data',
            'meta' => ['status', 'request_id'],
            'errors',
        ]);
    }
}
