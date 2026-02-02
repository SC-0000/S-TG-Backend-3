<?php

namespace App\Http\Controllers;

use App\Models\LiveLessonSession;
use App\Models\ContentLesson;
use App\Models\LiveSessionParticipant;
use App\Models\LiveSessionMessage;
use App\Models\User;
use App\Events\SlideChanged;
use App\Events\MessageSent;
use App\Events\EmojiReaction;
use App\Events\SessionStateChanged;
use App\Events\BlockHighlighted;
use App\Events\AnnotationStroke;
use App\Events\AnnotationClear;
use App\Events\StudentInteraction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

class LiveLessonController extends Controller
{
    /**
     * Display a listing of all live sessions (Admin)
     */
    public function index(Request $request)
    {
        Log::info('[LiveLessonController] Loading sessions index');

        $user = Auth::user();

        $query = LiveLessonSession::with(['lesson', 'teacher', 'organization'])
            ->orderBy('scheduled_start_time', 'desc');

        // Super admin can filter by organization, others see only their organization
        if ($user->hasRole('super_admin') && $request->filled('organization_id')) {
            $query->where('organization_id', $request->organization_id);
        } elseif (!$user->hasRole('super_admin') && $user->current_organization_id) {
            $query->where('organization_id', $user->current_organization_id);
        }

        // Filter by status if provided
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $sessions = $query->paginate(20);

        // Get organizations for super admin
        $organizations = null;
        if ($user->hasRole('super_admin')) {
            $organizations = \App\Models\Organization::orderBy('name')->get();
        }

        return Inertia::render('@admin/LiveSessions/Index', [
            'sessions' => $sessions,
            'organizations' => $organizations,
            'filters' => $request->only(['status', 'organization_id'])
        ]);
    }

    /**
     * Teacher's live sessions index - scoped to their organization
     */
    public function teacherIndex(Request $request)
    {
        Log::info('[LiveLessonController] Loading teacher sessions index');

        $user = Auth::user();

        $query = LiveLessonSession::with(['lesson', 'teacher', 'organization'])
            ->when($user->current_organization_id, function($q) use ($user) {
                $q->where('organization_id', $user->current_organization_id);
            })
            ->orderBy('scheduled_start_time', 'desc');

        // Filter by status if provided
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $sessions = $query->paginate(20);

        return Inertia::render('@admin/LiveSessions/Index', [
            'sessions' => $sessions,
            'filters' => $request->only('status')
        ]);
    }

    /**
     * Show the form for creating a new live session
     */
    public function create()
    {
        Log::info('[LiveLessonController] Loading session creation form');

        // Get available lessons
        $lessons = ContentLesson::with(['modules.course', 'slides'])
            ->whereHas('slides')
            ->orderBy('title')
            ->get()
            ->map(fn($lesson) => [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'course_name' => $lesson->modules->first()?->course?->title ?? 'N/A',
                'slides_count' => $lesson->slides->count()
            ]);

        // ✅ Only get teachers list for admins
        $teachers = auth()->user()->role === 'admin' 
            ? User::where('role', 'admin')
                ->orWhere('role', 'teacher')
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
            : collect(); // Empty collection for teachers

        return Inertia::render('@admin/LiveSessions/Create', [
            'lessons' => $lessons,
            'teachers' => $teachers
        ]);
    }

    /**
     * Store a newly created live session
     */
    public function store(Request $request)
    {
        // ✅ Conditional validation based on user role
        $validated = $request->validate([
            'lesson_id' => 'required|exists:new_lessons,id',
            'course_id' => 'nullable|exists:courses,id',
            'year_group' => 'nullable|string|max:50',
            'teacher_id' => auth()->user()->role === 'admin' 
                ? 'required|exists:users,id' 
                : 'nullable|exists:users,id', // ✅ Optional for teachers
            'organization_id' => 'nullable|exists:organizations,id',
            'scheduled_start_time' => 'required|date|after:now',
            'audio_enabled' => 'boolean',
            'video_enabled' => 'boolean',
            'allow_student_questions' => 'boolean',
            'whiteboard_enabled' => 'boolean',
            'record_session' => 'boolean',
            'start_now' => 'boolean'
        ]);

        // ✅ Auto-assign teacher_id if not provided (for teachers)
        $teacherId = $validated['teacher_id'] ?? auth()->id();

        Log::info('[LiveLessonController] Creating new session', [
            'validated' => $validated,
            'teacher_id' => $teacherId,
            'creator_role' => auth()->user()->role
        ]);

        // Get the first slide of the lesson
        $lesson = ContentLesson::with('slides')->findOrFail($validated['lesson_id']);
        $firstSlide = $lesson->slides->sortBy('order')->first();

        $session = LiveLessonSession::create([
            'lesson_id' => $validated['lesson_id'],
            'course_id' => $validated['course_id'] ?? null,
            'year_group' => $validated['year_group'] ?? null,
            'teacher_id' => $teacherId, // ✅ Use $teacherId instead of $validated['teacher_id']
            'organization_id' => $validated['organization_id'] ?? $request->organization_id ?: Auth::user()->current_organization_id,
            'scheduled_start_time' => $validated['scheduled_start_time'],
            'current_slide_id' => $firstSlide?->id,
            'status' => $validated['start_now'] ?? false ? 'live' : 'scheduled',
            'actual_start_time' => $validated['start_now'] ?? false ? now() : null,
            'audio_enabled' => $validated['audio_enabled'] ?? true,
            'video_enabled' => $validated['video_enabled'] ?? false,
            'allow_student_questions' => $validated['allow_student_questions'] ?? true,
            'whiteboard_enabled' => $validated['whiteboard_enabled'] ?? true,
            'record_session' => $validated['record_session'] ?? false,
            'pacing_mode' => 'teacher_controlled',
            'navigation_locked' => false
        ]);

        Log::info('[LiveLessonController] Session created', ['session_id' => $session->id]);

        // ✅ Create notification task based on who created the session
        $creatorRole = auth()->user()->role;

        if ($creatorRole === 'admin') {
            // Admin created session → Notify the assigned teacher
            \App\Models\AdminTask::create([
                'task_type'      => 'Live Session Scheduled',
                'assigned_to'    => $session->teacher_id, // Notify specific teacher
                'status'         => 'Pending',
                'related_entity' => route('teacher.live-sessions.index'),
                'priority'       => 'Medium',
                'description'    => "A new live session '{$lesson->title}' has been scheduled for " . 
                                    $session->scheduled_start_time->format('M d, Y \a\t g:i A') . 
                                    ". Please review and prepare for the session.",
            ]);
            
            Log::info('[LiveLessonController] Task created for teacher', [
                'teacher_id' => $session->teacher_id,
                'session_id' => $session->id
            ]);
            
        } elseif ($creatorRole === 'teacher') {
            // Teacher created session → Notify admins
            \App\Models\AdminTask::create([
                'task_type'      => 'Live Session Created by Teacher',
                'assigned_to'    => null, // For all admins
                'status'         => 'Pending',
                'related_entity' => route('admin.live-sessions.index'),
                'priority'       => 'Low',
                'description'    => "Teacher " . auth()->user()->name . " has created a new live session '{$lesson->title}' scheduled for " . 
                                    $session->scheduled_start_time->format('M d, Y \a\t g:i A') . ".",
            ]);
            
            Log::info('[LiveLessonController] Task created for admins', [
                'creator' => auth()->user()->name,
                'session_id' => $session->id
            ]);
            
            // ✅ Also create a task for the teacher themselves (linked to the session)
            \App\Models\AdminTask::create([
                'task_type'      => 'Your Upcoming Live Session',
                'assigned_to'    => auth()->id(), // The teacher who created it
                'status'         => 'Pending',
                'related_entity' => route('teacher.live-sessions.teach', $session->id),
                'priority'       => 'High',
                'description'    => "You have scheduled a live session '{$lesson->title}' for " . 
                                    $session->scheduled_start_time->format('M d, Y \a\t g:i A') . 
                                    ". Click here to view or start the session.",
            ]);
            
            Log::info('[LiveLessonController] Task created for teacher (self)', [
                'teacher_id' => auth()->id(),
                'session_id' => $session->id
            ]);
        }

        // Determine route prefix based on user role
        $routePrefix = auth()->user()->role === 'teacher' ? 'teacher' : 'admin';

        // If start now, redirect to teacher panel
        if ($validated['start_now'] ?? false) {
            return redirect()->route("{$routePrefix}.live-sessions.teach", $session->id)
                ->with('success', 'Live session started successfully!');
        }

        return redirect()->route("{$routePrefix}.live-sessions.index")
            ->with('success', 'Live session scheduled successfully!');
    }

