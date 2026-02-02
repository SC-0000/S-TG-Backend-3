<?php

namespace Tests\Feature\Api;

use App\Models\Application;
use App\Models\Child;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;
use Tests\TestCase;

class AiDataBackedTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_ai_chat_returns_reply_and_session(): void
    {
        OpenAI::swap($this->fakeOpenAi('Hello from AI'));

        $owner = User::factory()->create(['role' => 'admin']);
        $org = Organization::create([
            'name' => 'Org One',
            'slug' => 'org-one',
            'status' => 'active',
            'owner_id' => $owner->id,
            'settings' => [
                'features' => [
                    'parent' => [
                        'ai' => [
                            'chatbot' => true,
                        ],
                    ],
                ],
            ],
        ]);

        $parent = User::factory()->create([
            'role' => 'parent',
            'current_organization_id' => $org->id,
        ]);

        $subscription = Subscription::create([
            'name' => 'AI Plan',
            'slug' => 'ai-plan',
            'features' => ['ai_analysis' => true],
        ]);

        DB::table('user_subscriptions')->insert([
            'user_id' => $parent->id,
            'subscription_id' => $subscription->id,
            'starts_at' => now(),
            'ends_at' => null,
            'status' => 'active',
            'source' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $application = Application::create([
            'application_id' => (string) Str::uuid(),
            'applicant_name' => 'Parent Applicant',
            'email' => 'parent@app.test',
            'application_status' => 'Approved',
            'application_type' => 'Type1',
            'submitted_date' => now(),
            'organization_id' => $org->id,
        ]);

        $child = Child::create([
            'application_id' => $application->application_id,
            'user_id' => $parent->id,
            'child_name' => 'Test Child',
            'age' => 10,
            'school_name' => 'Test School',
            'area' => 'Test Area',
            'year_group' => 'Year 5',
            'organization_id' => $org->id,
        ]);

        $response = $this->actingAs($parent, 'sanctum')
            ->postJson('/api/v1/ai/chat', [
                'prompt' => 'Hello',
                'child_id' => $child->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.reply', 'Hello from AI')
            ->assertJsonPath('data.session_id', $response->json('data.session_id'));

        $this->assertDatabaseHas('chat_sessions', [
            'child_id' => $child->id,
            'section' => 'tutor',
        ]);
    }

    public function test_ai_agent_is_org_scoped_for_admin(): void
    {
        OpenAI::swap($this->fakeOpenAi('Scoped reply'));

        $owner = User::factory()->create(['role' => 'admin']);
        $orgOne = Organization::create([
            'name' => 'Org One',
            'slug' => 'org-one',
            'status' => 'active',
            'owner_id' => $owner->id,
            'settings' => [
                'features' => [
                    'parent' => [
                        'ai' => [
                            'chatbot' => true,
                        ],
                    ],
                ],
            ],
        ]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'current_organization_id' => $orgOne->id,
        ]);

        $ownerTwo = User::factory()->create(['role' => 'admin']);
        $orgTwo = Organization::create([
            'name' => 'Org Two',
            'slug' => 'org-two',
            'status' => 'active',
            'owner_id' => $ownerTwo->id,
        ]);

        $application = Application::create([
            'application_id' => (string) Str::uuid(),
            'applicant_name' => 'Other Parent',
            'email' => 'other@app.test',
            'application_status' => 'Approved',
            'application_type' => 'Type1',
            'submitted_date' => now(),
            'organization_id' => $orgTwo->id,
        ]);

        $child = Child::create([
            'application_id' => $application->application_id,
            'user_id' => $admin->id,
            'child_name' => 'Other Child',
            'age' => 9,
            'school_name' => 'Other School',
            'area' => 'Other Area',
            'year_group' => 'Year 4',
            'organization_id' => $orgTwo->id,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/ai/tutor/chat', [
                'child_id' => $child->id,
                'message' => 'Hi',
            ]);

        $response->assertStatus(404);
    }

    private function fakeOpenAi(string $reply): object
    {
        return new class($reply) {
            private string $reply;

            public function __construct(string $reply)
            {
                $this->reply = $reply;
            }

            public function chat(): object
            {
                $reply = $this->reply;

                return new class($reply) {
                    private string $reply;

                    public function __construct(string $reply)
                    {
                        $this->reply = $reply;
                    }

                    public function create(array $payload): array
                    {
                        return [
                            'choices' => [
                                ['message' => ['content' => $this->reply]],
                            ],
                        ];
                    }
                };
            }
        };
    }
}
