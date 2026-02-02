<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class PublicContentTest extends TestCase
{
    public function test_public_home_is_enveloped(): void
    {
        $response = $this->getJson('/api/v1/public/home');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'meta' => ['status', 'request_id'],
            'errors',
        ]);
    }

    public function test_public_services_is_enveloped(): void
    {
        $response = $this->getJson('/api/v1/services');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'meta' => ['status', 'request_id', 'pagination'],
            'errors',
        ]);
    }
}
