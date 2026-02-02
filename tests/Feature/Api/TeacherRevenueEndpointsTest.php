<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class TeacherRevenueEndpointsTest extends TestCase
{
    public function test_teacher_revenue_requires_auth(): void
    {
        $this->getJson('/api/v1/teacher/revenue')->assertStatus(401);
    }
}
