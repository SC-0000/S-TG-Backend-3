<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\AccessResource;
use App\Models\Access;
use App\Models\Child;
use App\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccessController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!($user->isAdmin() || $user->isSuperAdmin())) {
            return $this->error('Unauthorized access.', [], 403);
        }

        $orgId = $request->attributes->get('organization_id');

        $query = Access::query()
            ->with([
                'child.user:id,name,email',
                'lesson:id,title,status,start_time,end_time',
                'contentLesson:id,title',
                'assessment:id,title',
            ])
            ->when($orgId, function ($q) use ($orgId) {
                $q->whereHas('child', function ($childQuery) use ($orgId) {
                    $childQuery->where('organization_id', $orgId);
                });
            });

        if ($request->filled('child_id')) {
            $query->where('child_id', $request->child_id);
        }

        if ($request->filled('lesson_id')) {
            $query->where('lesson_id', $request->lesson_id);
        }

        if ($request->filled('content_lesson_id')) {
            $query->where('content_lesson_id', $request->content_lesson_id);
        }

        if ($request->filled('assessment_id')) {
            $query->where('assessment_id', $request->assessment_id);
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->has('access')) {
            $query->where('access', filter_var($request->access, FILTER_VALIDATE_BOOLEAN));
        }

        $accesses = $query->orderByDesc('created_at')->paginate(ApiPagination::perPage($request));

        $data = AccessResource::collection($accesses->items())->resolve();

        return $this->paginated($accesses, $data);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!($user->isAdmin() || $user->isSuperAdmin())) {
            return $this->error('Unauthorized access.', [], 403);
        }

        $data = $request->validate([
            'child_id' => 'required|exists:children,id',
            'lesson_id' => 'nullable|exists:live_sessions,id',
            'lesson_ids' => 'nullable|array',
            'lesson_ids.*' => 'integer|exists:live_sessions,id',
            'live_lesson_session_ids' => 'nullable|array',
            'live_lesson_session_ids.*' => 'integer|exists:live_lesson_sessions,id',
            'content_lesson_id' => 'nullable|exists:new_lessons,id',
            'content_lesson_ids' => 'nullable|array',
            'content_lesson_ids.*' => 'integer|exists:new_lessons,id',
            'assessment_id' => 'nullable|exists:assessments,id',
            'assessment_ids' => 'nullable|array',
            'assessment_ids.*' => 'integer|exists:assessments,id',
            'course_ids' => 'nullable|array',
            'course_ids.*' => 'integer|exists:courses,id',
            'module_ids' => 'nullable|array',
            'module_ids.*' => 'integer|exists:modules,id',
            'purchase_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'access' => 'required|boolean',
            'payment_status' => 'required|in:paid,refunded,disputed,failed',
            'refund_id' => 'nullable|string',
            'invoice_id' => 'nullable|string',
            'transaction_id' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $orgId = $request->attributes->get('organization_id');
        if ($orgId) {
            $child = Child::query()->find($data['child_id']);
            if ($child && (int) $child->organization_id !== (int) $orgId) {
                return $this->error('Unauthorized access.', [], 403);
            }
        }

        $lessonIds = $data['lesson_ids'] ?? [];
        $assessmentIds = $data['assessment_ids'] ?? [];
        $courseIds = $data['course_ids'] ?? [];
        $moduleIds = $data['module_ids'] ?? [];
        $contentLessonIds = $data['content_lesson_ids'] ?? [];
        $liveLessonSessionIds = $data['live_lesson_session_ids'] ?? [];

        if (!empty($data['lesson_id']) && empty($lessonIds)) {
            $lessonIds = [$data['lesson_id']];
        }
        if (!empty($data['assessment_id']) && empty($assessmentIds)) {
            $assessmentIds = [$data['assessment_id']];
        }
        $contentLessonId = $data['content_lesson_id'] ?? null;
        if (!$contentLessonId && count($contentLessonIds) === 1) {
            $contentLessonId = $contentLessonIds[0];
        }

        $metadata = $data['metadata'] ?? null;
        if (!empty($contentLessonIds)) {
            $metadata = array_merge($metadata ?? [], ['content_lesson_ids' => $contentLessonIds]);
        }
        if (!empty($liveLessonSessionIds)) {
            $metadata = array_merge($metadata ?? [], ['live_lesson_session_ids' => $liveLessonSessionIds]);
        }

        $access = Access::create([
            'child_id' => $data['child_id'],
            'lesson_id' => $data['lesson_id'] ?? null,
            'content_lesson_id' => $contentLessonId,
            'assessment_id' => $data['assessment_id'] ?? null,
            'lesson_ids' => $lessonIds,
            'course_ids' => $courseIds,
            'module_ids' => $moduleIds,
            'assessment_ids' => $assessmentIds,
            'purchase_date' => $data['purchase_date'] ?? now(),
            'due_date' => $data['due_date'] ?? null,
            'access' => $data['access'],
            'payment_status' => $data['payment_status'],
            'refund_id' => $data['refund_id'] ?? null,
            'invoice_id' => $data['invoice_id'] ?? null,
            'transaction_id' => $data['transaction_id'] ?? null,
            'metadata' => $metadata,
        ]);

        $access->load([
            'child.user:id,name,email',
            'lesson:id,title,status,start_time,end_time',
            'contentLesson:id,title',
            'assessment:id,title',
        ]);

        return $this->success([
            'access' => (new AccessResource($access))->resolve(),
        ], [], 201);
    }

    public function update(Request $request, Access $access): JsonResponse
    {
        $user = $request->user();
        if (!($user->isAdmin() || $user->isSuperAdmin())) {
            return $this->error('Unauthorized access.', [], 403);
        }

        $orgId = $request->attributes->get('organization_id');
        if ($orgId && $access->child && (int) $access->child->organization_id !== (int) $orgId) {
            return $this->error('Unauthorized access.', [], 403);
        }

        $data = $request->validate([
            'child_id' => 'required|exists:children,id',
            'lesson_id' => 'nullable|exists:live_sessions,id',
            'assessment_id' => 'nullable|exists:assessments,id',
            'course_ids' => 'nullable|array',
            'course_ids.*' => 'integer|exists:courses,id',
            'module_ids' => 'nullable|array',
            'module_ids.*' => 'integer|exists:modules,id',
            'content_lesson_id' => 'nullable|exists:new_lessons,id',
            'content_lesson_ids' => 'nullable|array',
            'content_lesson_ids.*' => 'integer|exists:new_lessons,id',
            'live_lesson_session_ids' => 'nullable|array',
            'live_lesson_session_ids.*' => 'integer|exists:live_lesson_sessions,id',
            'purchase_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'access' => 'required|boolean',
            'payment_status' => 'required|in:paid,refunded,disputed,failed',
            'refund_id' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $metadata = $data['metadata'] ?? $access->metadata ?? null;
        if (!empty($data['content_lesson_ids'])) {
            $metadata = array_merge($metadata ?? [], ['content_lesson_ids' => $data['content_lesson_ids']]);
        }
        if (!empty($data['live_lesson_session_ids'])) {
            $metadata = array_merge($metadata ?? [], ['live_lesson_session_ids' => $data['live_lesson_session_ids']]);
        }
        $contentLessonId = $data['content_lesson_id'] ?? $access->content_lesson_id;
        if (!$contentLessonId && !empty($data['content_lesson_ids'])) {
            $contentLessonId = $data['content_lesson_ids'][0];
        }

        $access->update([
            'child_id' => $data['child_id'],
            'lesson_id' => $data['lesson_id'] ?? null,
            'assessment_id' => $data['assessment_id'] ?? null,
            'course_ids' => $data['course_ids'] ?? $access->course_ids,
            'module_ids' => $data['module_ids'] ?? $access->module_ids,
            'content_lesson_id' => $contentLessonId,
            'purchase_date' => $data['purchase_date'] ?? $access->purchase_date,
            'due_date' => $data['due_date'] ?? null,
            'access' => $data['access'],
            'payment_status' => $data['payment_status'],
            'refund_id' => $data['refund_id'] ?? null,
            'metadata' => $metadata,
        ]);
        $access->load([
            'child.user:id,name,email',
            'lesson:id,title,status,start_time,end_time',
            'contentLesson:id,title',
            'assessment:id,title',
        ]);

        return $this->success([
            'access' => (new AccessResource($access))->resolve(),
        ]);
    }

    public function revoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!($user->isAdmin() || $user->isSuperAdmin())) {
            return $this->error('Unauthorized access.', [], 403);
        }

        $data = $request->validate([
            'ids' => 'nullable|array',
            'ids.*' => 'integer|exists:access,id',
            'manual_only' => 'nullable|boolean',
        ]);

        $orgId = $request->attributes->get('organization_id');

        $query = Access::query();
        if ($orgId) {
            $query->whereHas('child', function ($childQuery) use ($orgId) {
                $childQuery->where('organization_id', $orgId);
            });
        }

        if (!empty($data['ids'])) {
            $query->whereIn('id', $data['ids']);
        } elseif (!empty($data['manual_only'])) {
            $query->where(function ($q) {
                $q->whereNull('invoice_id')
                    ->orWhereNull('transaction_id');
            });
        } else {
            return $this->error('No revoke target provided.', [], 422);
        }

        $now = now();
        $accesses = $query->get();
        foreach ($accesses as $access) {
            $metadata = $access->metadata ?? [];
            $metadata['revoked_by_admin'] = true;
            $metadata['revoked_at'] = $now->toISOString();
            $access->access = false;
            $access->metadata = $metadata;
            $access->save();
        }

        return $this->success([
            'revoked' => $accesses->count(),
        ]);
    }
}
