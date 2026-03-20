<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Homework\HomeworkAssignmentStoreRequest;
use App\Http\Requests\Api\Homework\HomeworkAssignmentUpdateRequest;
use App\Http\Resources\HomeworkAssignmentResource;
use App\Mail\HomeworkNotificationMail;
use App\Models\AppNotification;
use App\Models\Child;
use App\Models\HomeworkAssignment;
use App\Models\HomeworkTarget;
use App\Models\User;
use App\Support\HomeworkItemResolver;
use App\Support\MailContext;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class HomeworkAssignmentController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');

        $query = HomeworkAssignment::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->withCount('targets');

        $includeItems = $this->shouldInclude($request, 'items');
        if ($includeItems) {
            $query->with('items');
        }

        ApiQuery::applyFilters($query, $request, [
            'subject' => true,
            'created_by' => true,
            'assigned_by' => true,
            'journey_category_id' => true,
            'status' => true,
            'visibility' => true,
            'grading_mode' => true,
            'due_from' => fn ($q, $value) => $q->where('due_date', '>=', $value),
            'due_to' => fn ($q, $value) => $q->where('due_date', '<=', $value),
        ]);

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%");
            });
        }

        ApiQuery::applySort($query, $request, ['created_at', 'due_date', 'title'], '-created_at');

        $assignments = $query->paginate(ApiPagination::perPage($request, 20));
        if ($includeItems) {
            $resolver = new HomeworkItemResolver();
            $assignments->getCollection()->transform(function ($assignment) use ($resolver) {
                $assignment->setAttribute('hydrated_items', $resolver->resolve($assignment->items));
                return $assignment;
            });
        }

        $data = HomeworkAssignmentResource::collection($assignments->items())->resolve();

        return $this->paginated($assignments, $data);
    }

    public function show(Request $request, HomeworkAssignment $homework): JsonResponse
    {
        if ($response = $this->ensureScope($request, $homework)) {
            return $response;
        }

        $includeItems = $this->shouldInclude($request, 'items');
        if ($includeItems) {
            $homework->load('items');
            $resolver = new HomeworkItemResolver();
            $homework->setAttribute('hydrated_items', $resolver->resolve($homework->items));
        }
        if ($this->shouldInclude($request, 'targets')) {
            $homework->load('targets.child.user');
        }
        $data = (new HomeworkAssignmentResource($homework))->resolve();

        return $this->success($data);
    }

    public function createData(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id');
        $allowedChildIds = $this->allowedChildIdsForUser($user, $orgId);

        $childrenScope = function ($query) use ($allowedChildIds, $orgId) {
            if ($orgId) {
                $query->where('children.organization_id', $orgId);
            }
            if (is_array($allowedChildIds)) {
                $query->whereIn('children.id', $allowedChildIds);
            }
        };

        $parentsQuery = User::whereIn('role', ['parent', 'guest_parent'])
            ->select('id', 'name')
            ->with([
                'children' => function ($query) use ($childrenScope) {
                    $query->select('id', 'child_name', 'user_id', 'year_group', 'organization_id');
                    $childrenScope($query);
                },
            ]);

        $parentsQuery->whereHas('children', $childrenScope);

        $parents = $parentsQuery->orderBy('name')->get();

        return $this->success(['parents' => $parents]);
    }

    public function store(HomeworkAssignmentStoreRequest $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id');
        $payload = $request->validated();
        $targetChildIds = $payload['target_child_ids'] ?? [];
        if (is_array($targetChildIds)) {
            $targetChildIds = $this->sanitizeTargetChildIdsForUser($request->user(), $targetChildIds, $orgId);
            if (!empty($payload['target_child_ids']) && empty($targetChildIds)) {
                return $this->error('You can assign homework only to your assigned students.', [], 422);
            }
            $payload['target_child_ids'] = $targetChildIds;
        }
        $payload['created_by'] = $user->id;
        if (!isset($payload['assigned_by'])) {
            $payload['assigned_by'] = $user->id;
        }
        if (!isset($payload['assigned_by_role'])) {
            $payload['assigned_by_role'] = $user->role ?? null;
        }
        if ($orgId) {
            $payload['organization_id'] = $orgId;
        }

        $attachments = $this->storeAttachments($request, 'homework_attachments');
        if (!empty($attachments)) {
            $payload['attachments'] = $attachments;
        }

        $assignment = HomeworkAssignment::create($payload);

        if (!empty($payload['items']) && is_array($payload['items'])) {
            $items = array_map(function ($item, $index) {
                return [
                    'type' => $item['type'] ?? 'text',
                    'ref_id' => $item['ref_id'] ?? null,
                    'payload' => $item['payload'] ?? null,
                    'sort_order' => $item['sort_order'] ?? $index,
                ];
            }, $payload['items'], array_keys($payload['items']));
            $assignment->items()->createMany($items);
        }

        $targetChildIds = $payload['target_child_ids'] ?? [];
        if (!empty($targetChildIds) && is_array($targetChildIds)) {
            $targets = collect($targetChildIds)
                ->unique()
                ->map(fn ($childId) => [
                    'homework_id' => $assignment->id,
                    'child_id' => (int) $childId,
                    'assigned_by' => $request->user()?->id,
                    'assigned_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
                ->values()
                ->all();

            if (!empty($targets)) {
                HomeworkTarget::insert($targets);
            }

            $children = Child::with('user')
                ->whereIn('id', $targetChildIds)
                ->get();
            $organization = MailContext::resolveOrganization($assignment->organization_id, $request->user(), $assignment, $request);
            $title = "Homework assigned: {$assignment->title}";

            foreach ($children as $child) {
                $parent = $child->user;
                if (! $parent) {
                    continue;
                }
                $message = "For \"{$child->child_name}\": New homework assigned.";
                AppNotification::create([
                    'user_id' => $parent->id,
                    'title' => $title,
                    'message' => $message,
                    'type' => 'task',
                    'status' => 'unread',
                    'channel' => 'in-app',
                ]);

                if ($parent->email) {
                    $mail = new HomeworkNotificationMail(
                        $title,
                        $message,
                        "/portal/homework/{$assignment->id}",
                        $organization,
                        'View Homework',
                        $child->child_name
                    );
                    MailContext::sendMailable($parent->email, $mail);
                }
            }
        }

        $assignment->load('items', 'targets.child.user');
        $data = (new HomeworkAssignmentResource($assignment))->resolve();

        return $this->success(['assignment' => $data], [], 201);
    }

    public function update(HomeworkAssignmentUpdateRequest $request, HomeworkAssignment $homework): JsonResponse
    {
        if ($response = $this->ensureScope($request, $homework)) {
            return $response;
        }

        $payload = $request->validated();
        if (array_key_exists('target_child_ids', $payload) && is_array($payload['target_child_ids'])) {
            $payload['target_child_ids'] = $this->sanitizeTargetChildIdsForUser(
                $request->user(),
                $payload['target_child_ids'],
                $request->attributes->get('organization_id')
            );
            if (!empty($request->input('target_child_ids')) && empty($payload['target_child_ids'])) {
                return $this->error('You can assign homework only to your assigned students.', [], 422);
            }
        }
        if (!isset($payload['assigned_by'])) {
            $payload['assigned_by'] = $request->user()?->id;
        }
        if (!isset($payload['assigned_by_role'])) {
            $payload['assigned_by_role'] = $request->user()?->role;
        }
        $attachments = $this->storeAttachments($request, 'homework_attachments');
        if (!empty($attachments)) {
            $payload['attachments'] = array_values(array_merge($homework->attachments ?? [], $attachments));
        }

        $homework->update($payload);

        if (array_key_exists('items', $payload) && is_array($payload['items'])) {
            $homework->items()->delete();
            $items = array_map(function ($item, $index) {
                return [
                    'type' => $item['type'] ?? 'text',
                    'ref_id' => $item['ref_id'] ?? null,
                    'payload' => $item['payload'] ?? null,
                    'sort_order' => $item['sort_order'] ?? $index,
                ];
            }, $payload['items'], array_keys($payload['items']));
            if (!empty($items)) {
                $homework->items()->createMany($items);
            }
        }

        if (array_key_exists('target_child_ids', $payload)) {
            $targetChildIds = $payload['target_child_ids'] ?? [];
            $homework->targets()->delete();
            $targets = collect($targetChildIds ?? [])
                ->unique()
                ->map(fn ($childId) => [
                    'homework_id' => $homework->id,
                    'child_id' => (int) $childId,
                    'assigned_by' => $request->user()?->id,
                    'assigned_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
                ->values()
                ->all();

            if (!empty($targets)) {
                HomeworkTarget::insert($targets);
            }
        }

        $homework->load('items', 'targets.child.user');
        $data = (new HomeworkAssignmentResource($homework))->resolve();

        return $this->success(['assignment' => $data]);
    }

    public function destroy(Request $request, HomeworkAssignment $homework): JsonResponse
    {
        if ($response = $this->ensureScope($request, $homework)) {
            return $response;
        }

        $homework->delete();

        return $this->success(['message' => 'Homework assignment deleted successfully.']);
    }

    private function ensureScope(Request $request, HomeworkAssignment $homework): ?JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');
        if ($orgId && (int) $homework->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        return null;
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

    private function storeAttachments(Request $request, string $directory): array
    {
        $stored = [];

        if (!$request->hasFile('attachments')) {
            return $stored;
        }

        $files = $request->file('attachments');
        if ($files instanceof UploadedFile) {
            $files = [$files];
        }

        foreach ($files as $file) {
            $stored[] = $file->store($directory, 'public');
        }

        return $stored;
    }

    private function allowedChildIdsForUser(?User $user, ?int $orgId): ?array
    {
        if (!$user || ($user->role ?? null) !== 'teacher') {
            return null;
        }

        return $user->assignedStudents()
            ->when($orgId, fn ($q) => $q->where('children.organization_id', $orgId))
            ->pluck('children.id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function sanitizeTargetChildIdsForUser(?User $user, array $targetChildIds, ?int $orgId): array
    {
        $allowedChildIds = $this->allowedChildIdsForUser($user, $orgId);
        $normalized = collect($targetChildIds)->map(fn ($id) => (int) $id)->unique()->values()->all();

        if (!is_array($allowedChildIds)) {
            return $normalized;
        }

        return array_values(array_intersect($normalized, $allowedChildIds));
    }
}
