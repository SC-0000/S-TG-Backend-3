<?php

namespace App\Services\AI\Agents;

use App\Models\User;
use App\Services\AI\OrgContextDataFetcher;
use App\Services\AI\OrgDataRequirementsAnalyzer;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

class TeacherOrgAgent
{
    protected string $model = 'gpt-5-nano';
    protected int $maxTokens = 2000;
    protected float $temperature = 0.4;

    public function process(User $user, array $context = []): array
    {
        $message = $context['message'] ?? '';
        $history = $context['history'] ?? [];
        $forcedFilters = $context['forced_filters'] ?? [];

        if (empty($message)) {
            return [
                'success' => false,
                'error' => 'No message provided',
            ];
        }

        $analyzer = app(OrgDataRequirementsAnalyzer::class);
        $requirements = $analyzer->analyze($message, $user, $history, $forcedFilters);

        $fetcher = app(OrgContextDataFetcher::class);
        $data = $fetcher->fetch($user, $requirements);

        $systemPrompt = $this->getSystemPrompt();
        $formattedContext = $this->formatContext($requirements, $data);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'system', 'content' => $formattedContext],
        ];

        foreach (array_slice($history, -10) as $msg) {
            $messages[] = $msg;
        }

        $messages[] = ['role' => 'user', 'content' => $message];

        try {
            $payload = [
                'model' => $this->model,
                'messages' => $messages,
                'max_completion_tokens' => $this->maxTokens,
                'user' => (string) $user->id,
            ];

            if ($this->supportsTemperature($this->model)) {
                $payload['temperature'] = $this->temperature;
            }

            $response = OpenAI::chat()->create($payload);
            $text = $this->extractResponse($response);

            return [
                'success' => true,
                'response' => $text,
                'metadata' => [
                    'required_data' => $requirements['required_data'] ?? [],
                    'filters' => $requirements['filters'] ?? [],
                ],
            ];
        } catch (\Exception $e) {
            Log::error('TeacherOrgAgent error', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'error' => 'AI service temporarily unavailable',
            ];
        }
    }

    protected function getSystemPrompt(): string
    {
        return "You are a teacher assistant for an education organization.

Domain structure (always keep in mind):
- Services grant access to content.
- Assessment services include assessments.
- Lesson services include lessons (online or face-to-face). Online lessons have a live session attached.
- Bundle services include lessons + assessments.
- Flexible services include lessons + assessments.
- Course services include courses. A course has modules; each module can have assessments, content lessons, and live sessions.
- Every live session must be linked to a content lesson.

Rules:
- Only answer questions related to the teacher's assigned students and live sessions.
- Do not provide revenue information.
- Be concise and conversational.
- Only ask a follow-up if the user’s request is ambiguous or missing a required detail.
- When listing people or items, format as Name (ID 3) and Course “Title” (ID 2).
- If you include related context that wasn’t directly asked, add a short “why” sentence.
- Avoid technical phrases like \"fetch\", \"query\", or \"dataset\". Use plain language.
- Include item ids when referencing a specific student/session/lesson.";
    }

    protected function formatContext(array $requirements, array $data): string
    {
        $payload = [
            'required_data' => $requirements['required_data'] ?? [],
            'filters' => $requirements['filters'] ?? [],
            'fetched_data' => $data,
        ];

        return "Context (teacher):\n" . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function extractResponse($response): string
    {
        if (is_object($response) && isset($response->choices[0]->message)) {
            $message = $response->choices[0]->message;
            $content = is_object($message) && property_exists($message, 'content')
                ? $message->content
                : (string) $message;
            return trim(is_array($content) ? json_encode($content) : (string) $content);
        }

        $responseData = method_exists($response, 'toArray') ? $response->toArray() : (array) $response;
        $message = $responseData['choices'][0]['message']['content'] ?? '';
        return trim(is_array($message) ? json_encode($message) : (string) $message);
    }

    protected function supportsTemperature(string $model): bool
    {
        return !str_starts_with($model, 'gpt-5');
    }
}
