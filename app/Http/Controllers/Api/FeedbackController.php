<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\ParentFeedbacks\ParentFeedbackStoreRequest;
use App\Http\Resources\ParentFeedbackResource;
use App\Models\AdminTask;
use App\Models\ParentFeedbacks;
use App\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeedbackController extends ApiController
{
    private function portalBaseUrl(?\App\Models\Organization $organization, ?Request $request = null): ?string
    {
        $value = $organization?->portal_domain;
        if (! $value || ! is_string($value)) {
            $value = (string) config('app.frontend_url');
        }

        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        $scheme = null;
        $host = null;
        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            $parsed = parse_url($raw);
            $scheme = $parsed['scheme'] ?? null;
            $host = $parsed['host'] ?? null;
        } else {
            $host = preg_replace('#/.*$#', '', $raw);
        }

        if (! $host) {
            return null;
        }

        if (! $scheme) {
            $isLocal = str_starts_with($host, 'localhost') || str_starts_with($host, '127.0.0.1');
            $scheme = $isLocal ? 'http' : 'https';
        }

        return $scheme . '://' . $host;
    }

    private function portalUrl(string $path, ?\App\Models\Organization $organization, ?Request $request = null): ?string
    {
        $base = $this->portalBaseUrl($organization, $request);
        if (! $base) {
            return null;
        }
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $query = ParentFeedbacks::query()
            ->where('user_id', $user->id)
            ->orderByDesc('submitted_at');

        $feedbacks = $query->paginate(ApiPagination::perPage($request, 20));
        $data = ParentFeedbackResource::collection($feedbacks->items())->resolve();

        return $this->paginated($feedbacks, $data);
    }

    public function store(ParentFeedbackStoreRequest $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $validated = $request->validated();

        if ($validated['feature'] === 'child_profile') {
            $ownsChild = $user->children()->where('id', $validated['child_id'])->exists();
            if (!$ownsChild) {
                return $this->error('Invalid child selection.', [], 422);
            }
        }

        $attachedPaths = [];
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $attachedPaths[] = $file->store('parent_feedback_attachments', 'public');
            }
        }

        $details = [
            'feature' => $validated['feature'],
            'child_id' => $validated['feature'] === 'child_profile'
                ? $validated['child_id']
                : null,
        ];

        $feedback = ParentFeedbacks::create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'name' => $validated['name'],
            'user_email' => $validated['user_email'],
            'category' => $validated['category'],
            'message' => $validated['message'],
            'details' => $details,
            'attachments' => $attachedPaths,
            'status' => 'New',
            'submitted_at' => now(),
            'user_ip' => $request->ip(),
        ]);

        $org = $user->currentOrganization()->first();
        $relatedEntity = $this->portalUrl('/admin/portal-feedbacks/' . $feedback->id, $org, $request);

        AdminTask::create([
            'task_type' => 'Parent Concern',
            'assigned_to' => null,
            'status' => 'Pending',
            'related_entity' => $relatedEntity,
            'priority' => 'Medium',
            'organization_id' => $user->current_organization_id,
        ]);

        $data = (new ParentFeedbackResource($feedback))->resolve();

        return $this->success(['feedback' => $data], [], 201);
    }
}
