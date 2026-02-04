<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Feedback;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeedbackController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $query = Feedback::query();

        if ($user->isSuperAdmin() && $request->filled('organization_id')) {
            $query->forOrganization($request->integer('organization_id'));
        } elseif (! $user->isSuperAdmin() && $user->current_organization_id) {
            $query->forOrganization($user->current_organization_id);
        }

        ApiQuery::applyFilters($query, $request, [
            'status' => true,
            'category' => true,
        ]);

        ApiQuery::applySort($query, $request, ['submission_date', 'created_at', 'status'], '-submission_date');

        $feedbacks = $query->paginate(ApiPagination::perPage($request, 20));
        $data = $feedbacks->getCollection()
            ->map(fn (Feedback $feedback) => $this->mapFeedback($feedback))
            ->values()
            ->all();

        $meta = [];
        if ($user->isSuperAdmin()) {
            $meta['organizations'] = \App\Models\Organization::select('id', 'name')
                ->orderBy('name')
                ->get();
        }

        return $this->paginated($feedbacks, $data, $meta);
    }

    public function show(Request $request, Feedback $feedback): JsonResponse
    {
        if ($response = $this->ensureScoped($request, $feedback)) {
            return $response;
        }

        return $this->success($this->mapFeedback($feedback, true));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $validated = $request->validate([
            'user_id' => 'nullable|string|max:255',
            'name' => 'required|string',
            'user_email' => 'required|email|max:255',
            'category' => 'required|in:Inquiry,Complaint,Suggestion,Support',
            'message' => 'required|string',
            'organization_id' => 'nullable|integer',
            'attachments.*' => 'nullable|file',
        ]);

        $attachmentPaths = [];
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $attachmentPaths[] = $file->store('feedback', 'public');
            }
        }

        $orgId = $user->isSuperAdmin() && isset($validated['organization_id'])
            ? $validated['organization_id']
            : $user->current_organization_id;

        $feedback = Feedback::create(array_merge($validated, [
            'attachments' => count($attachmentPaths) ? $attachmentPaths : null,
            'status' => 'Pending',
            'submission_date' => now(),
            'user_ip' => $request->ip(),
            'organization_id' => $orgId,
        ]));

        return $this->success([
            'feedback' => $this->mapFeedback($feedback, true),
            'message' => 'Feedback submitted successfully.',
        ], [], 201);
    }

    public function update(Request $request, Feedback $feedback): JsonResponse
    {
        if ($response = $this->ensureScoped($request, $feedback)) {
            return $response;
        }

        $validated = $request->validate([
            'status' => 'required|in:Pending,Reviewed,Resolved',
            'admin_response' => 'nullable|string',
        ]);

        $feedback->update($validated);

        return $this->success([
            'feedback' => $this->mapFeedback($feedback, true),
            'message' => 'Feedback updated successfully.',
        ]);
    }

    public function destroy(Request $request, Feedback $feedback): JsonResponse
    {
        if ($response = $this->ensureScoped($request, $feedback)) {
            return $response;
        }

        $feedback->delete();

        return $this->success(['message' => 'Feedback deleted.']);
    }

    private function ensureScoped(Request $request, Feedback $feedback): ?JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        if ($user->isSuperAdmin() && $request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
            if ($orgId && (int) $feedback->organization_id !== (int) $orgId) {
                return $this->error('Not found.', [], 404);
            }
            return null;
        }

        if (! $user->isSuperAdmin() && $user->current_organization_id) {
            if ((int) $feedback->organization_id !== (int) $user->current_organization_id) {
                return $this->error('Not found.', [], 404);
            }
        }

        return null;
    }

    private function mapFeedback(Feedback $feedback, bool $includeMessage = false): array
    {
        return [
            'id' => $feedback->id,
            'user_id' => $feedback->user_id,
            'name' => $feedback->name,
            'user_email' => $feedback->user_email,
            'category' => $feedback->category,
            'status' => $feedback->status,
            'submission_date' => $feedback->submission_date?->toISOString(),
            'admin_response' => $feedback->admin_response,
            'attachments' => $feedback->attachments,
            'user_ip' => $feedback->user_ip,
            'organization_id' => $feedback->organization_id,
            'message' => $includeMessage ? $feedback->message : null,
        ];
    }
}
