<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class AdminContentManagementEndpointsTest extends TestCase
{
    public function test_admin_content_management_requires_auth(): void
    {
        $this->getJson('/api/v1/admin/content/articles')->assertStatus(401);
        $this->postJson('/api/v1/admin/content/articles', [])->assertStatus(401);
        $this->getJson('/api/v1/admin/content/articles/1')->assertStatus(401);
        $this->putJson('/api/v1/admin/content/articles/1', [])->assertStatus(401);
        $this->deleteJson('/api/v1/admin/content/articles/1')->assertStatus(401);

        $this->getJson('/api/v1/admin/content/faqs')->assertStatus(401);
        $this->postJson('/api/v1/admin/content/faqs', [])->assertStatus(401);
        $this->getJson('/api/v1/admin/content/faqs/1')->assertStatus(401);
        $this->putJson('/api/v1/admin/content/faqs/1', [])->assertStatus(401);
        $this->deleteJson('/api/v1/admin/content/faqs/1')->assertStatus(401);

        $this->getJson('/api/v1/admin/content/alerts')->assertStatus(401);
        $this->postJson('/api/v1/admin/content/alerts', [])->assertStatus(401);
        $this->getJson('/api/v1/admin/content/alerts/1')->assertStatus(401);
        $this->putJson('/api/v1/admin/content/alerts/1', [])->assertStatus(401);
        $this->deleteJson('/api/v1/admin/content/alerts/1')->assertStatus(401);

        $this->getJson('/api/v1/admin/content/slides')->assertStatus(401);
        $this->postJson('/api/v1/admin/content/slides', [])->assertStatus(401);
        $this->getJson('/api/v1/admin/content/slides/1')->assertStatus(401);
        $this->putJson('/api/v1/admin/content/slides/1', [])->assertStatus(401);
        $this->deleteJson('/api/v1/admin/content/slides/1')->assertStatus(401);

        $this->getJson('/api/v1/admin/content/testimonials')->assertStatus(401);
        $this->postJson('/api/v1/admin/content/testimonials', [])->assertStatus(401);
        $this->getJson('/api/v1/admin/content/testimonials/1')->assertStatus(401);
        $this->putJson('/api/v1/admin/content/testimonials/1', [])->assertStatus(401);
        $this->deleteJson('/api/v1/admin/content/testimonials/1')->assertStatus(401);

        $this->getJson('/api/v1/admin/content/milestones')->assertStatus(401);
        $this->postJson('/api/v1/admin/content/milestones', [])->assertStatus(401);
        $this->getJson('/api/v1/admin/content/milestones/1')->assertStatus(401);
        $this->putJson('/api/v1/admin/content/milestones/1', [])->assertStatus(401);
        $this->deleteJson('/api/v1/admin/content/milestones/1')->assertStatus(401);
    }
}
