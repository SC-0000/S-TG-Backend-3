<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class AdminContentApiTest extends TestCase
{
    public function test_admin_course_store_requires_auth(): void
    {
        $response = $this->postJson('/api/v1/admin/courses', [
            'title' => 'Test Course',
        ]);

        $response->assertStatus(401);
    }
}
