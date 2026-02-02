<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class PortalEndpointsTest extends TestCase
{
    public function test_portal_endpoints_require_auth(): void
    {
        $endpoints = [
            '/api/v1/portal/dashboard',
            '/api/v1/portal/schedule',
            '/api/v1/portal/deadlines',
            '/api/v1/portal/calendar-feed',
            '/api/v1/portal/tracker',
        ];

        foreach ($endpoints as $endpoint) {
            $this->getJson($endpoint)->assertStatus(401);
        }
    }
}
