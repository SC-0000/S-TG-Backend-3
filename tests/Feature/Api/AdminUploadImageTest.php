<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class AdminUploadImageTest extends TestCase
{
    public function test_admin_upload_requires_auth(): void
    {
        $response = $this->postJson('/api/v1/admin/upload-image');
        $response->assertStatus(401);
    }
}
