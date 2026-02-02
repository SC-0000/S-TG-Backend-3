<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\AccessResource;
use App\Models\Access;
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
                'lesson:id,session_code,status,scheduled_start_time',
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
            'lesson_id' => 'nullable|exists:lessons,id',
            'assessment_id' => 'nullable|exists:assessments,id',
            'due_date' => 'nullable|date',
            'access' => 'required|boolean',
            'payment_status' => 'required|in:paid,refunded,disputed,failed',
            'refund_id' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $access->update($data);
        $access->load([
            'child.user:id,name,email',
            'lesson:id,session_code,status,scheduled_start_time',
            'contentLesson:id,title',
            'assessment:id,title',
        ]);

        return $this->success([
            'access' => (new AccessResource($access))->resolve(),
        ]);
    }
}