    /**
     * Show the form for editing the specified session
     */
    public function edit($sessionId)
    {
        Log::info('[LiveLessonController] Loading session edit form', ['session_id' => $sessionId]);

        $session = LiveLessonSession::with(['lesson', 'teacher'])->findOrFail($sessionId);

        // Get available lessons and teachers
        $lessons = ContentLesson::with(['modules.course', 'slides'])
            ->whereHas('slides')
            ->orderBy('title')
            ->get()
            ->map(fn($lesson) => [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'course_name' => $lesson->modules->first()?->course?->title ?? 'N/A',
                'slides_count' => $lesson->slides->count()
            ]);

        $teachers = User::where('role', 'admin')
            ->orWhere('role', 'teacher')
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return Inertia::render('@admin/LiveSessions/Edit', [
            'session' => $session,
            'lessons' => $lessons,
            'teachers' => $teachers
        ]);
    }

    /**
     * Update the specified session
     */
    public function update(Request $request, $sessionId)
    {
        $validated = $request->validate([
            'lesson_id' => 'required|exists:new_lessons,id',
            'year_group' => 'nullable|string|max:50',
            'teacher_id' => 'required|exists:users,id',
            'organization_id' => 'nullable|exists:organizations,id',
            'scheduled_start_time' => 'required|date',
            'audio_enabled' => 'boolean',
            'video_enabled' => 'boolean',
            'allow_student_questions' => 'boolean',
            'whiteboard_enabled' => 'boolean',
            'record_session' => 'boolean'
        ]);

        Log::info('[LiveLessonController] Updating session', ['session_id' => $sessionId]);

        $session = LiveLessonSession::findOrFail($sessionId);

        // Don't allow editing live or completed sessions
        if (in_array($session->status, ['live', 'completed'])) { // ✅ Fixed: Use 'live' instead of 'active'
            return redirect()->back()
                ->with('error', 'Cannot edit live or completed sessions');
        }

        $session->update($validated);

        Log::info('[LiveLessonController] Session updated successfully');
         $routePrefix = auth()->user()->role === 'teacher' ? 'teacher' : 'admin';
        return redirect()->route("{$routePrefix}.live-sessions.index")
            ->with('success', 'Session updated successfully!');
    }

    /**
     * Remove the specified session
     */
    public function destroy($sessionId)
    {
        Log::info('[LiveLessonController] Deleting session', ['session_id' => $sessionId]);

        $session = LiveLessonSession::findOrFail($sessionId);

        // Don't allow deleting live sessions
        if ($session->status === 'live') { // ✅ Fixed: Use 'live' instead of 'active'
            return redirect()->back()
                ->with('error', 'Cannot delete live sessions. End the session first.');
        }

        $session->delete();

        Log::info('[LiveLessonController] Session deleted successfully');
         $routePrefix = auth()->user()->role === 'teacher' ? 'teacher' : 'admin';
        return redirect()->route("{$routePrefix}.live-sessions.index")
            ->with('success', 'Session deleted successfully!');
    }

    /**
     * Manually start a scheduled session
     */
    public function startSession($sessionId)
    {
        Log::info('[LiveLessonController] Starting session manually', ['session_id' => $sessionId]);

        $session = LiveLessonSession::findOrFail($sessionId);

        if ($session->status !== 'scheduled') {
            return redirect()->back()
                ->with('error', 'Can only start scheduled sessions');
        }

        $session->startSession();

        Log::info('[LiveLessonController] Session started successfully');
         $routePrefix = auth()->user()->role === 'teacher' ? 'teacher' : 'admin';
        return redirect()->route("{$routePrefix}.live-sessions.teach", $session->id)
            ->with('success', 'Session started! You can now begin teaching.');
    }

