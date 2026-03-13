<?php

namespace App\Services\AI;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

/**
 * OrgDataRequirementsAnalyzer
 * Stage 1 analyzer for admin/teacher org-level AI.
 */
class OrgDataRequirementsAnalyzer
{
    public function analyze(string $userMessage, User $user, array $conversationHistory = [], array $forcedFilters = []): array
    {
        try {
            $systemPrompt = $this->getAnalysisSystemPrompt($user);

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
            ];

            $recentHistory = array_slice($conversationHistory, -10);
            foreach ($recentHistory as $msg) {
                $messages[] = $msg;
            }

            $messages[] = ['role' => 'user', 'content' => $this->buildAnalysisPrompt($userMessage, $user, $conversationHistory)];

            $response = OpenAI::chat()->create([
                'model' => 'gpt-5-nano',
                'messages' => $messages,
                'functions' => [$this->getDataRequirementsFunctionDefinition()],
                'function_call' => ['name' => 'specify_data_requirements'],
            ]);

            $parsed = $this->parseDataRequirements($response);
            if (!empty($forcedFilters)) {
                $parsed['filters'] = array_merge($parsed['filters'] ?? [], $forcedFilters);
            }

            return $parsed;
        } catch (\Exception $e) {
            Log::error('OrgDataRequirementsAnalyzer error', [
                'user_id' => $user->id,
                'message' => $userMessage,
                'error' => $e->getMessage(),
            ]);

            return [
                'required_data' => ['none'],
                'filters' => $forcedFilters,
                'reasoning' => 'Error in analysis, proceeding without database context',
            ];
        }
    }

    protected function getAnalysisSystemPrompt(User $user): string
    {
        $role = $user->role ?? 'admin';

        return "You are a data requirements analyzer for an education platform {$role} assistant.

Your task: Analyze the user's question and determine what database information is needed.

Available data sources:
- org_overview: organization-wide counts (children, teachers, services, courses, sessions)
- child_summary: child identity and basic info
- child_access: child access entitlements (lessons/assessments/courses/live sessions)
- child_progress: lesson progress summary
- child_submissions: assessment submissions summary
- assessment_summary: counts of assessments and question bank items
- assessment_questions: questions linked to a specific assessment
- assessment_access_stats: how many students have access to a specific assessment
- assessment_access_students: list student names who have access to a specific assessment
- assessments_catalog: assessment list with names and question counts
- content_lessons_catalog: content lesson list
- live_sessions_catalog: live session list
- courses_catalog: course list with module/lesson counts
- services_catalog: service list
- teacher_load: teacher student load and assignments
- teacher_schedule: upcoming live sessions for a teacher
- journey_progress: progress tracker style course summaries
- revenue_summary: totals and recent transactions
- none: no database data needed (general guidance)

Domain structure (for deciding data needs):
- Services grant access to content.
- Assessment services include assessments.
- Lesson services include lessons (online or face-to-face). Online lessons have a live session attached.
- Bundle services include lessons + assessments.
- Flexible services include lessons + assessments.
- Course services include courses. A course has modules; each module can have assessments, content lessons, and live sessions.
- Every live session must be linked to a content lesson.

IMPORTANT RULES:
1. Only request data that is directly relevant.
2. Prefer summary data over raw data.
3. Use time filters when questions mention recent/upcoming periods.
4. If the question is general policy or advice, return 'none'.
5. If a specific child or teacher is referenced, include a filter like child_name or teacher_name.
6. If the user is a teacher, do NOT request revenue_summary.
";
    }

    protected function buildAnalysisPrompt(string $userMessage, User $user, array $conversationHistory = []): string
    {
        $prompt = "User Question: \"{$userMessage}\"\n\n" .
                  "User Context:\n" .
                  "- User ID: {$user->id}\n" .
                  "- Role: {$user->role}\n\n";

        if (!empty($conversationHistory)) {
            $prompt .= "Review the conversation history above. If the answer is already available there, request 'none'.\n\n";
        }

        $prompt .= "Determine what database information is needed to answer this question.";

        return $prompt;
    }

    protected function getDataRequirementsFunctionDefinition(): array
    {
        return [
            'name' => 'specify_data_requirements',
            'description' => 'Specify what database information is needed to answer the admin/teacher question',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'required_data' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string',
                            'enum' => [
                                'org_overview',
                                'child_summary',
                                'child_access',
                                'child_progress',
                                'child_submissions',
                                'assessment_summary',
                                'assessment_questions',
                                'assessment_access_stats',
                                'assessment_access_students',
                                'assessments_catalog',
                                'content_lessons_catalog',
                                'live_sessions_catalog',
                                'courses_catalog',
                                'services_catalog',
                                'teacher_load',
                                'teacher_schedule',
                                'journey_progress',
                                'revenue_summary',
                                'none',
                            ],
                        ],
                        'description' => 'List of required data sources',
                    ],
                    'filters' => [
                        'type' => 'object',
                        'properties' => [
                            'time_range' => [
                                'type' => 'string',
                                'enum' => ['last_7_days', 'last_30_days', 'last_90_days', 'upcoming_7_days', 'upcoming_30_days', 'all'],
                            ],
                            'child_id' => [
                                'type' => 'integer',
                            ],
                            'teacher_id' => [
                                'type' => 'integer',
                            ],
                            'assessment_id' => [
                                'type' => 'integer',
                            ],
                            'assessment_title' => [
                                'type' => 'string',
                            ],
                            'child_name' => [
                                'type' => 'string',
                            ],
                            'teacher_name' => [
                                'type' => 'string',
                            ],
                            'status' => [
                                'type' => 'string',
                            ],
                            'limit' => [
                                'type' => 'integer',
                                'minimum' => 1,
                                'maximum' => 50,
                            ],
                        ],
                    ],
                    'reasoning' => [
                        'type' => 'string',
                    ],
                ],
                'required' => ['required_data', 'filters', 'reasoning'],
            ],
        ];
    }

    protected function parseDataRequirements($response): array
    {
        $data = null;

        if (is_object($response) && isset($response->choices[0]->message)) {
            $message = $response->choices[0]->message;
            if (is_object($message) && property_exists($message, 'function_call')) {
                $functionCall = $message->function_call;
                if (is_object($functionCall) && property_exists($functionCall, 'arguments')) {
                    $data = $functionCall->arguments;
                }
            }
        }

        if (!$data) {
            $responseData = method_exists($response, 'toArray') ? $response->toArray() : (array) $response;
            $data = $responseData['choices'][0]['message']['function_call']['arguments'] ?? null;
        }

        if (!$data) {
            return [
                'required_data' => ['none'],
                'filters' => [],
                'reasoning' => 'No structured data requirements returned',
            ];
        }

        $decoded = json_decode($data, true);

        return [
            'required_data' => $decoded['required_data'] ?? ['none'],
            'filters' => $decoded['filters'] ?? [],
            'reasoning' => $decoded['reasoning'] ?? 'No reasoning provided',
        ];
    }
}
