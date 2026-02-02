<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class AiEndpointsTest extends TestCase
{
    public function test_ai_endpoints_require_auth(): void
    {
        $this->postJson('/api/v1/ai/chat', [])->assertStatus(401);
        $this->getJson('/api/v1/ai/chat/open')->assertStatus(401);
        $this->getJson('/api/v1/ai/chat/history')->assertStatus(401);
        $this->postJson('/api/v1/ai/hint-loop', [])->assertStatus(401);

        $this->postJson('/api/v1/ai/tutor/chat', [])->assertStatus(401);
        $this->postJson('/api/v1/ai/grading/review', [])->assertStatus(401);
        $this->postJson('/api/v1/ai/progress/analyze', [])->assertStatus(401);
        $this->postJson('/api/v1/ai/hints/generate', [])->assertStatus(401);
        $this->getJson('/api/v1/ai/agents/capabilities')->assertStatus(401);
    }
}