    /**
     * Display user's purchased live sessions (via courses or services)
     */
    public function mySessionsIndex(Request $request)
    {
        Log::info('[LiveLessonController] Loading my purchased sessions', ['user_id' => auth()->id()]);

        $user = auth()->user();
        
        // Get visible child IDs for this user (same logic as AssessmentController)
        $visibleChildIds = $user->children()->pluck('id')->all();

        // Get all access records for these children
        $accessRecords = \App\Models\Access::whereIn('child_id', $visibleChildIds)
            ->where('access', true)
            ->where('payment_status', 'paid')
            ->where(function($query) {
                $query->whereNotNull('lesson_id')
                      ->orWhereNotNull('lesson_ids')
                      ->orWhereJsonContains('metadata->live_lesson_session_ids', DB::raw('json_array()'));
            })
            ->with(['service', 'course', 'child'])
            ->get();

        // Collect all live lesson session IDs from access records
        $sessionIds = collect();
        
        foreach ($accessRecords as $access) {
            // From lesson_id
            if ($access->lesson_id) {
                $sessionIds->push($access->lesson_id);
            }
            
            // From lesson_ids array
            if ($access->lesson_ids && is_array($access->lesson_ids)) {
                $sessionIds = $sessionIds->merge($access->lesson_ids);
            }
            
            // From metadata->live_lesson_session_ids
            if (isset($access->metadata['live_lesson_session_ids'])) {
                $sessionIds = $sessionIds->merge($access->metadata['live_lesson_session_ids']);
            }
        }

        $sessionIds = $sessionIds->unique()->filter();

        // Load sessions with relationships
        $sessions = LiveLessonSession::whereIn('id', $sessionIds)
            ->with([
                'contentLesson.slides',
                'contentLesson.modules.course',
                'teacher',
                'course'
            ])
            ->orderBy('scheduled_start_time', 'desc')
            ->get()
            ->map(function($session) use ($accessRecords) {
                // Find the access record that granted this session
                $grantingAccess = $accessRecords->first(function($access) use ($session) {
                    if ($access->lesson_id == $session->id) return true;
                    if (is_array($access->lesson_ids) && in_array($session->id, $access->lesson_ids)) return true;
                    if (isset($access->metadata['live_lesson_session_ids']) && 
                        in_array($session->id, $access->metadata['live_lesson_session_ids'])) return true;
                    return false;
                });

                return [
                    'id' => $session->id,
                    'uid' => $session->uid,
                    'status' => $session->status,
                    'scheduled_start_time' => $session->scheduled_start_time,
                    'actual_start_time' => $session->actual_start_time,
                    'end_time' => $session->end_time,
                    'content_lesson' => [
                        'id' => $session->contentLesson->id,
                        'title' => $session->contentLesson->title,
                        'description' => $session->contentLesson->description,
                    ],
                    'course' => $session->course ? [
                        'id' => $session->course->id,
                        'title' => $session->course->title,
                    ] : ($session->contentLesson->modules->first()?->course ? [
                        'id' => $session->contentLesson->modules->first()->course->id,
                        'title' => $session->contentLesson->modules->first()->course->title,
                    ] : null),
                    'module' => $session->contentLesson->modules->first() ? [
                        'id' => $session->contentLesson->modules->first()->id,
                        'title' => $session->contentLesson->modules->first()->title,
                    ] : null,
                    'teacher' => [
                        'id' => $session->teacher->id,
                        'name' => $session->teacher->name,
                    ],
                    'purchase_source' => $grantingAccess ? [
                        'type' => $grantingAccess->course_id ? 'course' : 'service',
                        'name' => $grantingAccess->course_id 
                            ? ($grantingAccess->course?->title ?? 'Unknown Course')
                            : ($grantingAccess->service?->name ?? 'Unknown Service'),
                    ] : null,
                ];
            });

        Log::info('[LiveLessonController] Found purchased sessions', ['count' => $sessions->count()]);

        // Map sessions with child_ids (which children have access to each session)
        $sessions = $sessions->map(function($session) use ($accessRecords) {
            // Find all children who have access to this session
            $child_ids = $accessRecords->filter(function($access) use ($session) {
                if ($access->lesson_id == $session['id']) return true;
                if (is_array($access->lesson_ids) && in_array($session['id'], $access->lesson_ids)) return true;
                if (isset($access->metadata['live_lesson_session_ids']) && 
                    in_array($session['id'], $access->metadata['live_lesson_session_ids'])) return true;
                return false;
            })
            ->pluck('child_id')
            ->map(fn($id) => (string) $id)
            ->unique()
            ->values();

            $session['child_ids'] = $child_ids;
            return $session;
        })
        ->filter(function($session) {
            // Only include sessions where at least one child has access
            return !empty($session['child_ids']) && $session['child_ids']->isNotEmpty();
        })
        ->values(); // Re-index array after filtering

        return Inertia::render('@parent/LiveSessions/MySessions', [
            'sessions' => $sessions,
        ]);
    }

    /**
     * Show details of a specific purchased live session
     */
    public function mySessionShow($sessionId)
    {
        Log::info('[LiveLessonController] Loading session details', [
            'session_id' => $sessionId,
            'user_id' => auth()->id()
        ]);

        $user = auth()->user();
        
        // Get visible child IDs for this user
        $visibleChildIds = $user->children()->pluck('id')->all();

        // Load session with all relationships
        $session = LiveLessonSession::with([
            'contentLesson.slides',
            'contentLesson.modules.course',
            'teacher',
            'course',
            'participants'
        ])->findOrFail($sessionId);

        // Get all access records for the user's children (same logic as mySessionsIndex)
        $accessRecords = \App\Models\Access::whereIn('child_id', $visibleChildIds)
            ->where('access', true)
            ->where('payment_status', 'paid')
            ->where(function($query) {
                $query->whereNotNull('lesson_id')
                      ->orWhereNotNull('lesson_ids')
                      ->orWhereJsonContains('metadata->live_lesson_session_ids', DB::raw('json_array()'));
            })
            ->with(['service', 'course'])
            ->get();

        // Check if any access record grants access to this session (same logic as mySessionsIndex)
        $hasAccess = $accessRecords->contains(function($access) use ($sessionId) {
            // Check lesson_id
            if ($access->lesson_id == $sessionId) return true;
            
            // Check lesson_ids array
            if (is_array($access->lesson_ids) && in_array($sessionId, $access->lesson_ids)) return true;
            
            // Check metadata->live_lesson_session_ids
            if (isset($access->metadata['live_lesson_session_ids']) && 
                in_array($sessionId, $access->metadata['live_lesson_session_ids'])) return true;
            
            return false;
        });

        if (!$hasAccess) {
            abort(403, 'You do not have access to this live session.');
        }

        // Get the access record that grants access to this session
        $access = $accessRecords->first(function($access) use ($sessionId) {
            if ($access->lesson_id == $sessionId) return true;
            if (is_array($access->lesson_ids) && in_array($sessionId, $access->lesson_ids)) return true;
            if (isset($access->metadata['live_lesson_session_ids']) && 
                in_array($sessionId, $access->metadata['live_lesson_session_ids'])) return true;
            return false;
        });

        $sessionData = [
            'id' => $session->id,
            'uid' => $session->uid,
            'status' => $session->status,
            'scheduled_start_time' => $session->scheduled_start_time,
            'actual_start_time' => $session->actual_start_time,
            'end_time' => $session->end_time,
            'duration' => $session->duration,
            'content_lesson' => [
                'id' => $session->contentLesson->id,
                'title' => $session->contentLesson->title,
                'description' => $session->contentLesson->description,
                'slides_count' => $session->contentLesson->slides->count(),
            ],
            'course' => $session->course ? [
                'id' => $session->course->id,
                'title' => $session->course->title,
                'description' => $session->course->description,
            ] : ($session->contentLesson->modules->first()?->course ? [
                'id' => $session->contentLesson->modules->first()->course->id,
                'title' => $session->contentLesson->modules->first()->course->title,
                'description' => $session->contentLesson->modules->first()->course->description,
            ] : null),
            'module' => $session->contentLesson->modules->first() ? [
                'id' => $session->contentLesson->modules->first()->id,
                'title' => $session->contentLesson->modules->first()->title,
                'description' => $session->contentLesson->modules->first()->description,
            ] : null,
            'teacher' => [
                'id' => $session->teacher->id,
                'name' => $session->teacher->name,
                'email' => $session->teacher->email,
            ],
            'purchase_source' => $access ? [
                'type' => $access->course_id ? 'course' : 'service',
                'name' => $access->course_id 
                    ? ($access->course?->title ?? 'Unknown Course')
                    : ($access->service?->name ?? 'Unknown Service'),
                'id' => $access->course_id ?: $access->service_id,
            ] : null,
        ];

        Log::info('[LiveLessonController] Session details loaded successfully');

        return Inertia::render('@parent/LiveSessions/SessionDetails', [
            'session' => $sessionData,
        ]);
    }

