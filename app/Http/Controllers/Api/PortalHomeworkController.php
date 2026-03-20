<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\HomeworkAssignmentResource;
use App\Models\HomeworkAssignment;
use App\Models\HomeworkSubmission;
use App\Models\Question;
use App\Models\Assessment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalHomeworkController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $childIds = $user->children->pluck('id')->all();
        if (empty($childIds)) {
            return $this->success(['assignments' => []]);
        }

        $orgId = $request->attributes->get('organization_id');

        $query = HomeworkAssignment::query()
            ->whereHas('targets', fn ($q) => $q->whereIn('child_id', $childIds))
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->orderByDesc('due_date');

        if ($this->shouldInclude($request, 'items')) {
            $query->with('items');
        }

        $assignments = $query->get();

        $submissionMap = HomeworkSubmission::query()
            ->whereIn('assignment_id', $assignments->pluck('id')->all())
            ->whereIn('student_id', $childIds)
            ->get()
            ->groupBy(fn ($s) => $s->assignment_id . ':' . $s->student_id);

        $data = $assignments->map(function ($assignment) use ($childIds, $submissionMap) {
            $payload = (new HomeworkAssignmentResource($assignment))->resolve();
            $payload['submissions'] = [];
            foreach ($childIds as $childId) {
                $key = $assignment->id . ':' . $childId;
                $attempts = $submissionMap->get($key);
                if ($attempts && $attempts->isNotEmpty()) {
                    $submission = $attempts
                        ->sort(function ($left, $right) {
                            $leftAttempt = (int) ($left->attempt ?? 0);
                            $rightAttempt = (int) ($right->attempt ?? 0);

                            if ($leftAttempt !== $rightAttempt) {
                                return $rightAttempt <=> $leftAttempt;
                            }

                            $leftTimestamp = $left->created_at?->getTimestamp() ?? 0;
                            $rightTimestamp = $right->created_at?->getTimestamp() ?? 0;
                            return $rightTimestamp <=> $leftTimestamp;
                        })
                        ->first();

                    $payload['submissions'][] = [
                        'child_id' => $childId,
                        'submission_id' => $submission->id,
                        'attempt' => $submission->attempt,
                        'attempts_used' => $attempts->count(),
                        'status' => $submission->submission_status,
                        'grade' => $submission->grade,
                        'submitted_at' => $submission->submitted_at?->toISOString(),
                        'reviewed_at' => $submission->reviewed_at?->toISOString(),
                    ];
                }
            }
            return $payload;
        })->values();

        return $this->success(['assignments' => $data]);
    }

    public function show(Request $request, HomeworkAssignment $homework): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $childIds = $user->children->pluck('id')->all();
        if (empty($childIds)) {
            return $this->error('Not found.', [], 404);
        }

        $orgId = $request->attributes->get('organization_id');
        if ($orgId && (int) $homework->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $homework->load('items', 'targets');

        if (!$homework->targets->whereIn('child_id', $childIds)->count()) {
            return $this->error('Not found.', [], 404);
        }

        $payload = (new HomeworkAssignmentResource($homework))->resolve();

        $payload['items'] = $homework->items->map(function ($item) {
            $base = [
                'id' => $item->id,
                'type' => $item->type,
                'ref_id' => $item->ref_id,
                'payload' => $item->payload,
                'sort_order' => $item->sort_order,
            ];

            if ($item->type === 'question_bank' && $item->ref_id) {
                $question = Question::find($item->ref_id);
                if ($question) {
                    $base['question'] = $question->renderForStudent();
                    $base['question_meta'] = [
                        'id' => $question->id,
                        'title' => $question->title,
                        'marks' => $question->marks,
                        'question_type' => $question->question_type,
                    ];
                }
            }

            if ($item->type === 'assessment' && $item->ref_id) {
                $assessment = Assessment::find($item->ref_id);
                if ($assessment) {
                    $base['assessment'] = [
                        'id' => $assessment->id,
                        'title' => $assessment->title,
                        'type' => $assessment->type,
                        'deadline' => $assessment->deadline?->toISOString(),
                    ];
                }
            }

            return $base;
        })->values();

        $submission = HomeworkSubmission::query()
            ->where('assignment_id', $homework->id)
            ->whereIn('student_id', $childIds)
            ->orderByDesc('created_at')
            ->first();

        $payload['latest_submission'] = $submission ? [
            'id' => $submission->id,
            'student_id' => $submission->student_id,
            'attempt' => $submission->attempt,
            'status' => $submission->submission_status,
            'submitted_at' => $submission->submitted_at?->toISOString(),
            'reviewed_at' => $submission->reviewed_at?->toISOString(),
        ] : null;

        $payload['submissions'] = HomeworkSubmission::query()
            ->where('assignment_id', $homework->id)
            ->whereIn('student_id', $childIds)
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'student_id' => $item->student_id,
                    'attempt' => $item->attempt,
                    'status' => $item->submission_status,
                    'submitted_at' => $item->submitted_at?->toISOString(),
                    'reviewed_at' => $item->reviewed_at?->toISOString(),
                    'grade' => $item->grade,
                    'feedback' => $item->feedback,
                    'attachments' => $this->toAbsoluteStorageUrls($item->attachments ?? []),
                    'content' => $item->content,
                ];
            })
            ->values()
            ->all();

        return $this->success(['assignment' => $payload]);
    }

    private function shouldInclude(Request $request, string $key): bool
    {
        $include = (string) $request->query('include', '');
        if ($include === '') {
            return false;
        }
        $parts = array_map('trim', explode(',', $include));
        return in_array($key, $parts, true);
    }

    private function toAbsoluteStorageUrls(array $paths): array
    {
        $origin = request()?->getSchemeAndHttpHost();

        return collect($paths)
            ->map(function ($path) use ($origin) {
                if (!is_string($path) || trim($path) === '') {
                    return null;
                }
                if (preg_match('/^https?:\/\//i', $path)) {
                    return $path;
                }
                $relativePath = str_starts_with($path, '/storage/')
                    ? $path
                    : '/storage/' . ltrim($path, '/');

                return $origin ? "{$origin}{$relativePath}" : $relativePath;
            })
            ->filter()
            ->values()
            ->all();
    }
}
