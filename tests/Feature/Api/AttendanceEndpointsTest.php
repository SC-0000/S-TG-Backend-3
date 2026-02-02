<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class AttendanceEndpointsTest extends TestCase
{
    public function test_admin_attendance_endpoints_require_auth(): void
    {
        $this->getJson('/api/v1/admin/attendance')->assertStatus(401);
        $this->getJson('/api/v1/admin/attendance/lesson/1')->assertStatus(401);
        $this->postJson('/api/v1/admin/attendance/lessons/1/mark-all', [])->assertStatus(401);
        $this->postJson('/api/v1/admin/attendance/lessons/1/approve-all', [])->assertStatus(401);
        $this->postJson('/api/v1/admin/attendance/1/approve', [])->assertStatus(401);
    }

    public function test_teacher_attendance_endpoints_require_auth(): void
    {
        $this->getJson('/api/v1/teacher/attendance')->assertStatus(401);
        $this->getJson('/api/v1/teacher/attendance/lesson/1')->assertStatus(401);
        $this->postJson('/api/v1/teacher/attendance/lessons/1/mark-all', [])->assertStatus(401);
        $this->postJson('/api/v1/teacher/attendance/lessons/1/approve-all', [])->assertStatus(401);
    }
}
