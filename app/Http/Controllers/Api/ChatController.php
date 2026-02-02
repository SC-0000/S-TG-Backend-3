<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\AssessmentSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use OpenAI\Laravel\Facades\OpenAI;  // or inject OpenAI\Client
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\Lesson;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class ChatController extends Controller
{
   public function ask(Request $request)
    {
        Log::info('ask() hit');
        // 1. Validate inputs
        $request->validate([
            'prompt'     => 'required|string',
            
            'child_id'   => 'required|exists:children,id',
        ]);
        $childId = $request->input('child_id');
        $session = $this->ensureTutorSession($childId);
        $child = Child::findOrFail($childId);
        // 2. Grab or create this child's chat session
        // $session = ChatSession::firstOrCreate(
        //     ['id' => $request->input('session_id')],
        //     [
        //       'child_id' => $childId,
        //       'section'  => 'tutor',
        //       'messages' => [],
        //     ]
        // );

        // 3. Fetch that child's past submissions & build a profile string
        $submissions = AssessmentSubmission::with('assessment:id,title,questions_json')
            ->where('child_id', $childId)
            ->get();
        $now = now();

        // Example: fetch lessons linked to the child’s classes/services
        // Use the service relationship to filter lessons/assessments by child's services
        $serviceFilter = function($q) use ($childId) {
            $q->whereHas('children', fn($q2) =>
            $q2->where('children.id', $childId)
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
        Log::info('Fetched upcoming lessons and assessments', [
            'lessons_count' => $upcomingLessons,
            'assessments_count' => $upcomingAssessments,
        ]);
        $profileLines = ["Child {$child->child_name} with id #{$childId} Assessment History:\n"];
        foreach ($submissions as $sub) {
            $profileLines[] = "- {$sub->assessment->title} "
              . "(Scored {$sub->marks_obtained}/{$sub->total_marks} "
              . "on {$sub->finished_at->toDateTimeString()}):";
            $profileLines[] = "  Q: " . json_encode($sub->assessment->questions_json);
            $profileLines[] = "  A: " . json_encode($sub->answers_json) . "\n";
        }
        $profileLines[] = "\nUpcoming Lessons:\n";
        foreach ($upcomingLessons as $lesson) {
            $profileLines[] = "- {$lesson->title} on " . $lesson->start_time->toDateTimeString();
            if ($lesson->description) {
                $profileLines[] = "  Details: {$lesson->description}";
            }
        }

        $profileLines[] = "\nUpcoming Assessments:\n";
        foreach ($upcomingAssessments as $assessment) {
            $profileLines[] = "- {$assessment->title} on " . $assessment->scheduled_at->toDateTimeString();
            if ($assessment->description) {
                $profileLines[] = "  Details: {$assessment->description}";
            }
        }
        $profileText = implode("\n", $profileLines);

        // 4. Build the ordered list of messages
        $messages = [];

        // 4a) Static system prompt (this is cacheable)
        $messages[] = [
            'role'    => 'system',
            'content' => "You are a personalized tutor. Use the student profile and chat history to give tailored feedback.",
        ];

        // 4b) Dynamic profile snippet (not cacheable across children)
        $messages[] = [
            'role'    => 'system',
            'content' => $profileText,
        ];

        // 4c) Any prior turns in this session
        foreach ($session->messages as $turn) {
            $messages[] = [
                'role'    => $turn['role'],
                'content' => $turn['content'],
            ];
        }

        // 4d) The new user question
        $messages[] = [
            'role'    => 'user',
            'content' => $request->input('prompt'),
        ];

        Log::info('Chat payload', ['messages' => $messages]);

        // 5. Send to OpenAI
        $openaiResponse = OpenAI::chat()->create([
            'model'    => 'gpt-5-nano',
            'user'     => (string) $childId,
            'messages' => $messages,
        ]);

        $reply = $openaiResponse['choices'][0]['message']['content'];

        // 6. Persist the new turns
        $session->messages = array_merge(
            $session->messages,
            [
              ['role' => 'user',      'content' => $request->input('prompt')],
              ['role' => 'assistant', 'content' => $reply],
            ]
        );
        $session->save();

        // 7. Return to front-end
        return response()->json([
            'reply'      => $reply,
            'session_id' => $session->id,
        ]);
    }

    public function fetch(Request $r)
    {
        $r->validate(['session_id'=>'required|exists:chat_sessions,id']);
        return response()->json(['messages'=>ChatSession::findOrFail($r->session_id)->messages]);
    }
    private function ensureTutorSession(int $childId): ChatSession
    {
        $child = Child::findOrFail($childId);

        if ($child->tutorSession) {
            return $child->tutorSession;              // existing
        }

        return $child->tutorSession()->create([
            'section'  => 'tutor',
            'messages' => [],
        ]);
    }
    public function open(Request $r)
{
    $childId = $r->validate(['child_id'=>'required|exists:children,id'])['child_id'];

    $session = $this->ensureTutorSession($childId);

    return response()->json([
        'session_id' => $session->id,
        'messages'   => $session->messages,
    ]);
}
    public function hintLoop(Request $request)
    {
        $request->validate([
            'question'      => 'required|string',
            'child_attempt' => 'required|string',
            'child_id'      => 'required|exists:children,id',
            'history'       => 'nullable|array',
        ]);

        // Base system instruction
        $messages = [
            ['role' => 'system', 'content' =>
                'You are a friendly tutor. Only give hints to guide the student if they are wrong; if correct, congratulate but do not reveal the solution.'
            ],
        ];

        // 1️⃣ Rehydrate prior turns
        if ($request->filled('history')) {
            foreach ($request->history as $turn) {
                $messages[] = [
                    'role'    => $turn['role'],
                    'content' => $turn['content'],
                ];
            }
        }

        // 2️⃣ Append the new attempt
        $messages[] = [
            'role'    => 'user',
            'content' => "Question: {$request->question}\nStudent's Attempt: {$request->child_attempt}"
        ];

        // 3️⃣ Call OpenAI
        $apiResponse = OpenAI::chat()->create([
            'model'    => 'gpt-5-nano',
            'user'     => (string) $request->child_id,
            'messages' => $messages,
        ]);

        $feedback = $apiResponse['choices'][0]['message']['content'];

        return response()->json(['feedback' => $feedback]);
    }

    public function show()
    {
        // eager-load the “children” of the logged-in user
        $children = Auth::user()->children()->select('id', 'child_name')->get();

        return Inertia::render('@parent/ChatAI/Chat', [
            'children' => $children,
        ]);
    }
}