    /**
     * Display available live sessions for students
     */
    public function studentIndex()
    {
        Log::info('[LiveLessonController] Loading student sessions index', ['user_id' => auth()->id()]);

        $child = auth()->user()->children()->first();

        if (!$child) {
            return Inertia::render('@parent/LiveSessions/Browse', [
                'sessions' => [],
                'message' => 'No child profile found'
            ]);
        }

        // Get sessions the student has access to
        $sessions = LiveLessonSession::with(['lesson.course', 'teacher'])
            ->where('status', '!=', 'ended')
            ->where(function($query) use ($child) {
                // Filter by organization
                if ($child->organization_id) {
                    $query->where('organization_id', $child->organization_id);
                }
            })
            ->orderByRaw("CASE WHEN status = 'live' THEN 0 ELSE 1 END") // ✅ Fixed: Use 'live' instead of 'active'
            ->orderBy('scheduled_start_time', 'asc')
            ->get()
            ->map(function($session) {
                return [
                    'id' => $session->id,
                    'uid' => $session->uid,
                    'session_code' => $session->session_code,
                    'status' => $session->status,
                    'scheduled_start_time' => $session->scheduled_start_time,
                    'actual_start_time' => $session->actual_start_time,
                    'lesson' => [
                        'id' => $session->lesson->id,
                        'title' => $session->lesson->title,
                        'description' => $session->lesson->description,
                        'course_name' => $session->lesson->course->title ?? 'N/A'
                    ],
                    'teacher' => [
                        'name' => $session->teacher->name
                    ]
                ];
            });

        Log::info('[LiveLessonController] Found sessions for student', ['count' => $sessions->count()]);
        Log::info('[LiveLessonController] Sessions data', ['sessions' => $sessions->toArray()]);
        return Inertia::render('@parent/LiveSessions/Browse', [
            'sessions' => $sessions
        ]);
    }

    /**
     * Show the teacher control panel for a live session
     */
    public function teacherPanel($sessionId)
    {
        Log::info('[LiveLessonController] Loading teacher panel', ['session_id' => $sessionId]);

        $session = LiveLessonSession::with([
            'lesson.slides',
            'participants.child.user',
            'teacher'
        ])->findOrFail($sessionId);

        // Verify user is the teacher
        if ($session->teacher_id !== auth()->id()) {
            abort(403, 'You are not authorized to control this session');
        }

        // ✅ DEBUG: Log all participant data being sent to teacher panel
        $participantDebugData = $session->participants->map(function ($participant) {
            return [
                'participant_id' => $participant->id,
                'child_id' => $participant->child_id,
                'child_name (from child_name field)' => $participant->child->child_name ?? 'NULL',
                'parent_name (from user relation)' => $participant->child->user->name ?? 'NULL',
                'joined_at' => $participant->joined_at,
                'status' => $participant->status
            ];
        });

        Log::info('[LiveLessonController] ✅ Teacher panel loaded - PARTICIPANT DEBUG', [
            'session_id' => $sessionId,
            'lesson_id' => $session->content_lesson_id,
            'total_participants' => $session->participants->count(),
            'participants_debug' => $participantDebugData
        ]);

        return Inertia::render('@admin/Teacher/LiveLesson/TeacherPanel', [
            'session' => $session,
            'lesson' => $session->lesson,
            'participants' => $session->participants
                ->where('status', 'joined')
                ->whereIn('connection_status', ['connected', 'reconnecting'])
                ->values()
                ->map(function ($participant) {
                    // ✅ FIX: Use child_name field instead of user->name
                    $childName = $participant->child->child_name ?? $participant->child->user->name;
                    
                    Log::info('[LiveLessonController] ✅ Mapping participant for teacher panel', [
                        'participant_id' => $participant->id,
                        'child_id' => $participant->child_id,
                        'child_name (used)' => $childName,
                        'child_name_field' => $participant->child->child_name ?? 'NULL',
                        'parent_name' => $participant->child->user->name ?? 'NULL'
                    ]);
                    
                    return [
                        'id' => $participant->id,
                        'child_id' => $participant->child_id,
                        'user_id' => $participant->child->user->id, // ✅ Add user_id for LiveKit matching
                        'child_name' => $childName, // ✅ Use child's actual name, not parent's name
                        'joined_at' => $participant->joined_at,
                        'status' => $participant->status,
                        'connection_status' => $participant->connection_status,
                        'hand_raised' => $participant->hand_raised ?? false,
                        'hand_raised_at' => $participant->hand_raised_at
                    ];
                })

        ]);
    }

    /**
     * Change the current slide for all participants
     */
    public function changeSlide(Request $request, $sessionId)
    {
        $validated = $request->validate([
            'slide_id' => 'required|integer|exists:lesson_slides,id'
        ]);

        Log::info('[LiveLessonController] ✅ Teacher changing slide', [
            'session_id' => $sessionId,
            'slide_id' => $validated['slide_id'],
            'teacher_id' => auth()->id()
        ]);

        $session = LiveLessonSession::findOrFail($sessionId);

        // Update session current slide
        $session->update([
            'current_slide_id' => $validated['slide_id']
        ]);

        Log::info('[LiveLessonController] ✅ Broadcasting SlideChanged event', [
            'session_id' => $sessionId,
            'slide_id' => $validated['slide_id'],
            'channel' => "live-session.{$sessionId}",
            'event_name' => 'slide.changed'
        ]);

        // Broadcast to all participants
        broadcast(new SlideChanged(
            $session,
            $validated['slide_id'],
            auth()->id()
        ))->toOthers();

        Log::info('[LiveLessonController] ✅ Slide changed and broadcasted successfully');

        return response()->json([
            'success' => true,
            'current_slide_id' => $validated['slide_id']
        ]);
    }

