<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class JourneyEndpointsTest extends TestCase
{
    public function test_journeys_require_auth(): void
    {
        $this->getJson('/api/v1/journeys')->assertStatus(401);
    }
}
