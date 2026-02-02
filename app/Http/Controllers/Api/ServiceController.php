<?php

namespace App\Http\Controllers\Api;

use App\Models\Service;
use App\Services\FlexibleServiceAccessService;
use App\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Requests\Api\Services\ServiceSelectionRequest;

class ServiceController extends ApiController
{
    private function resolveOrgId(Request $request): ?int
    {
        $orgId = $request->header('X-Organization-Id') ?? $request->query('organization_id');
        return $orgId ? (int) $orgId : null;
    }

    public function index(Request $request): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);

        $query = Service::query()->visibleToOrg($orgId);
        $includes = array_filter(array_map('trim', explode(',', (string) $request->query('include', ''))));
        $with = [];
        if (in_array('lessons', $includes, true)) {
            $with[] = 'lessons:id,title,description,lesson_mode,start_time,end_time';
        }
        if (in_array('assessments', $includes, true)) {
            $with[] = 'assessments:id,title,description,deadline';
        }
        if ($request->boolean('include_children')) {
            $with[] = 'children:id,child_name,year_group';
        }
        if (!empty($with)) {
            $query->with($with);
        }

        if ($request->filled('type')) {
            $query->where('_type', $request->type);
        }

        if ($request->filled('service_level')) {
            $query->where('service_level', $request->service_level);
        }

        if ($request->has('availability')) {
            $query->where('availability', filter_var($request->availability, FILTER_VALIDATE_BOOLEAN));
        } else {
            $query->where('availability', true);
        }

        if ($request->filled('category')) {
            $query->whereJsonContains('categories', $request->category);
        }

        $services = $query->orderByDesc('created_at')
            ->paginate(ApiPagination::perPage($request));

        $data = $services->getCollection()->map(fn ($service) => $this->mapService($service))->all();

        return $this->paginated($services, $data);
    }

    public function show(Request $request, Service $service): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && !$service->is_global && (int) $service->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $service->load([
            'lessons:id,title,lesson_mode,start_time,end_time',
            'assessments:id,title,description,deadline',
            'children:id,child_name,year_group',
            'course:id,title,description,cover_image,thumbnail,status',
        ]);

        $response = $this->mapService($service);

        if ($service->isFlexibleService()) {
            $response['flexible'] = [
                'selection_config' => $service->selection_config,
                'required_selections' => $service->getRequiredSelections(),
            ];
        }

        return $this->success($response);
    }

    public function availableContent(Request $request, Service $service): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && !$service->is_global && (int) $service->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        if (!$service->isFlexibleService()) {
            return $this->error('This service is not a flexible service.', [], 400);
        }

        $liveSessions = $service->getAvailableLiveSessions()->map(function ($session) {
            return [
                'id' => $session->id,
                'title' => $session->title,
                'lesson_mode' => $session->lesson_mode,
                'start_time' => $session->start_time,
                'end_time' => $session->end_time,
                'enrollment_status' => $session->enrollment_status,
                'current_enrollments' => $session->current_enrollments,
                'max_enrollments' => $session->max_enrollments,
                'is_available' => $session->is_available,
            ];
        });

        $assessments = $service->getAvailableAssessments()->map(function ($assessment) {
            return [
                'id' => $assessment->id,
                'title' => $assessment->title,
                'description' => $assessment->description,
                'deadline' => $assessment->deadline,
                'enrollment_status' => $assessment->enrollment_status,
                'current_enrollments' => $assessment->current_enrollments,
                'max_enrollments' => $assessment->max_enrollments,
                'is_available' => $assessment->is_available,
            ];
        });

        return $this->success([
            'service' => [
                'id' => $service->id,
                'service_name' => $service->service_name,
                'selection_config' => $service->selection_config,
                'required_selections' => $service->getRequiredSelections(),
            ],
            'available_content' => [
                'live_sessions' => $liveSessions,
                'assessments' => $assessments,
            ],
        ]);
    }

    public function selection(ServiceSelectionRequest $request, Service $service, FlexibleServiceAccessService $accessService): JsonResponse
    {
        if (! $service->isFlexibleService()) {
            return $this->error('This service is not a flexible service.', [], 400);
        }

        $data = $request->validated();
        $result = $accessService->validateSelections(
            $service,
            $data['selected_lessons'] ?? [],
            $data['selected_assessments'] ?? []
        );

        if (! $result['valid']) {
            return $this->error($result['message'] ?? 'Invalid selections.', [], 422);
        }

        return $this->success([
            'valid' => true,
            'message' => $result['message'] ?? 'Selections valid.',
        ]);
    }

    private function mapService(Service $service): array
    {
        return [
            'id' => $service->id,
            'organization_id' => $service->organization_id,
            'is_global' => (bool) $service->is_global,
            'service_name' => $service->service_name,
            'type' => $service->_type,
            'service_level' => $service->service_level,
            'availability' => (bool) $service->availability,
            'price' => $service->price,
            'course_id' => $service->course_id,
            'selection_config' => $service->selection_config,
            'start_datetime' => $service->start_datetime?->toISOString(),
            'end_datetime' => $service->end_datetime?->toISOString(),
            'display_until' => $service->display_until?->toDateString(),
            'quantity' => $service->quantity,
            'quantity_remaining' => $service->quantity_remaining,
            'quantity_allowed_per_child' => $service->quantity_allowed_per_child,
            'restriction_type' => $service->restriction_type,
            'year_groups_allowed' => $service->year_groups_allowed,
            'categories' => $service->categories,
            'auto_attendance' => (bool) $service->auto_attendance,
            'description' => $service->description,
            'schedule' => $service->schedule,
            'media' => $service->media,
            'child_ids' => $service->relationLoaded('children')
                ? $service->children->pluck('id')->map(fn ($id) => (string) $id)->values()
                : null,
            'lessons' => $service->relationLoaded('lessons')
                ? $service->lessons->map(fn ($lesson) => [
                    'id' => $lesson->id,
                    'title' => $lesson->title,
                    'description' => $lesson->description,
                    'lesson_mode' => $lesson->lesson_mode,
                    'start_time' => $lesson->start_time,
                    'end_time' => $lesson->end_time,
                ])
                : null,
            'assessments' => $service->relationLoaded('assessments')
                ? $service->assessments->map(fn ($assessment) => [
                    'id' => $assessment->id,
                    'title' => $assessment->title,
                    'description' => $assessment->description,
                    'deadline' => $assessment->deadline,
                ])
                : null,
            'course' => $service->relationLoaded('course') && $service->course
                ? [
                    'id' => $service->course->id,
                    'title' => $service->course->title,
                    'description' => $service->course->description,
                    'cover_image' => $service->course->cover_image,
                    'thumbnail' => $service->course->thumbnail,
                    'status' => $service->course->status,
                ]
                : null,
        ];
    }
}