    /**
     * Change session state (active, paused, ended)
     */
    public function changeState(Request $request, $sessionId)
    {
        $validated = $request->validate([
            'state' => 'required|in:live,paused,ended', // ✅ Fixed: Accept 'live' instead of 'active'
            'message' => 'nullable|string|max:255'
        ]);

        Log::info('[LiveLessonController] Changing session state', [
            'session_id' => $sessionId,
            'new_state' => $validated['state'],
            'message' => $validated['message'] ?? null
        ]);

        $session = LiveLessonSession::findOrFail($sessionId);

        $session->update([
            'status' => $validated['state']
        ]);

        // Broadcast state change
        broadcast(new SessionStateChanged(
            $session,
            $validated['state'],
            $validated['message'] ?? null
        ))->toOthers();

        Log::info('[LiveLessonController] Session state changed', [
            'session_id' => $sessionId,
            'new_state' => $validated['state']
        ]);

        return response()->json([
            'success' => true,
            'state' => $validated['state']
        ]);
    }

    /**
     * Highlight a specific block for all participants
     */
    public function highlightBlock(Request $request, $sessionId)
    {
        $validated = $request->validate([
            'slide_id' => 'required|integer',
            'block_id' => 'nullable|string',
            'highlighted' => 'required|boolean'
        ]);

        Log::info('[LiveLessonController] Highlighting block', [
            'session_id' => $sessionId,
            'slide_id' => $validated['slide_id'],
            'block_id' => $validated['block_id'],
            'highlighted' => $validated['highlighted']
        ]);

        $session = LiveLessonSession::findOrFail($sessionId);

        // Broadcast block highlight
        broadcast(new BlockHighlighted(
            $session,
            $validated['slide_id'],
            $validated['block_id'],
            $validated['highlighted']
        ))->toOthers();

        return response()->json(['success' => true]);
    }

    /**
     * Send annotation stroke data
     */
    public function sendAnnotation(Request $request, $sessionId)
    {
        $validated = $request->validate([
            'slide_id' => 'required|integer',
            'stroke_data' => 'required|array',
            'user_role' => 'required|in:teacher,student'
        ]);

        Log::info('[LiveLessonController] Sending annotation', [
            'session_id' => $sessionId,
            'slide_id' => $validated['slide_id'],
            'user_id' => auth()->id(),
            'role' => $validated['user_role']
        ]);

        $session = LiveLessonSession::findOrFail($sessionId);

        // Broadcast annotation stroke
        broadcast(new AnnotationStroke(
            $session,
            $validated['slide_id'],
            $validated['stroke_data'],
            auth()->id(),
            $validated['user_role']
        ))->toOthers();

        return response()->json(['success' => true]);
    }

    /**
     * Clear all annotations
     */
    public function clearAnnotations(Request $request, $sessionId)
    {
        $validated = $request->validate([
            'slide_id' => 'required|integer'
        ]);

        Log::info('[LiveLessonController] Clearing annotations', [
            'session_id' => $sessionId,
            'slide_id' => $validated['slide_id']
        ]);

        $session = LiveLessonSession::findOrFail($sessionId);

        // Broadcast annotation clear
        broadcast(new AnnotationClear(
            $session,
            $validated['slide_id'],
            auth()->id()
        ))->toOthers();

        return response()->json(['success' => true]);
    }

    /**
     * Toggle navigation lock for students
     */
    public function toggleNavigationLock(Request $request, $sessionId)
    {
        $validated = $request->validate([
            'locked' => 'required|boolean'
        ]);

        Log::info('[LiveLessonController] ✅ Toggling navigation lock', [
            'session_id' => $sessionId,
            'locked' => $validated['locked']
        ]);

        $session = LiveLessonSession::findOrFail($sessionId);

        $session->update([
            'navigation_locked' => $validated['locked']
        ]);

        // ✅ Refresh model to get updated value from database
        $session->refresh();

        Log::info('[LiveLessonController] ✅ Broadcasting SessionStateChanged with navigation lock', [
            'session_id' => $sessionId,
            'navigation_locked' => $session->navigation_locked, // ✅ Now has correct value
            'channel' => "live-session.{$sessionId}",
            'event_name' => 'session.state.changed'
        ]);

        // Broadcast state change with lock info
        broadcast(new SessionStateChanged(
            $session,
            $session->status,
            $validated['locked'] ? 'Navigation locked by teacher' : 'Navigation unlocked'
        ))->toOthers();

        Log::info('[LiveLessonController] ✅ Navigation lock broadcasted successfully');

        return response()->json([
            'success' => true,
            'navigation_locked' => $validated['locked']
        ]);
    }

    /**
     * Get current session participants with their status
     */
    public function getParticipants($sessionId)
    {
        Log::info('[LiveLessonController] Getting participants', ['session_id' => $sessionId]);

        $session = LiveLessonSession::with([
            'participants.child.user'
        ])->findOrFail($sessionId);

        $participants = $session->participants->map(function ($participant) {
            // ✅ FIX: Use child_name field instead of parent's name
            $childName = $participant->child->child_name ?? $participant->child->user->name;
            
            return [
                'id' => $participant->id,
                'child_id' => $participant->child_id,
                'user_id' => $participant->child->user->id, // ✅ Add user_id for LiveKit matching
                'child_name' => $childName, // ✅ Fixed: Use child's actual name, not parent's name
                'joined_at' => $participant->joined_at,
                'status' => $participant->status
            ];
        });

        return response()->json([
            'participants' => $participants
        ]);
    }

