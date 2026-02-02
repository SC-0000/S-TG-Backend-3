<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class ApiEnvelopeTest extends TestCase
{
    public function test_api_returns_enveloped_errors(): void
    {
        $response = $this->getJson('/api/v1/questions/search');

        $response->assertStatus(401);
        $response->assertJsonStructure([
            'data',
            'meta' => ['status', 'request_id'],
            'errors',
        ]);
    }
}
