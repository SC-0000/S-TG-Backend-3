<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class LiveSessionsEndpointsTest extends TestCase
{
    public function test_live_session_endpoints_require_auth(): void
    {
        $this->getJson('/api/v1/live-sessions')->assertStatus(401);
        $this->postJson('/api/v1/live-sessions', [])->assertStatus(401);
        $this->getJson('/api/v1/live-sessions/1')->assertStatus(401);
        $this->getJson('/api/v1/live-sessions/1/teach')->assertStatus(401);
        $this->putJson('/api/v1/live-sessions/1', [])->assertStatus(401);
        $this->deleteJson('/api/v1/live-sessions/1')->assertStatus(401);

        $this->postJson('/api/v1/live-sessions/1/start', [])->assertStatus(401);
        $this->postJson('/api/v1/live-sessions/1/state', [])->assertStatus(401);
        $this->postJson('/api/v1/live-sessions/1/slide', [])->assertStatus(401);
        $this->postJson('/api/v1/live-sessions/1/highlight', [])->assertStatus(401);
        $this->deleteJson('/api/v1/live-sessions/1/annotation')->assertStatus(401);
        $this->postJson('/api/v1/live-sessions/1/navigation-lock', [])->assertStatus(401);
        $this->getJson('/api/v1/live-sessions/1/participants')->assertStatus(401);

        $this->postJson('/api/v1/live-sessions/1/join', [])->assertStatus(401);
        $this->postJson('/api/v1/live-sessions/1/leave', [])->assertStatus(401);
        $this->postJson('/api/v1/live-sessions/1/hand', [])->assertStatus(401);
        $this->postJson('/api/v1/live-sessions/1/reaction', [])->assertStatus(401);
        $this->postJson('/api/v1/live-sessions/1/token', [])->assertStatus(401);
        $this->getJson('/api/v1/live-sessions/1/messages')->assertStatus(401);
        $this->postJson('/api/v1/live-sessions/1/messages', [])->assertStatus(401);
        $this->postJson('/api/v1/live-sessions/1/messages/1/answer', [])->assertStatus(401);
        $this->postJson('/api/v1/live-sessions/1/participants/1/mute', [])->assertStatus(401);
        $this->postJson('/api/v1/live-sessions/1/participants/1/lower-hand', [])->assertStatus(401);
        $this->postJson('/api/v1/live-sessions/1/participants/1/camera', [])->assertStatus(401);
        $this->postJson('/api/v1/live-sessions/1/participants/mute-all', [])->assertStatus(401);
        $this->postJson('/api/v1/live-sessions/1/participants/1/kick', [])->assertStatus(401);
    }
}
