<?php

namespace App\Http\Controllers;

use App\Models\AdminTask;
use App\Models\Assessment;
use App\Models\Child;
use App\Models\HomeworkAssignment;
use App\Models\JourneyCategory;
use App\Models\Lesson;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class LessonController extends Controller
{
      public function browseIndex()
    {
       $services = Service::whereIn('_type', ['lesson', 'bundle'])
        ->with([
            'lessons:id,title,service_id',
            'children:id'                // we just need the PK
        ])
        ->get()

        /* 2️⃣  For every Service append a child_ids array that the
               React filter expects and (optionally) drop the full
               children collection to keep the JSON small.              */
        ->map(function ($svc) {
            $svc->child_ids = $svc->children
                ->pluck('id')                 // [1, 4, 9]
                ->map(strval(...))            // ["1","4","9"]
                ->values();                   // re-index 0…n

            unset($svc->children);            // not needed by the UI
            return $svc;
        });

        return Inertia::render('@parent/Lessons/Browse', [
            'services' => $services,
        ]);
    }

    public function index(Request $request)
    {
        $user = auth()->user();
        $query = Lesson::withCount(['children','assessments'])->latest();
        
        // Super admin: optional organization filtering
        if ($user->role === 'super_admin' && $request->filled('organization_id')) {
            $query->where('organization_id', $request->organization_id);
        } 
        // Regular users: include global + their organization
        elseif ($user->role !== 'super_admin') {
            $query->visibleToOrg($user->current_organization_id);
        }
        
        $lessons = $query->paginate(10);
        
        // Get organizations for super admin dropdown
        $organizations = null;
        if ($user->role === 'super_admin') {
            $organizations = \App\Models\Organization::orderBy('name')->get();
        }

        return Inertia::render('@admin/Lessons/Index', [
            'lessons' => $lessons,
            'organizations' => $organizations,
            'filters' => $request->only('organization_id'),
        ]);
    }
     public function adminShow(Lesson $lesson)
    {
        // 1️⃣ eager‐load everything we need
        $lesson->load([
            'service:id,service_name',
            'service.children.user:id,name',
            'assessments',
            'assessments.submissions:id,assessment_id',
            'attendances.child:id,child_name',
            'attendances' => fn($q) =>
                $q->whereDate('date', optional($lesson->start_time)->toDateString()),
        ]);
        
        // Load linked live session if exists
        $linkedSession = null;
        if ($lesson->live_lesson_session_id) {
            $linkedSession = \App\Models\LiveLessonSession::with('lesson:id,title')
                ->find($lesson->live_lesson_session_id);
        }

        // 2️⃣ format the children + attendance (handle no service)
        $children = collect();
        if ($lesson->service && $lesson->service->children) {
            $children = $lesson->service->children->map(fn($c) => [
                'id'         => $c->id,
                'name'       => $c->child_name,
                'parentName' => $c->user->name,
                'attendance' => optional(
                    $lesson->attendances->first(fn($at) => $at->child_id === $c->id)
                )->only(['id','status','notes','approved','date']),
            ]);
        }

        // 3️⃣ format the assessments + submission count
        $assessments = $lesson->assessments->map(fn($a) => [
            'id'                => $a->id,
            'title'             => $a->title,
            'description'       => $a->description,
            'deadline'          => $a->deadline,
            'submission_count'  => $a->submissions->count(),
        ]);

        // 4️⃣ render the admin view
        return Inertia::render('@admin/Lessons/Show', [
            'lesson' => [
                'id'            => $lesson->id,
                'title'         => $lesson->title,
                'description'   => $lesson->description,
                'lesson_type'   => $lesson->lesson_type,
                'lesson_mode'   => $lesson->lesson_mode,
                'start_time'    => $lesson->start_time,
                'end_time'      => $lesson->end_time,
                'address'       => $lesson->address,
                'meeting_link'  => $lesson->meeting_link,
                'live_lesson_session_id' => $lesson->live_lesson_session_id,
                'service'       => $lesson->service ? [
                    'id'   => $lesson->service->id,
                    'name' => $lesson->service->service_name,
                ] : null,
            ],
            'children'    => $children,
            'assessments' => $assessments,
            'linkedSession' => $linkedSession,
        ]);
    }
    public function create()
    {
        $user = auth()->user();

        $instructors = User::query()
            ->select('id', 'name')
            ->where('role', User::ROLE_TEACHER)
            ->when(!$user->isSuperAdmin(), function ($query) use ($user) {
                $orgId = $user->current_organization_id;

                if (!$orgId) {
                    // No org context; hide list to avoid cross-org leakage
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

        $user = auth()->user();
        $orgId = $user?->role === 'super_admin' ? null : $user?->current_organization_id;
        $journeyCategories = JourneyCategory::with('journey')
            ->when($orgId, fn($q) => $q->forOrganization($orgId))
            ->get();
        
        // Fetch available live lesson sessions for linking
        $liveLessonSessions = \App\Models\LiveLessonSession::with('lesson:id,title')
                      ->whereIn('status', ['scheduled', 'live'])
                      ->orderBy('scheduled_start_time', 'desc')
                      ->get(['id', 'lesson_id', 'session_code', 'scheduled_start_time', 'status']);

        $organizations = $user->role === 'super_admin'
            ? \App\Models\Organization::select('id', 'name')->orderBy('name')->get()
            : null;
                      
        return Inertia::render('@admin/Lessons/Create', [
            'instructors' => $instructors,
            'services' => [1, 2],
            'journeyCategories' => $journeyCategories,
            'liveLessonSessions' => $liveLessonSessions,
            'routePrefix' => $user->role === 'teacher' ? 'teacher' : 'lessons',
            'organizations' => $organizations,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title'        => 'required|string|max:255',
            'description'  => 'nullable|string',
            'lesson_type'  => 'required|in:1:1,group',
            'lesson_mode'  => 'required|in:in_person,online',
            'start_time'   => 'nullable|date',
            'journey_category_id' => 'nullable|exists:journey_categories,id',
            'end_time'     => 'nullable|date|after_or_equal:start_time',
            'address'      => 'nullable|string',
            'meeting_link' => 'nullable|url',
            'live_lesson_session_id' => 'nullable|exists:live_lesson_sessions,id',
            'instructor_id'=> 'nullable|exists:users,id',
            'service_id'   => 'nullable|exists:services,id',
            'year_group'   => 'nullable|string|max:50',
            'is_global' => 'nullable|boolean',
            'organization_id' => 'nullable|integer|exists:organizations,id',
        ]);
        
        $user = auth()->user();
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
        
        // If online mode and live_lesson_session_id provided, fetch meeting link
        if ($data['lesson_mode'] === 'online' && !empty($data['live_lesson_session_id'])) {
            $liveSession = \App\Models\LiveLessonSession::find($data['live_lesson_session_id']);
            if ($liveSession && empty($data['meeting_link'])) {
                // Auto-populate meeting link from live session's connection_info
                $connectionInfo = is_string($liveSession->connection_info) 
                    ? json_decode($liveSession->connection_info, true) 
                    : $liveSession->connection_info;
                $data['meeting_link'] = $connectionInfo['meeting_link'] ?? null;
            }
        }

        $lesson = Lesson::create($data);

        // Build related entity link based on assignee role (teacher vs admin)
        $relatedLink = route('lessons.admin.show', $lesson->id);
        if (!empty($data['instructor_id'])) {
            $instructor = User::find($data['instructor_id']);
            if ($instructor && $instructor->role === 'teacher') {
                $relatedLink = route('teacher.lessons.show', $lesson->id);
            }
        }

        AdminTask::create([
                'task_type'     => 'Lesson assigned to ' . (isset($data['instructor_id']) ? User::find($data['instructor_id'])->name : 'Unknown'),
                'assigned_to'   => $data['instructor_id'] ?? null,
                'status'        => 'Pending',
                'related_entity' => $relatedLink,
                'priority'      => 'Medium',
            ]);

        // TODO: attach children if sent
        // $lesson->children()->attach($request->child_ids);

        // Redirect back to the same route namespace we came from
        $prefix = $request->routeIs('teacher.*') ? 'teacher' : 'lessons';
        return redirect()->route("{$prefix}.lessons.index")->with('success','Lesson created');
    }

   /* app/Http/Controllers/LessonController.php */

public function show(Lesson $lesson)
{
    $user = auth()->user();

    /* load attendances relation for compatibility, but we'll read DB directly */
    $lesson->load([
        'attendances' => fn ($q) =>
            $q->whereDate('date', $lesson->start_time),  // today's attendance rows
    ]);
    
    // Load linked live session if exists
    $linkedSession = null;
    if ($lesson->live_lesson_session_id) {
        $linkedSession = \App\Models\LiveLessonSession::with('lesson:id,title')
            ->find($lesson->live_lesson_session_id);
    }

    /*
     * Determine allowed children using the Access table.
     * We consider Access rows that explicitly reference this lesson
     * (either via lesson_id or stored lesson_ids JSON) and where
     * access is true and payment_status is 'paid'.
     */
    $accessRecords = \App\Models\Access::where('access', true)
        ->where('payment_status', 'paid')
        ->where(function ($q) use ($lesson) {
            $q->where('lesson_id', $lesson->id)
              ->orWhereJsonContains('lesson_ids', $lesson->id)
              ->orWhereRaw('JSON_CONTAINS(lesson_ids, ?)', [json_encode((string) $lesson->id)]);
        })
        ->get();

    // log accessRecords summary for debugging
    $accessDetails = $accessRecords->map(fn($a) => [
        'id' => $a->id,
        'child_id' => $a->child_id,
        'lesson_id' => $a->lesson_id,
        'lesson_ids' => $a->lesson_ids,
        'assessment_id' => $a->assessment_id,
        'payment_status' => $a->payment_status,
    ])->values()->all();


    // collect unique child IDs from access records that actually match this lesson
    $childIds = collect();
    foreach ($accessRecords as $a) {
        // lesson_ids might already be an array due to Laravel's JSON casting
        $lessonIds = is_array($a->lesson_ids) ? $a->lesson_ids : (json_decode($a->lesson_ids, true) ?: []);
        if ($a->lesson_id == $lesson->id || in_array($lesson->id, array_map('intval', $lessonIds))) {
            $childIds->push($a->child_id);
        }
    }
    $childIds = $childIds->unique()->values();

    // restrict by parent if needed
    if ($user->role !== 'admin') {
        $allowedIds = $user->children->pluck('id');
        $childIds = $childIds->intersect($allowedIds)->values();
       
    } else {
        Log::info('LessonController@show user is admin', ['user_id' => $user->id ?? null]);
    }

    // load Child models for allowed ids
    $children = \App\Models\Child::whereIn('id', $childIds->all())
        ->get(['id','child_name']);

   

    // Fetch attendance rows directly from attendance table for this lesson/date
    $attendanceRows = \App\Models\Attendance::where('lesson_id', $lesson->id)
        // ->whereDate('date', $lesson->start_time)
        ->get(['id','child_id','status','notes','approved','date']);

    

    // shape children for front-end including attendance info
    $childrenPayload = $children->map(fn($c) => [
        'id' => $c->id,
        'name' => $c->child_name,
        'attendance' => optional(
            $attendanceRows->firstWhere('child_id', $c->id)
        )->only(['id','status','notes','approved','date']),
    ])->values();

    // assessments unchanged (still restrict by Access assessment ids if present)
    $assessmentIdsFromAccess = collect();
    foreach ($accessRecords as $a) {
        if (!empty($a->assessment_id)) $assessmentIdsFromAccess->push($a->assessment_id);
        $parsed = [];
        try { $parsed = json_decode($a->assessment_ids, true) ?: []; } catch (\Throwable $e) { $parsed = []; }
        foreach ($parsed as $aid) $assessmentIdsFromAccess->push($aid);
    }
    $assessmentIdsFromAccess = $assessmentIdsFromAccess->unique()->values();

    if ($assessmentIdsFromAccess->isNotEmpty()) {
        $assessments = Assessment::where('lesson_id', $lesson->id)
            ->whereIn('id', $assessmentIdsFromAccess->all())
            ->get();
    } else {
        $assessments = Assessment::where('lesson_id', $lesson->id)->get();
    }


    return Inertia::render('@parent/Lessons/Show', [
        'lesson' => $lesson,
        'assessments' => $assessments,
        'children' => $childrenPayload,
        'attendances' => $attendanceRows,
        'linkedSession' => $linkedSession,
    ]);
}

  public function portalIndex(Request $request)
{
     $user = $request->user();

    // work out which child IDs this parent has
    $visibleChildIds = $user->role === 'parent'
        ? $user->children()->pluck('id')->all()
        : Child::pluck('id')->all();

    // grab lessons that touch at least one of those children
    $raw = Lesson::with(['children:id', 'service.children:id'])   // eager-load
        ->where(function ($q) use ($visibleChildIds) {
            $q->whereHas('children',    fn ($c) => $c->whereIn('children.id', $visibleChildIds))
              ->orWhereHas('service.children', fn ($c) => $c->whereIn('children.id', $visibleChildIds));
        })
        ->orderBy('start_time')
        ->get();

    /* BUILD allowed_child_ids → [ '12', '44', … ]  */
   $lessons = $raw->map(function (Lesson $l) {
    // ① children attached directly to the lesson
    $ids = collect($l->children)->pluck('id');

    // ② plus children attached through the SINGLE service (if any)
    if ($l->service) {                         // ← only one, might be null
        $ids = $ids->merge($l->service->children->pluck('id'));
    }

    $l->allowed_child_ids = $ids->unique()
                                ->values()
                                ->map(fn ($id) => (string) $id);

    return $l;
});


    return Inertia::render('@parent/Lessons/Index', [
        'lessons'      => $lessons,
        // you can still ship childrenList for the navbar‐dropdown if you like
    ]);
}


    public function edit(Lesson $lesson)
    {
        $user = auth()->user();

        // Scope instructors to teachers in the current org (unless super admin)
        $instructors = User::query()
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

        $services    = Service::select('id','service_name')->get();
        $user = auth()->user();
        $orgId = $user?->role === 'super_admin' ? null : $user?->current_organization_id;
        $journeyCategories = JourneyCategory::with('journey')
            ->when($orgId, fn($q) => $q->forOrganization($orgId))
            ->get();
        
        // Fetch available live lesson sessions for linking
        $liveLessonSessions = \App\Models\LiveLessonSession::with('lesson:id,title')
                       ->whereIn('status', ['scheduled', 'live'])
                       ->orderBy('scheduled_start_time', 'desc')
                       ->get(['id', 'lesson_id', 'session_code', 'scheduled_start_time', 'status']);

        $organizations = $user->role === 'super_admin'
            ? \App\Models\Organization::select('id', 'name')->orderBy('name')->get()
            : null;
        
        return Inertia::render('@admin/Lessons/Edit', [
            'lesson'      => $lesson,
            'instructors' => $instructors,
            'services'    => $services,
            'journeyCategories' => $journeyCategories,
            'liveLessonSessions' => $liveLessonSessions,
            'organizations' => $organizations,
        ]);
    }

    /**
     * Persist updates to a lesson.
     */
    public function update(Request $request, Lesson $lesson)
    {
        $data = $request->validate([
            'title'         => 'required|string|max:255',
            'description'   => 'nullable|string',
            'lesson_type'   => 'required|in:1:1,group',
            'lesson_mode'   => 'required|in:in_person,online',
            'start_time'    => 'nullable|date',
            'journey_category_id' => 'nullable|exists:journey_categories,id',
            'end_time'      => 'nullable|date|after_or_equal:start_time',
            'address'       => 'nullable|string',
            'meeting_link'  => 'nullable|url',
            'live_lesson_session_id' => 'nullable|exists:live_lesson_sessions,id',
            'instructor_id' => 'nullable|exists:users,id',
            'service_id'    => 'nullable|exists:services,id',
            'year_group'    => 'nullable|string|max:50',
            'is_global' => 'nullable|boolean',
            'organization_id' => 'nullable|integer|exists:organizations,id',
        ]);

        $user = auth()->user();
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
        
        // If online mode and live_lesson_session_id provided, fetch meeting link
        if ($data['lesson_mode'] === 'online' && !empty($data['live_lesson_session_id'])) {
            $liveSession = \App\Models\LiveLessonSession::find($data['live_lesson_session_id']);
            if ($liveSession && empty($data['meeting_link'])) {
                // Auto-populate meeting link from live session's connection_info
                $connectionInfo = is_string($liveSession->connection_info) 
                    ? json_decode($liveSession->connection_info, true) 
                    : $liveSession->connection_info;
                $data['meeting_link'] = $connectionInfo['meeting_link'] ?? null;
            }
        }

        $lesson->update($data);

        return redirect()
            ->route('lessons.admin.show', $lesson)
            ->with('success', 'Lesson updated successfully!');
        }

        /**
         * Delete a lesson.
         */
        public function destroy(Lesson $lesson)
        {
        $lesson->delete();

        return redirect()
            ->route('lessons.index')
            ->with('success', 'Lesson deleted successfully!');
        }

    public function AssignedLessons()
    {
        $user = auth()->user();

        // Ensure only admins can access this
        if ($user->role !== 'admin') {
            abort(403, 'Unauthorized');
        }

        // Get lessons where this user is the instructor
        $lessons = Lesson::where('instructor_id', $user->id)
            ->withCount(['children', 'assessments'])
            ->with(['service:id,service_name'])
            ->orderBy('start_time', 'desc')
            ->get();

        return Inertia::render('@admin/Lessons/AssignedLessons', [
            'lessons' => $lessons,
        ]);
    }

    /**
     * Teacher-scoped lesson index
     * Shows all lessons where the authenticated teacher is the instructor
     */
    public function teacherIndex()
    {
        $user = auth()->user();
        
        // Show lessons for the teacher's organization (not limited to instructor_id)
        $lessons = Lesson::withCount(['children', 'assessments'])
            ->with(['service:id,service_name'])
            ->when($user->current_organization_id, function ($q) use ($user) {
                $q->where('organization_id', $user->current_organization_id);
            })
            ->orderBy('start_time', 'desc')
            ->paginate(10);
        
        return Inertia::render('@admin/Lessons/Index', [
            'lessons' => $lessons
        ]);
    }

    /**
     * Teacher-scoped assigned lessons
     * Shows all lessons assigned to the authenticated teacher
     */
    public function teacherAssignedLessons()
    {
        $user = auth()->user();
        
        // Get lessons assigned to this teacher
        $lessons = Lesson::where('instructor_id', $user->id)
            ->withCount(['children', 'assessments'])
            ->with(['service:id,service_name'])
            ->orderBy('start_time', 'desc')
            ->get();
        
        return Inertia::render('@admin/Lessons/AssignedLessons', [
            'lessons' => $lessons,
        ]);
    }
}
