<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Applications\ApplicationReviewRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Services\ApplicationApprovalService;
use App\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApplicationController extends ApiController
{
    protected ApplicationApprovalService $approvalService;

    public function __construct(ApplicationApprovalService $approvalService)
    {
        $this->approvalService = $approvalService;
    }

    public function index(Request $request): JsonResponse
    {
        $query = Application::query();

        $this->applyOrgScope($request, $query);

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('applicant_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('application_type', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->query('status') !== 'all') {
            $query->where('application_status', $request->query('status'));
        }

        if ($request->filled('type') && $request->query('type') !== 'all') {
            $query->where('application_type', $request->query('type'));
        }

        switch ($request->query('sort', 'newest')) {
            case 'oldest':
                $query->orderBy('created_at', 'asc');
                break;
            case 'name':
                $query->orderBy('applicant_name', 'asc');
                break;
            case 'status':
                $query->orderBy('application_status', 'asc');
                break;
            case 'newest':
            default:
                $query->orderBy('created_at', 'desc');
                break;
        }

        $applications = $query->paginate(ApiPagination::perPage($request, 10));
        $data = ApplicationResource::collection($applications->items())->resolve();

        return $this->paginated($applications, $data);
    }

    public function show(Request $request, Application $application): JsonResponse
    {
        if ($response = $this->ensureScope($request, $application)) {
            return $response;
        }

        $application->load('user');
        $data = (new ApplicationResource($application))->resolve();

        return $this->success($data);
    }

    public function review(ApplicationReviewRequest $request, Application $application): JsonResponse
    {
        if ($response = $this->ensureScope($request, $application)) {
            return $response;
        }

        $validated = $request->validated();
        $reviewerId = $request->user()?->id;

        if ($validated['status'] === 'Approved') {
            $this->approvalService->approve($application, $reviewerId);
        } else {
            $this->approvalService->reject(
                $application,
                $validated['admin_feedback'] ?? null,
                $reviewerId
            );
        }

        $application->refresh()->load('user');
        $data = (new ApplicationResource($application))->resolve();

        return $this->success([
            'application' => $data,
            'message' => 'Application reviewed successfully.',
        ]);
    }

    /**
     * Get applications grouped by pipeline status for kanban view.
     */
    public function kanban(Request $request): JsonResponse
    {
        $query = Application::query()->with('affiliate');
        $this->applyOrgScope($request, $query);

        // Apply filters
        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('applicant_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('type') && $request->query('type') !== 'all') {
            $query->where('application_type', $request->query('type'));
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->query('date_to') . ' 23:59:59');
        }

        if ($request->filled('affiliate_id')) {
            $query->where('affiliate_id', $request->query('affiliate_id'));
        }

        // Exclude old approved/rejected unless explicitly requested
        if (!$request->boolean('show_all')) {
            $query->where(function ($q) {
                $q->whereNotIn('pipeline_status', [Application::PIPELINE_APPROVED, Application::PIPELINE_REJECTED])
                  ->orWhere('updated_at', '>=', now()->subDays(30));
            });
        }

        $applications = $query->orderBy('created_at', 'desc')->get();

        $columns = [];
        foreach (Application::PIPELINE_STATUSES as $status) {
            $group = $applications->where('pipeline_status', $status);
            $columns[$status] = [
                'count' => $group->count(),
                'items' => ApplicationResource::collection($group)->resolve(),
            ];
        }

        return $this->success(['columns' => $columns]);
    }

    /**
     * Update an application's pipeline status (for kanban drag-and-drop).
     */
    public function updatePipelineStatus(Request $request, Application $application): JsonResponse
    {
        if ($response = $this->ensureScope($request, $application)) {
            return $response;
        }

        $validated = $request->validate([
            'pipeline_status' => 'required|in:' . implode(',', Application::PIPELINE_STATUSES),
        ]);

        $application->update([
            'pipeline_status'            => $validated['pipeline_status'],
            'pipeline_status_changed_at' => now(),
        ]);

        // If moved to approved/rejected, trigger the approval service
        if ($validated['pipeline_status'] === Application::PIPELINE_APPROVED && $application->application_status !== 'Approved') {
            $this->approvalService->approve($application, $request->user()?->id);
        } elseif ($validated['pipeline_status'] === Application::PIPELINE_REJECTED && $application->application_status !== 'Rejected') {
            $this->approvalService->reject($application, null, $request->user()?->id);
        }

        $application->refresh();
        $data = (new ApplicationResource($application))->resolve();

        return $this->success($data);
    }

    public function destroy(Request $request, Application $application): JsonResponse
    {
        if ($response = $this->ensureScope($request, $application)) {
            return $response;
        }

        $application->delete();

        return $this->success(['message' => 'Application deleted successfully.']);
    }

    private function ensureScope(Request $request, Application $application): ?JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id');
        if ($user->isSuperAdmin() && $request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
        }

        if ($orgId && (int) $application->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        return null;
    }

    private function applyOrgScope(Request $request, $query): void
    {
        $user = $request->user();
        if (!$user) {
            return;
        }

        $orgId = $request->attributes->get('organization_id');
        if ($user->isSuperAdmin() && $request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
        }

        if ($orgId) {
            $query->where('organization_id', $orgId);
        }
    }
}
