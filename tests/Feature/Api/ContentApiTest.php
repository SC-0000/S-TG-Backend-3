<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class ContentApiTest extends TestCase
{
    public function test_courses_are_public(): void
    {
        $response = $this->getJson('/api/v1/courses');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'meta' => ['status', 'request_id', 'pagination'],
                'errors',
            ]);
    }
}