    /**
     * Get Agora RTC token for live session (for both teacher & students)
     * @deprecated Use getLiveKitToken instead
     */
    public function getAgoraToken($sessionId)
    {
        Log::info('[LiveLessonController] Getting Agora token', [
            'session_id' => $sessionId,
            'user_id' => auth()->id()
        ]);

        $session = LiveLessonSession::findOrFail($sessionId);
        $tokenService = app(\App\Services\AgoraTokenService::class);

        try {
            $tokenData = $tokenService->generateSessionToken(
                $session,
                auth()->user(),
                'publisher' // All users can publish audio
            );

            Log::info('[LiveLessonController] Agora token generated successfully');

            return response()->json($tokenData);
        } catch (\Exception $e) {
            Log::error('[LiveLessonController] Failed to generate Agora token', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to generate audio token. Please check Agora configuration.'
            ], 500);
        }
    }

    /**
     * Get LiveKit token for live session (for both teacher & students)
     */
    public function getLiveKitToken(Request $request, $sessionId)
    {
        Log::info('[LiveLessonController] Getting LiveKit token', [
            'session_id' => $sessionId,
            'user_id' => auth()->id(),
            'child_id_param' => $request->input('child_id')
        ]);

        $session = LiveLessonSession::findOrFail($sessionId);
        $tokenService = app(\App\Services\LiveKitTokenService::class);

        try {
            // Teachers and students can both publish audio
            $permissions = [
                'can_publish' => true,
                'can_subscribe' => true,
                'can_publish_data' => true,
            ];

            // Determine if user is teacher for this session
            $isTeacher = $session->teacher_id === auth()->id();

            // Add metadata to identify role
            $metadata = [
                'role' => $isTeacher ? 'teacher' : 'student',
                'name' => auth()->user()->name,
            ];
            
            // ✅ For students: Find which child is participating and set correct name
            if (!$isTeacher) {
                $childId = null;
                
                // Option 1: Use child_id from request parameter
                if ($request->has('child_id')) {
                    $childId = $request->input('child_id');
                    Log::info('[LiveLessonController] Using child_id from request', ['child_id' => $childId]);
                }
                // Option 2: Look up from participant record
                else {
                    $participant = LiveSessionParticipant::where('live_lesson_session_id', $sessionId)
                        ->whereHas('child', function($q) {
                            $q->where('user_id', auth()->id());
                        })
                        ->first();
                    
                    if ($participant) {
                        $childId = $participant->child_id;
                        Log::info('[LiveLessonController] Found child_id from participant', ['child_id' => $childId]);
                    }
                }
                
                // ✅ FIX: Set metadata.name to child's name instead of parent's name
                if (isset($childId)) {
                    $child = auth()->user()->children()->find($childId);
                    if ($child) {
                        $metadata['name'] = $child->child_name; // ✅ Use child's name for display
                        Log::info('[LiveLessonController] ✅ Setting metadata.name to child name', [
                            'child_id' => $childId,
                            'child_name' => $child->child_name,
                            'parent_name' => auth()->user()->name
                        ]);
                    }
                    $metadata['child_id'] = $childId;
                }
            }

            $tokenData = $tokenService->generateSessionToken(
                $session,
                auth()->user(),
                $permissions,
                $metadata
            );

            Log::info('[LiveLessonController] LiveKit token generated successfully', [
                'is_teacher' => $isTeacher,
                'metadata' => $metadata
            ]);

            return response()->json($tokenData);
        } catch (\Exception $e) {
            Log::error('[LiveLessonController] Failed to generate LiveKit token', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to generate audio token. Please check LiveKit configuration.'
            ], 500);
        }
    }

    /**
     * Student joins a live session
     */
    public function studentJoin(Request $request, $sessionId)
    {
        Log::info('[LiveLessonController] ✅ Student joining session', [
            'session_id' => $sessionId,
            'user_id' => auth()->id(),
            'child_id_param' => $request->input('child_id')
        ]);
        
        $session = LiveLessonSession::with([
            'contentLesson.slides'
        ])->findOrFail($sessionId);
        
        Log::info('[LiveLessonController] ✅ Session found', [
            'session_id' => $sessionId,
            'status' => $session->status,
            'lesson_id' => $session->content_lesson_id
        ]);
        
        // Verify session is live (not scheduled/completed/cancelled)
        if ($session->status !== 'live') {
            Log::warning('[LiveLessonController] ❌ Session not live, redirecting', [
                'session_id' => $sessionId,
                'current_status' => $session->status,
                'expected_status' => 'live'
            ]);
            
            return redirect()->route('parent.live-sessions.index')
                ->with('error', 'This live session is not currently active. Status: ' . $session->status);
        }

        // ✅ Multi-child support: Get all children for this user
        $children = auth()->user()->children;
        
        if ($children->isEmpty()) {
            Log::error('[LiveLessonController] ❌ No children found for user', [
                'user_id' => auth()->id()
            ]);
            
            return redirect()->route('parent.live-sessions.index')
                ->with('error', 'No child profile found. Please set up a child profile first.');
        }
        
        // ✅ If child_id provided, use that specific child
        if ($request->has('child_id')) {
            $child = $children->firstWhere('id', $request->input('child_id'));
            
            if (!$child) {
                Log::error('[LiveLessonController] ❌ Invalid child_id provided', [
                    'child_id' => $request->input('child_id'),
                    'available_children' => $children->pluck('id')
                ]);
                
                return redirect()->route('parent.live-sessions.index')
                    ->with('error', 'Invalid child selection.');
            }
        } 
        // ✅ If multiple children and no selection, show selection modal
        else if ($children->count() > 1) {
            Log::info('[LiveLessonController] ✅ Multiple children found, need selection', [
                'children_count' => $children->count()
            ]);
            
            // Pass children list to frontend for modal
            return Inertia::render('@parent/ContentLessons/LivePlayer', [
                'session' => $session,
                'lesson' => $session->contentLesson,
                'needsChildSelection' => true,
                'children' => $children->map(fn($child) => [
                    'id' => $child->id,
                    'name' => $child->child_name, // ✅ Use child's actual name, not parent's name
                    'age' => $child->age,
                ]),
                'progress' => null
            ]);
        }
        // ✅ Single child - use automatically
        else {
            $child = $children->first();
        }

        // ✅ Create/update participant record with selected child
        if ($child) {
            Log::info('[LiveLessonController] ✅ Creating/updating participant record', [
                'session_id' => $sessionId,
                'child_id' => $child->id,
                'child_name' => $child->child_name,
                'parent_name' => $child->user->name,
                'parent_id' => auth()->user()->id
            ]);

            $participant = LiveSessionParticipant::firstOrCreate([
                'live_lesson_session_id' => $sessionId,
                'child_id' => $child->id
            ], [
                'joined_at' => now(),
                'status' => 'joined',
                'connection_status' => 'connected'
            ]);

            // Update connection status if already existed
            if (!$participant->wasRecentlyCreated) {
                Log::info('[LiveLessonController] ⚠️ Participant already existed, updating status', [
                    'participant_id' => $participant->id,
                    'old_status' => $participant->status,
                    'old_connection' => $participant->connection_status
                ]);
                
                $participant->update([
                    'status' => 'joined',
                    'connection_status' => 'connected',
                    'joined_at' => now()
                ]);
            } else {
                Log::info('[LiveLessonController] ✅ New participant record created', [
                    'participant_id' => $participant->id
                ]);
            }

            // Broadcast participant joined event to teacher
            $participantData = [
                'id' => $participant->id,
                'child_id' => $child->id,
                'child_name' => $child->child_name, // ✅ Use child's actual name, not parent's name
                'joined_at' => $participant->joined_at->toIso8601String(),
                'status' => $participant->status,
                'connection_status' => $participant->connection_status,
                'hand_raised' => false,
                'hand_raised_at' => null
            ];

            Log::info('[LiveLessonController] ✅ Broadcasting ParticipantJoined event', [
                'session_id' => $sessionId,
                'participant_data' => $participantData,
                'channel' => "live-session.{$sessionId}"
            ]);

            broadcast(new \App\Events\ParticipantJoined(
                $sessionId,
                $participantData
            ))->toOthers();

            Log::info('[LiveLessonController] ✅ ParticipantJoined event broadcasted successfully');
        }

        Log::info('[LiveLessonController] ✅ Student joined successfully, passing data to frontend', [
            'session_id' => $session->id,
            'lesson_id' => $session->content_lesson_id,
            'slides_count' => $session->contentLesson?->slides?->count() ?? 0,
            'status' => $session->status,
            'selected_child_id' => $child->id ?? null
        ]);

        return Inertia::render('@parent/ContentLessons/LivePlayer', [
            'session' => $session,
            'lesson' => $session->contentLesson, // ✅ Fixed: Use contentLesson instead of lesson
            'selectedChildId' => $child->id ?? null, // ✅ Pass selected child ID to frontend
            'progress' => null // TODO: Get student progress
        ]);
    }

    /**
     * Student raises/lowers hand
     */
    public function raiseHand(Request $request, $sessionId)
    {
        $validated = $request->validate([
            'raised' => 'required|boolean'
        ]);

        Log::info('[LiveLessonController] Raise hand', [
            'session_id' => $sessionId,
            'user_id' => auth()->id(),
            'raised' => $validated['raised']
        ]);

        $session = LiveLessonSession::findOrFail($sessionId);

        // Better child lookup with error logging
        $child = auth()->user()->children()->first();

        if (!$child) {
            Log::error('[LiveLessonController] No child found for user', [
                'user_id' => auth()->id(),
                'user_email' => auth()->user()->email,
                'user_role' => auth()->user()->role
            ]);

            return response()->json([
                'error' => 'No child profile found',
                'message' => 'Please ensure your child profile is set up correctly'
            ], 400);
        }

        // Update participant hand status
        $participant = LiveSessionParticipant::where('live_lesson_session_id', $sessionId)
            ->where('child_id', $child->id)
            ->first();

        if ($participant) {
            $participant->update([
                'hand_raised' => $validated['raised'],
                'hand_raised_at' => $validated['raised'] ? now() : null
            ]);

        // Broadcast to teacher and other students
        // Temporarily removed ->toOthers() for debugging
        broadcast(new \App\Events\HandRaised(
            $session,
            $child->id,
            $child->user->name,
            $validated['raised']
        ));

            Log::info('[LiveLessonController] Hand status updated', [
                'participant_id' => $participant->id,
                'raised' => $validated['raised']
            ]);

            return response()->json([
                'success' => true,
                'hand_raised' => $validated['raised']
            ]);
        }

        return response()->json(['error' => 'Participant not found'], 404);
    }

    /**
     * Teacher lowers a student's hand
     */
    public function lowerHand($sessionId, $participantId)
    {
        Log::info('[LiveLessonController] Teacher lowering student hand', [
            'session_id' => $sessionId,
            'participant_id' => $participantId,
            'teacher_id' => auth()->id()
        ]);

        $session = LiveLessonSession::findOrFail($sessionId);
        $participant = LiveSessionParticipant::findOrFail($participantId);

        $participant->update([
            'hand_raised' => false,
            'hand_raised_at' => null
        ]);

        // Broadcast to all participants
        broadcast(new \App\Events\HandRaised(
            $session,
            $participant->child_id,
            $participant->child->user->name,
            false
        ))->toOthers();

        Log::info('[LiveLessonController] Student hand lowered by teacher');

        return response()->json(['success' => true]);
    }

    /**
     * Student sends a message/question
     */
    public function sendMessage(Request $request, $sessionId)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:1000',
            'type' => 'nullable|in:question,comment'
        ]);

        Log::info('[LiveLessonController] Student sending message', [
            'session_id' => $sessionId,
            'user_id' => auth()->id(),
            'type' => $validated['type'] ?? 'question'
        ]);

        $session = LiveLessonSession::findOrFail($sessionId);
        $child = auth()->user()->children()->first();

        if (!$child) {
            Log::error('[LiveLessonController] No child found for sendMessage', [
                'user_id' => auth()->id(),
                'user_email' => auth()->user()->email
            ]);

            return response()->json([
                'error' => 'No child profile found',
                'message' => 'Please ensure your child profile is set up correctly'
            ], 400);
        }

        // Create message
        $message = LiveSessionMessage::create([
            'live_session_id' => $sessionId,
            'child_id' => $child->id,
            'message' => $validated['message'],
            'type' => $validated['type'] ?? 'question',
            'is_answered' => false
        ]);

        // Broadcast to teacher and other students
        broadcast(new MessageSent(
            $session->id,
            $message->id,
            $child->id,
            $child->user->name,
            $validated['message'],
            $validated['type'] ?? 'question',
            false,
            null
        ))->toOthers();

        Log::info('[LiveLessonController] Message sent successfully', [
            'message_id' => $message->id
        ]);

        return response()->json([
            'success' => true,
            'message' => $message
        ]);
    }

    /**
     * Get all messages for a session
     */
    public function getMessages($sessionId)
    {
        Log::info('[LiveLessonController] Getting messages', ['session_id' => $sessionId]);

        $messages = LiveSessionMessage::where('live_session_id', $sessionId)
            ->with(['child.user', 'answeredBy'])
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($message) {
                return [
                    'id' => $message->id,
                    'child_id' => $message->child_id,
                    'student_name' => $message->child->user->name,
                    'message' => $message->message,
                    'type' => $message->type,
                    'is_answered' => $message->is_answered,
                    'answer' => $message->answer,
                    'answered_by' => $message->answeredBy ? $message->answeredBy->name : null,
                    'answered_at' => $message->answered_at,
                    'created_at' => $message->created_at
                ];
            });

        return response()->json([
            'messages' => $messages
        ]);
    }

    /**
     * Teacher answers a student's question
     */
    public function answerMessage(Request $request, $sessionId, $messageId)
    {
        $validated = $request->validate([
            'answer' => 'required|string|max:1000'
        ]);

        Log::info('[LiveLessonController] Teacher answering message', [
            'session_id' => $sessionId,
            'message_id' => $messageId,
            'teacher_id' => auth()->id()
        ]);

        $session = LiveLessonSession::findOrFail($sessionId);
        $message = LiveSessionMessage::findOrFail($messageId);

        // Update message with answer
        $message->update([
            'is_answered' => true,
            'answer' => $validated['answer'],
            'answered_by' => auth()->id(),
            'answered_at' => now()
        ]);

        // Broadcast answer to all participants
        broadcast(new MessageSent(
            $session->id,
            $message->id,
            $message->child_id,
            $message->child->user->name,
            $message->message,
            $message->type,
            true,
            $validated['answer']
        ))->toOthers();

        Log::info('[LiveLessonController] Message answered successfully');

        return response()->json([
            'success' => true,
            'message' => $message
        ]);
    }

    /**
     * Student leaves a live session
     */
    public function studentLeave($sessionId)
    {
        Log::info('[LiveLessonController] Student leaving session', [
            'session_id' => $sessionId,
            'user_id' => auth()->id()
        ]);

        $child = auth()->user()->children()->first();

        if (!$child) {
            Log::error('[LiveLessonController] No child found for studentLeave', [
                'user_id' => auth()->id(),
                'user_email' => auth()->user()->email
            ]);

            return response()->json([
                'error' => 'No child profile found',
                'message' => 'Please ensure your child profile is set up correctly'
            ], 400);
        }

        $participant = LiveSessionParticipant::where('live_lesson_session_id', $sessionId)
            ->where('child_id', $child->id)
            ->first();

        if ($participant) {
            $participant->update([
                'status' => 'left',
                'connection_status' => 'disconnected',
                'left_at' => now()
            ]);

            Log::info('[LiveLessonController] Student left successfully', [
                'participant_id' => $participant->id
            ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Send emoji reaction
     */
    public function sendReaction(Request $request, $sessionId)
    {
        $validated = $request->validate([
            'emoji' => 'required|string|max:10'
        ]);

        Log::info('[LiveLessonController] Sending emoji reaction', [
            'session_id' => $sessionId,
            'user_id' => auth()->id(),
            'emoji' => $validated['emoji']
        ]);

        $session = LiveLessonSession::findOrFail($sessionId);
        $userName = auth()->user()->name;

        // Broadcast emoji reaction to all participants
        broadcast(new EmojiReaction(
            $session->id,
            auth()->id(),
            $userName,
            $validated['emoji']
        ))->toOthers();

        Log::info('[LiveLessonController] Emoji reaction sent successfully');

        return response()->json([
            'success' => true
        ]);
    }

    /**
     * Mute/unmute a specific participant
     */
    public function muteParticipant(Request $request, $sessionId, $participantId)
    {
        $validated = $request->validate([
            'muted' => 'required|boolean'
        ]);

        Log::info('[LiveLessonController] Teacher muting participant', [
            'session_id' => $sessionId,
            'participant_id' => $participantId,
            'muted' => $validated['muted'],
            'teacher_id' => auth()->id()
        ]);

        $session = LiveLessonSession::findOrFail($sessionId);
        $participant = LiveSessionParticipant::findOrFail($participantId);

        // Broadcast mute event to all participants
        broadcast(new \App\Events\ParticipantMuted(
            $session->id,
            $participant->id,
            $participant->child_id,
            $validated['muted'],
            'teacher'
        ))->toOthers();

        Log::info('[LiveLessonController] Participant mute status changed', [
            'participant_id' => $participantId,
            'muted' => $validated['muted']
        ]);

        return response()->json([
            'success' => true,
            'muted' => $validated['muted']
        ]);
    }

    /**
     * Disable/enable a participant's camera
     */
    public function disableCamera(Request $request, $sessionId, $participantId)
    {
        $validated = $request->validate([
            'disabled' => 'required|boolean'
        ]);

        Log::info('[LiveLessonController] Teacher disabling participant camera', [
            'session_id' => $sessionId,
            'participant_id' => $participantId,
            'disabled' => $validated['disabled'],
            'teacher_id' => auth()->id()
        ]);

        $session = LiveLessonSession::findOrFail($sessionId);
        $participant = LiveSessionParticipant::findOrFail($participantId);

        // Broadcast camera disable event
        broadcast(new \App\Events\ParticipantCameraDisabled(
            $session->id,
            $participant->id,
            $participant->child_id,
            $validated['disabled'],
            'teacher'
        ))->toOthers();

        Log::info('[LiveLessonController] Participant camera status changed', [
            'participant_id' => $participantId,
            'disabled' => $validated['disabled']
        ]);

        return response()->json([
            'success' => true,
            'disabled' => $validated['disabled']
        ]);
    }

    /**
     * Mute all participants in the session
     */
    public function muteAll(Request $request, $sessionId)
    {
        $validated = $request->validate([
            'muted' => 'required|boolean'
        ]);

        Log::info('[LiveLessonController] Teacher muting all participants', [
            'session_id' => $sessionId,
            'muted' => $validated['muted'],
            'teacher_id' => auth()->id()
        ]);

        $session = LiveLessonSession::with('participants')->findOrFail($sessionId);

        // Broadcast mute event for each participant
        foreach ($session->participants as $participant) {
            broadcast(new \App\Events\ParticipantMuted(
                $session->id,
                $participant->id,
                $participant->child_id,
                $validated['muted'],
                'teacher'
            ))->toOthers();
        }

        Log::info('[LiveLessonController] All participants mute status changed', [
            'count' => $session->participants->count(),
            'muted' => $validated['muted']
        ]);

        return response()->json([
            'success' => true,
            'muted' => $validated['muted'],
            'count' => $session->participants->count()
        ]);
    }

    /**
     * Kick a participant from the session
     */
    public function kickParticipant(Request $request, $sessionId, $participantId)
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:255'
        ]);

        Log::info('[LiveLessonController] Teacher kicking participant', [
            'session_id' => $sessionId,
            'participant_id' => $participantId,
            'reason' => $validated['reason'] ?? 'No reason provided',
            'teacher_id' => auth()->id()
        ]);

        $session = LiveLessonSession::findOrFail($sessionId);
        $participant = LiveSessionParticipant::findOrFail($participantId);

        // Update participant status
        $participant->update([
            'status' => 'kicked',
            'connection_status' => 'disconnected',
            'left_at' => now()
        ]);

        // Broadcast kick event
        broadcast(new \App\Events\ParticipantKicked(
            $session->id,
            $participant->id,
            $participant->child_id,
            $validated['reason'] ?? 'Removed by teacher'
        ))->toOthers();

        Log::info('[LiveLessonController] Participant kicked successfully', [
            'participant_id' => $participantId
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Participant removed from session'
        ]);
    }
}
