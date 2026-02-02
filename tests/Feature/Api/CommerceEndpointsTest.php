<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class CommerceEndpointsTest extends TestCase
{
    public function test_checkout_requires_auth(): void
    {
        $this->postJson('/api/v1/checkout', [])->assertStatus(401);
        $this->postJson('/api/v1/checkout/guest', [])->assertStatus(401);
    }

    public function test_billing_requires_auth(): void
    {
        $this->getJson('/api/v1/billing/setup')->assertStatus(401);
        $this->getJson('/api/v1/billing/invoices')->assertStatus(401);
        $this->getJson('/api/v1/billing/payments')->assertStatus(401);
        $this->getJson('/api/v1/billing/portal')->assertStatus(401);
    }

    public function test_transactions_requires_auth(): void
    {
        $this->getJson('/api/v1/transactions')->assertStatus(401);
    }

    public function test_uploads_images_requires_auth(): void
    {
        $this->postJson('/api/v1/uploads/images', [])->assertStatus(401);
    }

    public function test_year_groups_requires_auth(): void
    {
        $this->getJson('/api/v1/year-groups')->assertStatus(401);
        $this->postJson('/api/v1/year-groups/bulk-update', [])->assertStatus(401);
    }

    public function test_attendance_requires_auth(): void
    {
        $this->getJson('/api/v1/attendance')->assertStatus(401);
        $this->getJson('/api/v1/attendance/lessons/1')->assertStatus(401);
        $this->postJson('/api/v1/attendance/lessons/1/mark-all', [])->assertStatus(401);
        $this->postJson('/api/v1/attendance/lessons/1/approve-all', [])->assertStatus(401);
    }
}
