<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\SyncLessonService;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\StoreServiceRequest;
use App\Models\Assessment;
use App\Models\Child;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Service;
use App\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ServiceController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $query = Service::with('organization:id,name')->latest();

        $orgId = $request->attributes->get('organization_id');
        if ($user->isSuperAdmin() && $request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
        } elseif (! $user->isSuperAdmin()) {
            $orgId = $user->current_organization_id;
        }

        if ($orgId) {
            $query->visibleToOrg($orgId);
        }

        $services = $query->paginate(ApiPagination::perPage($request, 20));
        $data = $services->getCollection()
            ->map(fn (Service $service) => $this->mapService($service))
            ->values()
            ->all();

        $meta = [];
        if ($user->isSuperAdmin()) {
            $meta['organizations'] = \App\Models\Organization::select('id', 'name')
                ->orderBy('name')
                ->get();
        }

        return $this->paginated($services, $data, $meta);
    }

    public function createData(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $isSuperAdmin = $user->isSuperAdmin();
        $orgId = $user->current_organization_id;

        $preselected = [];
        if ($request->query('lesson_id')) {
            $preselected = [(int) $request->query('lesson_id')];
        }

        return $this->success([
            'lessons' => Lesson::select('id', 'title', 'lesson_mode', 'start_time', 'end_time', 'organization_id', 'is_global')
                ->when(! $isSuperAdmin && $orgId, fn ($q) => $q->visibleToOrg($orgId))
                ->orderBy('title')
                ->get(),
            'assessments' => Assessment::select('id', 'title', 'organization_id', 'is_global')
                ->when(! $isSuperAdmin && $orgId, fn ($q) => $q->visibleToOrg($orgId))
                ->orderBy('title')
                ->get(),
            'courses' => Course::select('id', 'title', 'description', 'organization_id', 'is_global')
                ->when(! $isSuperAdmin && $orgId, fn ($q) => $q->visibleToOrg($orgId))
                ->orderBy('title')
                ->get(),
            'childrenByYear' => Child::select('id', 'child_name', 'year_group', 'organization_id')
                ->when(! $isSuperAdmin && $orgId, fn ($q) => $q->where('organization_id', $orgId))
                ->orderBy('year_group')
                ->get()
                ->groupBy('year_group'),
            'preselected_lesson_ids' => $preselected,
            'organizations' => $isSuperAdmin
                ? \App\Models\Organization::select('id', 'name')->orderBy('name')->get()
                : null,
        ]);
    }

    public function store(StoreServiceRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        try {
            $isSuperAdmin = $user->isSuperAdmin();
            $isGlobal = $isSuperAdmin ? $request->boolean('is_global') : false;
            $organizationId = $isSuperAdmin
                ? ($isGlobal ? null : $request->input('organization_id', $user->current_organization_id))
                : $user->current_organization_id;

            $service = null;
            DB::transaction(function () use ($request, $organizationId, $isGlobal, &$service) {
                $payload = $this->payload($request);

                $service = Service::create(array_merge(
                    $payload,
                    [
                        'organization_id' => $organizationId,
                        'is_global' => $isGlobal,
                        'quantity_remaining' => $request->quantity_remaining
                            ?? $request->quantity,
                    ]
                ));

                $this->syncRelations($service, $request);

                if ($request->hasFile('media')) {
                    $this->handleMediaUploads($service, $request);
                }

                if ($isGlobal) {
                    $this->propagateGlobalContent($service, $request);
                }
            });

            return $this->success([
                'service' => $service ? $this->mapService($service) : null,
                'message' => 'Service created successfully.',
            ], [], 201);
        } catch (\Throwable $e) {
            Log::error('Service API create failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function show(Request $request, Service $service): JsonResponse
    {
        if ($response = $this->ensureScoped($request, $service)) {
            return $response;
        }

        $service->load([
            'lessons:id,title,lesson_mode,start_time,end_time',
            'assessments:id,title,description,deadline',
            'children:id,child_name,year_group',
            'course:id,title,description,cover_image,thumbnail,status,organization_id,is_global',
            'organization:id,name',
        ]);

        $timeline = collect();
        foreach ($service->lessons as $lesson) {
            $timeline->push([
                'type' => 'lesson',
                'id' => $lesson->id,
                'title' => $lesson->title,
                'at' => $lesson->start_time,
                'extra' => [
                    'end' => $lesson->end_time,
                    'mode' => $lesson->lesson_mode,
                ],
            ]);
        }

        foreach ($service->assessments as $assessment) {
            $timeline->push([
                'type' => 'assessment',
                'id' => $assessment->id,
                'title' => $assessment->title,
                'at' => $assessment->deadline,
                'extra' => [
                    'desc' => $assessment->description,
                ],
            ]);
        }

        $flexibleData = null;
        if ($service->isFlexibleService()) {
            $flexibleData = [
                'selection_config' => $service->selection_config,
                'required_selections' => $service->getRequiredSelections(),
                'available_live_sessions' => $service->getAvailableLiveSessions(),
                'available_assessments' => $service->getAvailableAssessments(),
            ];
        }

        return $this->success([
            'service' => $this->mapService($service, true),
            'timeline' => $timeline->sortBy('at')->values()->all(),
            'flexibleData' => $flexibleData,
        ]);
    }

    public function editData(Request $request, Service $service): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        if ($response = $this->ensureScoped($request, $service)) {
            return $response;
        }

        $isSuperAdmin = $user->isSuperAdmin();
        $orgId = $user->current_organization_id;

        $service->load([
            'lessons:id,organization_id,is_global',
            'assessments:id,organization_id,is_global',
            'children:id',
            'course:id,title,description,organization_id,is_global',
            'organization:id,name',
        ]);

        return $this->success([
            'service' => $this->mapService($service, true),
            'lessons' => Lesson::select('id', 'title', 'lesson_mode', 'start_time', 'end_time', 'organization_id', 'is_global')
                ->when(! $isSuperAdmin && $orgId, fn ($q) => $q->visibleToOrg($orgId))
                ->orderBy('title')
                ->get(),
            'assessments' => Assessment::select('id', 'title', 'organization_id', 'is_global')
                ->when(! $isSuperAdmin && $orgId, fn ($q) => $q->visibleToOrg($orgId))
                ->orderBy('title')
                ->get(),
            'courses' => Course::select('id', 'title', 'description', 'organization_id', 'is_global')
                ->when(! $isSuperAdmin && $orgId, fn ($q) => $q->visibleToOrg($orgId))
                ->orderBy('title')
                ->get(),
            'childrenByYear' => Child::select('id', 'child_name', 'year_group', 'organization_id')
                ->when(! $isSuperAdmin && $orgId, fn ($q) => $q->where('organization_id', $orgId))
                ->orderBy('year_group')
                ->get()
                ->groupBy('year_group'),
            'organizations' => $isSuperAdmin
                ? \App\Models\Organization::select('id', 'name')->orderBy('name')->get()
                : null,
            'mismatch_warnings' => $this->getOrgMismatchWarnings($service),
        ]);
    }

    public function update(StoreServiceRequest $request, Service $service): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        if ($response = $this->ensureScoped($request, $service)) {
            return $response;
        }

        $isSuperAdmin = $user->isSuperAdmin();
        $isGlobal = $isSuperAdmin ? $request->boolean('is_global') : false;
        $organizationId = $isSuperAdmin
            ? ($isGlobal ? null : $request->input('organization_id', $user->current_organization_id))
            : $user->current_organization_id;

        DB::transaction(function () use ($request, $service, $organizationId, $isGlobal) {
            $service->update($this->payload($request) + [
                'organization_id' => $organizationId,
                'is_global' => $isGlobal,
            ]);

            $this->syncRelations($service, $request);
            $this->handleMediaUploads($service, $request, true);

            if ($isGlobal) {
                $this->propagateGlobalContent($service, $request);
            }
        });

        return $this->success([
            'service' => $this->mapService($service, true),
            'message' => 'Service updated successfully.',
        ]);
    }

    public function destroy(Request $request, Service $service): JsonResponse
    {
        if ($response = $this->ensureScoped($request, $service)) {
            return $response;
        }

        $service->delete();

        return $this->success(['message' => 'Service deleted.']);
    }

    private function ensureScoped(Request $request, Service $service): ?JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id');
        if ($user->isSuperAdmin() && $request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
        } elseif (! $user->isSuperAdmin()) {
            $orgId = $user->current_organization_id;
        }

        if ($orgId && ! $service->is_global && (int) $service->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        return null;
    }

    private function mapService(Service $service, bool $includeRelations = false): array
    {
        $data = [
            'id' => $service->id,
            'organization_id' => $service->organization_id,
            'organization' => $service->relationLoaded('organization') && $service->organization
                ? ['id' => $service->organization->id, 'name' => $service->organization->name]
                : null,
            'is_global' => (bool) $service->is_global,
            'service_name' => $service->service_name,
            '_type' => $service->_type,
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
            'created_at' => $service->created_at?->toISOString(),
            'updated_at' => $service->updated_at?->toISOString(),
        ];

        if ($includeRelations) {
            $data['lessons'] = $service->relationLoaded('lessons')
                ? $service->lessons->map(fn ($lesson) => [
                    'id' => $lesson->id,
                    'title' => $lesson->title,
                    'lesson_mode' => $lesson->lesson_mode,
                    'start_time' => $lesson->start_time,
                    'end_time' => $lesson->end_time,
                ])->values()
                : null;
            $data['assessments'] = $service->relationLoaded('assessments')
                ? $service->assessments->map(fn ($assessment) => [
                    'id' => $assessment->id,
                    'title' => $assessment->title,
                    'description' => $assessment->description,
                    'deadline' => $assessment->deadline,
                ])->values()
                : null;
            $data['children'] = $service->relationLoaded('children')
                ? $service->children->map(fn ($child) => [
                    'id' => $child->id,
                    'child_name' => $child->child_name,
                    'year_group' => $child->year_group,
                ])->values()
                : null;
            $data['course'] = $service->relationLoaded('course') && $service->course
                ? [
                    'id' => $service->course->id,
                    'title' => $service->course->title,
                    'description' => $service->course->description,
                    'cover_image' => $service->course->cover_image,
                    'thumbnail' => $service->course->thumbnail,
                    'status' => $service->course->status,
                    'organization_id' => $service->course->organization_id,
                    'is_global' => $service->course->is_global,
                ]
                : null;
        }

        return $data;
    }

    private function payload(StoreServiceRequest $request): array
    {
        return $request->except([
            'lesson_ids',
            'assessment_ids',
            'child_ids',
            'media',
        ]);
    }

    private function syncRelations(Service $service, StoreServiceRequest $request): void
    {
        $lessonIds = $request->input('lesson_ids', []);
        $assessmentIds = $request->input('assessment_ids', []);
        $childIds = $request->input('child_ids', []);

        if ($service->_type === 'flexible' && $request->has('flexible_content')) {
            $flexibleContent = $request->input('flexible_content', []);

            $lessonPivotData = [];
            foreach ($flexibleContent as $content) {
                if ($content['type'] === 'lesson') {
                    $lessonPivotData[$content['id']] = [
                        'enrollment_limit' => $content['max_enrollments'] ?? null,
                        'current_enrollments' => 0,
                    ];
                }
            }

            $assessmentPivotData = [];
            foreach ($flexibleContent as $content) {
                if ($content['type'] === 'assessment') {
                    $assessmentPivotData[$content['id']] = [
                        'enrollment_limit' => $content['max_enrollments'] ?? null,
                        'current_enrollments' => 0,
                    ];
                }
            }

            $service->lessons()->sync($lessonPivotData);
            $service->assessments()->sync($assessmentPivotData);
        } else {
            $service->lessons()->sync($lessonIds);
            $service->assessments()->sync($assessmentIds);
        }

        if ($service->restriction_type === 'Specific') {
            $service->children()->sync($childIds);
        } else {
            $service->children()->detach();
        }

        app(SyncLessonService::class)(
            $service->id,
            $service->_type === 'flexible'
                ? array_keys($lessonPivotData ?? [])
                : $lessonIds
        );
    }

    private function propagateGlobalContent(Service $service, StoreServiceRequest $request): void
    {
        $lessonIds = $request->input('lesson_ids', []);
        $assessmentIds = $request->input('assessment_ids', []);
        $courseId = $request->input('course_id');

        $flexibleContent = $request->input('flexible_content', []);
        if (! empty($flexibleContent)) {
            foreach ($flexibleContent as $content) {
                if (($content['type'] ?? null) === 'lesson') {
                    $lessonIds[] = $content['id'];
                }
                if (($content['type'] ?? null) === 'assessment') {
                    $assessmentIds[] = $content['id'];
                }
            }
        }

        $lessonIds = array_values(array_unique(array_filter($lessonIds)));
        $assessmentIds = array_values(array_unique(array_filter($assessmentIds)));

        if (! empty($lessonIds)) {
            Lesson::whereIn('id', $lessonIds)->update([
                'is_global' => true,
                'organization_id' => null,
            ]);
        }

        if (! empty($assessmentIds)) {
            Assessment::whereIn('id', $assessmentIds)->update([
                'is_global' => true,
                'organization_id' => null,
            ]);
        }

        if ($courseId) {
            $course = Course::find($courseId);
            if ($course) {
                $course->update([
                    'is_global' => true,
                    'organization_id' => null,
                ]);

                $courseAssessmentIds = $course->getAllAssessmentIds();
                if (! empty($courseAssessmentIds)) {
                    Assessment::whereIn('id', $courseAssessmentIds)->update([
                        'is_global' => true,
                        'organization_id' => null,
                    ]);
                }
            }
        }
    }

    private function getOrgMismatchWarnings(Service $service): array
    {
        if ($service->is_global) {
            return [];
        }

        $orgId = $service->organization_id;
        if (! $orgId) {
            return [];
        }

        $warnings = [];

        $lessonMismatches = $service->lessons()
            ->select('live_sessions.id', 'live_sessions.title', 'live_sessions.organization_id', 'live_sessions.is_global')
            ->where(function ($q) use ($orgId) {
                $q->where('organization_id', '!=', $orgId)
                    ->orWhereNull('organization_id');
            })
            ->get();

        if ($lessonMismatches->isNotEmpty()) {
            $warnings[] = [
                'type' => 'lesson',
                'count' => $lessonMismatches->count(),
                'items' => $lessonMismatches->map(fn ($item) => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'organization_id' => $item->organization_id,
                    'is_global' => $item->is_global,
                ])->toArray(),
            ];
        }

        $assessmentMismatches = $service->assessments()
            ->select('assessments.id', 'assessments.title', 'assessments.organization_id', 'assessments.is_global')
            ->where(function ($q) use ($orgId) {
                $q->where('organization_id', '!=', $orgId)
                    ->orWhereNull('organization_id');
            })
            ->get();

        if ($assessmentMismatches->isNotEmpty()) {
            $warnings[] = [
                'type' => 'assessment',
                'count' => $assessmentMismatches->count(),
                'items' => $assessmentMismatches->map(fn ($item) => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'organization_id' => $item->organization_id,
                    'is_global' => $item->is_global,
                ])->toArray(),
            ];
        }

        if ($service->course) {
            $course = $service->course;
            if ($course->organization_id !== $orgId || $course->is_global) {
                $warnings[] = [
                    'type' => 'course',
                    'count' => 1,
                    'items' => [[
                        'id' => $course->id,
                        'title' => $course->title,
                        'organization_id' => $course->organization_id,
                        'is_global' => $course->is_global,
                    ]],
                ];
            }
        }

        return $warnings;
    }

    private function handleMediaUploads(
        Service $service,
        StoreServiceRequest $request,
        bool $replace = false
    ): void {
        if (! $request->hasFile('media')) {
            return;
        }

        $newPaths = collect($request->file('media'))->map(
            fn ($file) => $file->store("service-media/{$service->id}", 'public')
        )->all();

        if ($replace) {
            $service->media = $newPaths;
        } else {
            $service->media = array_merge($service->media ?? [], $newPaths);
        }

        $service->save();
    }
}
