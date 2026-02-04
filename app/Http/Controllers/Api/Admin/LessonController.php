<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\AdminTask;
use App\Models\JourneyCategory;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use App\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LessonController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $query = Lesson::withCount(['children', 'assessments'])
            ->with(['service:id,service_name'])
            ->latest();

        if ($user->isSuperAdmin() && $request->filled('organization_id')) {
            $query->where('organization_id', $request->integer('organization_id'));
        } elseif (!$user->isSuperAdmin()) {
            $query->visibleToOrg($user->current_organization_id);
        }

        $lessons = $query->paginate(ApiPagination::perPage($request, 10));

        $organizations = null;
        if ($user->isSuperAdmin()) {
            $organizations = Organization::orderBy('name')->get(['id', 'name']);
        }

        $data = $lessons->getCollection()->map(fn (Lesson $lesson) => $this->mapLessonSummary($lesson))->all();

        return $this->paginated($lessons, $data, [
            'organizations' => $organizations,
            'filters' => $request->only('organization_id'),
        ]);
    }

    public function show(Request $request, Lesson $lesson): JsonResponse
    {
        if ($response = $this->ensureScope($request, $lesson)) {
            return $response;
        }

        $lesson->load([
            'service:id,service_name',
            'service.children.user:id,name',
            'assessments',
            'assessments.submissions:id,assessment_id',
            'attendances.child:id,child_name',
            'attendances' => fn ($q) => $q->whereDate('date', optional($lesson->start_time)->toDateString()),
        ]);

        $linkedSession = null;
        if ($lesson->live_lesson_session_id) {
            $linkedSession = \App\Models\LiveLessonSession::with('lesson:id,title')
                ->find($lesson->live_lesson_session_id);
        }

        $children = collect();
        if ($lesson->service && $lesson->service->children) {
            $children = $lesson->service->children->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->child_name,
                'parentName' => $c->user->name,
                'attendance' => optional(
                    $lesson->attendances->first(fn ($at) => $at->child_id === $c->id)
                )->only(['id', 'status', 'notes', 'approved', 'date']),
            ]);
        }

        $assessments = $lesson->assessments->map(fn ($a) => [
            'id' => $a->id,
            'title' => $a->title,
            'description' => $a->description,
            'deadline' => $a->deadline,
            'submission_count' => $a->submissions->count(),
        ]);

        return $this->success([
            'lesson' => $this->mapLessonDetail($lesson),
            'children' => $children,
            'assessments' => $assessments,
            'linkedSession' => $linkedSession,
        ]);
    }

    public function createData(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $instructors = $this->teacherOptions($user);
        $journeyCategories = $this->journeyCategoriesFor($user);
        $liveLessonSessions = $this->liveLessonSessions();
        $organizations = $user->isSuperAdmin()
            ? Organization::select('id', 'name')->orderBy('name')->get()
            : null;

        return $this->success([
            'instructors' => $instructors,
            'journey_categories' => $journeyCategories,
            'live_lesson_sessions' => $liveLessonSessions,
            'services' => Service::select('id', 'service_name')->orderBy('service_name')->get(),
            'organizations' => $organizations,
        ]);
    }

    public function editData(Request $request, Lesson $lesson): JsonResponse
    {
        if ($response = $this->ensureScope($request, $lesson)) {
            return $response;
        }

        $user = $request->user();

        return $this->success([
            'lesson' => $this->mapLessonDetail($lesson),
            'instructors' => $this->teacherOptions($user),
            'journey_categories' => $this->journeyCategoriesFor($user),
            'live_lesson_sessions' => $this->liveLessonSessions(),
            'services' => Service::select('id', 'service_name')->orderBy('service_name')->get(),
            'organizations' => $user->isSuperAdmin()
                ? Organization::select('id', 'name')->orderBy('name')->get()
                : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $data = $this->validateLesson($request);

        $isSuperAdmin = $user->role === 'super_admin';
        $isGlobal = $isSuperAdmin && $request->boolean('is_global');
        $organizationId = $request->input('organization_id');

        if (!$isSuperAdmin) {
            $organizationId = $user->current_organization_id;
            $isGlobal = false;
        }

        if ($isGlobal) {
            $organizationId = null;
        }

        if ($isSuperAdmin && !$isGlobal && !$organizationId) {
            throw ValidationException::withMessages([
                'organization_id' => 'Organization is required unless the lesson is global.',
            ]);
        }

        $data['organization_id'] = $organizationId;
        $data['is_global'] = $isGlobal;

        if ($data['lesson_mode'] === 'online' && !empty($data['live_lesson_session_id'])) {
            $liveSession = \App\Models\LiveLessonSession::find($data['live_lesson_session_id']);
            if ($liveSession && empty($data['meeting_link'])) {
                $connectionInfo = is_string($liveSession->connection_info)
                    ? json_decode($liveSession->connection_info, true)
                    : $liveSession->connection_info;
                $data['meeting_link'] = $connectionInfo['meeting_link'] ?? null;
            }
        }

        $lesson = Lesson::create($data);

        $relatedLink = route('lessons.admin.show', $lesson->id);
        if (!empty($data['instructor_id'])) {
            $instructor = User::find($data['instructor_id']);
            if ($instructor && $instructor->role === 'teacher') {
                $relatedLink = route('teacher.lessons.show', $lesson->id);
            }
        }

        AdminTask::create([
            'task_type' => 'Lesson assigned to ' . (isset($data['instructor_id']) ? User::find($data['instructor_id'])->name : 'Unknown'),
            'assigned_to' => $data['instructor_id'] ?? null,
            'status' => 'Pending',
            'related_entity' => $relatedLink,
            'priority' => 'Medium',
        ]);

        return $this->success($this->mapLessonDetail($lesson), [], 201);
    }

    public function update(Request $request, Lesson $lesson): JsonResponse
    {
        if ($response = $this->ensureScope($request, $lesson)) {
            return $response;
        }

        $data = $this->validateLesson($request);

        $user = $request->user();
        $isSuperAdmin = $user->role === 'super_admin';
        $isGlobal = $isSuperAdmin && $request->boolean('is_global');
        $organizationId = $request->input('organization_id');

        if (!$isSuperAdmin) {
            $organizationId = $user->current_organization_id;
            $isGlobal = false;
        }

        if ($isGlobal) {
            $organizationId = null;
        }

        if ($isSuperAdmin && !$isGlobal && !$organizationId) {
            throw ValidationException::withMessages([
                'organization_id' => 'Organization is required unless the lesson is global.',
            ]);
        }

        $data['organization_id'] = $organizationId;
        $data['is_global'] = $isGlobal;

        if ($data['lesson_mode'] === 'online' && !empty($data['live_lesson_session_id'])) {
            $liveSession = \App\Models\LiveLessonSession::find($data['live_lesson_session_id']);
            if ($liveSession && empty($data['meeting_link'])) {
                $connectionInfo = is_string($liveSession->connection_info)
                    ? json_decode($liveSession->connection_info, true)
                    : $liveSession->connection_info;
                $data['meeting_link'] = $connectionInfo['meeting_link'] ?? null;
            }
        }

        $lesson->update($data);

        return $this->success($this->mapLessonDetail($lesson));
    }

    public function destroy(Request $request, Lesson $lesson): JsonResponse
    {
        if ($response = $this->ensureScope($request, $lesson)) {
            return $response;
        }

        $lesson->delete();

        return $this->success(['message' => 'Lesson deleted successfully.']);
    }

    public function assigned(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $lessons = Lesson::where('instructor_id', $user->id)
            ->withCount(['children', 'assessments'])
            ->with(['service:id,service_name'])
            ->orderBy('start_time', 'desc')
            ->get();

        return $this->success($lessons->map(fn (Lesson $lesson) => $this->mapLessonSummary($lesson))->all());
    }

    private function validateLesson(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'lesson_type' => 'required|in:1:1,group',
            'lesson_mode' => 'required|in:in_person,online',
            'start_time' => 'nullable|date',
            'journey_category_id' => 'nullable|exists:journey_categories,id',
            'end_time' => 'nullable|date|after_or_equal:start_time',
            'address' => 'nullable|string',
            'meeting_link' => 'nullable|url',
            'live_lesson_session_id' => 'nullable|exists:live_lesson_sessions,id',
            'instructor_id' => 'nullable|exists:users,id',
            'service_id' => 'nullable|exists:services,id',
            'year_group' => 'nullable|string|max:50',
            'is_global' => 'nullable|boolean',
            'organization_id' => 'nullable|integer|exists:organizations,id',
        ]);
    }

    private function ensureScope(Request $request, Lesson $lesson): ?JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        if ($user->isSuperAdmin()) {
            return null;
        }

        $orgId = $user->current_organization_id;
        if ($orgId && !($lesson->is_global || (int) $lesson->organization_id === (int) $orgId)) {
            return $this->error('Not found.', [], 404);
        }

        return null;
    }

    private function mapLessonSummary(Lesson $lesson): array
    {
        return [
            'id' => $lesson->id,
            'organization_id' => $lesson->organization_id,
            'title' => $lesson->title,
            'description' => $lesson->description,
            'lesson_type' => $lesson->lesson_type,
            'lesson_mode' => $lesson->lesson_mode,
            'start_time' => $lesson->start_time,
            'end_time' => $lesson->end_time,
            'address' => $lesson->address,
            'meeting_link' => $lesson->meeting_link,
            'children_count' => $lesson->children_count,
            'assessments_count' => $lesson->assessments_count,
            'service' => $lesson->service ? [
                'id' => $lesson->service->id,
                'name' => $lesson->service->service_name,
            ] : null,
        ];
    }

    private function mapLessonDetail(Lesson $lesson): array
    {
        return [
            'id' => $lesson->id,
            'organization_id' => $lesson->organization_id,
            'title' => $lesson->title,
            'description' => $lesson->description,
            'lesson_type' => $lesson->lesson_type,
            'lesson_mode' => $lesson->lesson_mode,
            'start_time' => $lesson->start_time,
            'end_time' => $lesson->end_time,
            'address' => $lesson->address,
            'meeting_link' => $lesson->meeting_link,
            'live_lesson_session_id' => $lesson->live_lesson_session_id,
            'journey_category_id' => $lesson->journey_category_id,
            'instructor_id' => $lesson->instructor_id,
            'service_id' => $lesson->service_id,
            'year_group' => $lesson->year_group,
            'is_global' => $lesson->is_global,
            'service' => $lesson->service ? [
                'id' => $lesson->service->id,
                'name' => $lesson->service->service_name,
            ] : null,
        ];
    }

    private function teacherOptions(User $user)
    {
        return User::query()
            ->select('id', 'name')
            ->where('role', User::ROLE_TEACHER)
            ->when(!$user->isSuperAdmin(), function ($query) use ($user) {
                $orgId = $user->current_organization_id;

                if (!$orgId) {
                    return $query->whereRaw('1 = 0');
                }

                return $query->where(function ($q) use ($orgId) {
                    $q->where('current_organization_id', $orgId)
                        ->orWhereHas('organizations', function ($orgQuery) use ($orgId) {
                            $orgQuery->where('organization_id', $orgId);
                        });
                });
            })
            ->orderBy('name')
            ->get();
    }

    private function journeyCategoriesFor(User $user)
    {
        $orgId = $user->isSuperAdmin() ? null : $user->current_organization_id;

        return JourneyCategory::with('journey')
            ->when($orgId, fn ($q) => $q->forOrganization($orgId))
            ->get();
    }

    private function liveLessonSessions()
    {
        return \App\Models\LiveLessonSession::with('lesson:id,title')
            ->whereIn('status', ['scheduled', 'live'])
            ->orderBy('scheduled_start_time', 'desc')
            ->get(['id', 'lesson_id', 'session_code', 'scheduled_start_time', 'status']);
    }
}
