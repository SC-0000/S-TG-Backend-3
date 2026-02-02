<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Assessment;
use App\Models\AssessmentSubmission;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

class ParentChatController extends ApiController
{
    public function ask(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $validated = $request->validate([
            'prompt' => 'required|string|max:2000',
            'child_id' => 'required|exists:children,id',
        ]);

        $orgId = $request->attributes->get('organization_id');
        $child = $this->resolveChild($user, (int) $validated['child_id'], $orgId);
        if (!$child) {
            return $this->error('Access denied.', [], 403);
        }

        $session = $this->ensureTutorSession($child);

        $profileText = $this->buildProfileText($child);

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a personalized tutor. Use the student profile and chat history to give tailored feedback.',
            ],
            [
                'role' => 'system',
                'content' => $profileText,
            ],
        ];

        foreach ($session->messages ?? [] as $turn) {
            if (!isset($turn['role'], $turn['content'])) {
                continue;
            }
            $messages[] = [
                'role' => $turn['role'],
                'content' => $turn['content'],
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $validated['prompt'],
        ];

        $openaiResponse = OpenAI::chat()->create([
            'model' => 'gpt-5-nano',
            'user' => (string) $child->id,
            'messages' => $messages,
        ]);

        $reply = $openaiResponse['choices'][0]['message']['content'] ?? '';

        $session->messages = array_merge(
            $session->messages ?? [],
            [
                ['role' => 'user', 'content' => $validated['prompt']],
                ['role' => 'assistant', 'content' => $reply],
            ]
        );
        $session->save();

        return $this->success([
            'reply' => $reply,
            'session_id' => $session->id,
        ]);
    }

    public function fetch(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $validated = $request->validate([
            'session_id' => 'required|exists:chat_sessions,id',
        ]);

        $session = ChatSession::findOrFail($validated['session_id']);
        $child = $session->child;
        $orgId = $request->attributes->get('organization_id');

        if (!$child || !$this->resolveChild($user, (int) $child->id, $orgId)) {
            return $this->error('Access denied.', [], 403);
        }

        return $this->success([
            'messages' => $session->messages ?? [],
            'session_id' => $session->id,
        ]);
    }

    public function open(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $validated = $request->validate([
            'child_id' => 'required|exists:children,id',
        ]);

        $orgId = $request->attributes->get('organization_id');
        $child = $this->resolveChild($user, (int) $validated['child_id'], $orgId);
        if (!$child) {
            return $this->error('Access denied.', [], 403);
        }

        $session = $this->ensureTutorSession($child);

        return $this->success([
            'session_id' => $session->id,
            'messages' => $session->messages ?? [],
        ]);
    }

    public function hintLoop(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $validated = $request->validate([
            'question' => 'required|string',
            'child_attempt' => 'required|string',
            'child_id' => 'required|exists:children,id',
            'history' => 'nullable|array',
        ]);

        $orgId = $request->attributes->get('organization_id');
        $child = $this->resolveChild($user, (int) $validated['child_id'], $orgId);
        if (!$child) {
            return $this->error('Access denied.', [], 403);
        }

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a friendly tutor. Only give hints to guide the student if they are wrong; if correct, congratulate but do not reveal the solution.',
            ],
        ];

        foreach ($validated['history'] ?? [] as $turn) {
            if (!isset($turn['role'], $turn['content'])) {
                continue;
            }
            $messages[] = [
                'role' => $turn['role'],
                'content' => $turn['content'],
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => "Question: {$validated['question']}\nStudent's Attempt: {$validated['child_attempt']}",
        ];

        $apiResponse = OpenAI::chat()->create([
            'model' => 'gpt-5-nano',
            'user' => (string) $child->id,
            'messages' => $messages,
        ]);

        $feedback = $apiResponse['choices'][0]['message']['content'] ?? '';

        return $this->success([
            'feedback' => $feedback,
        ]);
    }

    private function ensureTutorSession(Child $child): ChatSession
    {
        $session = $child->tutorSession;
        if ($session) {
            return $session;
        }

        return $child->tutorSession()->create([
            'section' => 'tutor',
            'messages' => [],
        ]);
    }

    private function resolveChild($user, int $childId, ?int $orgId): ?Child
    {
        if ($user->isSuperAdmin()) {
            return Child::find($childId);
        }

        if (in_array($user->role, ['admin', 'teacher'], true)) {
            $child = Child::find($childId);
            if (!$child) {
                return null;
            }

            if ($orgId && (int) $child->organization_id !== (int) $orgId) {
                return null;
            }

            return $child;
        }

        $child = $user->children()->whereKey($childId)->first();
        if (!$child) {
            return null;
        }

        if ($orgId && (int) $child->organization_id !== (int) $orgId) {
            return null;
        }

        return $child;
    }

    private function buildProfileText(Child $child): string
    {
        $submissions = AssessmentSubmission::with('assessment:id,title,questions_json')
            ->where('child_id', $child->id)
            ->get();

        $now = now();

        $serviceFilter = function ($q) use ($child) {
            $q->whereHas('children', fn ($q2) =>
                $q2->where('children.id', $child->id)
            );
        };

        $upcomingLessons = Lesson::whereHas('service', $serviceFilter)
            ->where('start_time', '>=', $now)
            ->orderBy('start_time')
            ->limit(5)
            ->get(['title', 'start_time', 'description']);

        $upcomingAssessments = Assessment::whereHas('service', $serviceFilter)
            ->where('availability', '>=', $now)
            ->orderBy('availability')
            ->limit(5)
            ->get(['title', 'availability', 'description']);

        $profileLines = ["Child {$child->child_name} (ID {$child->id}) assessment history:"];
        foreach ($submissions as $sub) {
            $profileLines[] = "- {$sub->assessment->title} "
                . "(Scored {$sub->marks_obtained}/{$sub->total_marks} "
                . "on {$sub->finished_at?->toDateTimeString()}):";
            $profileLines[] = "  Q: " . json_encode($sub->assessment->questions_json);
            $profileLines[] = "  A: " . json_encode($sub->answers_json) . "\n";
        }

        $profileLines[] = "\nUpcoming lessons:";
        foreach ($upcomingLessons as $lesson) {
            $profileLines[] = "- {$lesson->title} on {$lesson->start_time?->toDateTimeString()}";
            if ($lesson->description) {
                $profileLines[] = "  Details: {$lesson->description}";
            }
        }

        $profileLines[] = "\nUpcoming assessments:";
        foreach ($upcomingAssessments as $assessment) {
            $profileLines[] = "- {$assessment->title} on {$assessment->availability?->toDateTimeString()}";
            if ($assessment->description) {
                $profileLines[] = "  Details: {$assessment->description}";
            }
        }

        Log::info('AI chat profile built', [
            'child_id' => $child->id,
            'submissions' => $submissions->count(),
            'lessons' => $upcomingLessons->count(),
            'assessments' => $upcomingAssessments->count(),
        ]);

        return implode("\n", $profileLines);
    }
}
