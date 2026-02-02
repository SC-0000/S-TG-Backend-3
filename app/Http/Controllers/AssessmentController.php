<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAssessmentReportJob;
use App\Models\AdminTask;
use Illuminate\Http\Request;
use App\Models\Assessment;
use App\Models\AssessmentQuestion;
use App\Models\Child;
use App\Models\JourneyCategory;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AssessmentController extends Controller
{
    /**
     * Keep-alive endpoint for session extension during assessment creation.
     * Returns 200 OK for keep-alive pings.
     */
    public function keepAlive()
    {
        return response()->json(['ok' => true]);
    }
     public function browseIndex()
    {
        $assessments = Assessment::get();
     $services = Service::whereIn('_type', ['bundle', 'assessment'])
        ->with([
            'assessments',        // so the front-end still gets them
            'children:id'         // only need the id column here
        ])
        ->get()

        // 2️⃣  Attach a child_ids property to each item
        ->map(function ($svc) {
            $svc->child_ids = $svc->children          // collection of Child models
                ->pluck('id')                         // [1, 5, 9]
                ->map(fn ($id) => (string) $id)       // ["1","5","9"] – strings are easier in JS
                ->values();

            // (optional) remove the bulky children collection if the UI
            // doesn’t actually need the full child objects:
            unset($svc->children);

            return $svc;                              // return the modified object
        });

        return Inertia::render('@parent/Assessments/Browse', ['assessments' => $assessments,'services' => $services]);
    }

    public function portalIndex(Request $request)
    {
        $user = $request->user();

        // Trigger billing reconciliation when parent portal is accessed so that
        // recent payments are reconciled and access can be granted before the
        // user views assessments/lessons. Prefer per-customer sync where possible.
        try {
            if (! empty($user->billing_customer_id) && class_exists(\App\Jobs\SyncBillingInvoicesJob::class)) {
                \App\Jobs\SyncBillingInvoicesJob::dispatch($user->billing_customer_id);
                Log::info('portalIndex: dispatched SyncBillingInvoicesJob for user', [
                    'user_id' => $user->id ?? null,
                    'billing_customer_id' => $user->billing_customer_id,
                ]);
            } elseif (class_exists(\App\Jobs\SyncAllOpenOrders::class)) {
                // fallback: dispatch a global reconciliation job
                \App\Jobs\SyncAllOpenOrders::dispatch();
                Log::info('portalIndex: dispatched SyncAllOpenOrders for user', [
                    'user_id' => $user->id ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('portalIndex: failed to dispatch billing sync job', [
                'error' => $e->getMessage(),
                'user_id' => $user->id ?? null,
            ]);
        }

        // 1. Which children can this user see?
        if ($user->role === User::ROLE_ADMIN) {
            // admin can see all children
            $visibleChildIds = Child::pluck('id')->all();
        } elseif (in_array($user->role, [User::ROLE_PARENT, User::ROLE_GUEST_PARENT], true)) {
            // parents (including guest_parent) see only their children
            $visibleChildIds = $user->children()->pluck('id')->all();
        } else {
            // other roles: none by default
            $visibleChildIds = [];
        }

        // 2. Get all access records for these children, with access granted and paid
        $accessRecords = \App\Models\Access::whereIn('child_id', $visibleChildIds)
            ->where('access', true)
            ->where('payment_status', 'paid')
            ->get();

        // 3. Collect all lesson and assessment IDs per child
        $childLessonMap = [];
        $childAssessmentMap = [];
        foreach ($accessRecords as $access) {
            $cid = (string) $access->child_id;

            // Single lesson_id
            if ($access->lesson_id) {
                $childLessonMap[$cid][] = $access->lesson_id;
            }
            // Multiple lesson_ids (JSON)
            if ($access->lesson_ids) {
                foreach ((array) $access->lesson_ids as $lid) {
                    $childLessonMap[$cid][] = $lid;
                }
            }
            // Single assessment_id
            if ($access->assessment_id) {
                $childAssessmentMap[$cid][] = $access->assessment_id;
            }
            // Multiple assessment_ids (JSON)
            if ($access->assessment_ids) {
                foreach ((array) $access->assessment_ids as $aid) {
                    $childAssessmentMap[$cid][] = $aid;
                }
            }
        }

        // Flatten and deduplicate
        $allLessonIds = collect($childLessonMap)->flatten()->unique()->values();
        $allAssessmentIds = collect($childAssessmentMap)->flatten()->unique()->values();

        // 4. Fetch lessons and assessments
        $lessons = \App\Models\Lesson::whereIn('id', $allLessonIds)
            ->orderBy('start_time')
            ->get()
            ->map(function ($l) use ($childLessonMap) {
                // Find all children who have access to this lesson
                $allowed_child_ids = collect($childLessonMap)
                    ->filter(fn ($lessonIds) => in_array($l->id, $lessonIds))
                    ->keys()
                    ->values();
                $l->allowed_child_ids = $allowed_child_ids;
                return $l;
            });

        $assessments = \App\Models\Assessment::whereIn('id', $allAssessmentIds)
            ->orderBy('deadline')
            ->get()
            ->map(function ($a) use ($childAssessmentMap) {
                // Find all children who have access to this assessment
                $child_ids = collect($childAssessmentMap)
                    ->filter(fn ($assessmentIds) => in_array($a->id, $assessmentIds))
                    ->keys()
                    ->values();
                return [
                    'id'                => $a->id,
                    'title'             => $a->title,
                    'availability'      => $a->availability,
                    'deadline'          => $a->deadline,
                    'lesson'            => $a->lesson,
                    'child_ids'         => $child_ids,
                ];
            });

        // 5. Get submissions (unchanged)
        $submissions = \App\Models\AssessmentSubmission::with([
                'child:id,child_name,year_group',
                'assessment:id,title',
            ])
            ->whereHas('child', fn ($q) => $q->whereIn('id', $visibleChildIds))
            ->latest('finished_at')
            ->get()
            ->map(fn ($s) => [
                'id'            => $s->id,
                'child'         => $s->child,
                'assessment'    => $s->assessment,
                'marks_obtained'=> $s->marks_obtained,
                'total_marks'   => $s->total_marks,
                'status'        => $s->status,
                'retake_number' => $s->retake_number,
                'created_at'    => $s->created_at,
                'finished_at'   => $s->finished_at,
                'graded_at'     => $s->graded_at,
            ]);

        // 6. Get courses with access - handle both course_id AND course_ids (JSON array)
        $courseIds = collect();
        
        foreach ($accessRecords as $access) {
            // Handle single course_id column
            if ($access->course_id) {
                $courseIds->push($access->course_id);
            }
            // Handle course_ids JSON array
            if ($access->course_ids) {
                $courseIds = $courseIds->merge($access->course_ids);
            }
        }
        
        $courseIds = $courseIds->unique()->values();

        Log::info('AssessmentController::portalIndex - Course IDs from access records', [
            'courseIds' => $courseIds->toArray(),
            'access_records_count' => $accessRecords->count(),
        ]);

        $courses = \App\Models\Course::whereIn('id', $courseIds)
            ->with(['modules'])
            ->get();

        Log::info('AssessmentController::portalIndex - Courses fetched', [
            'courses_count' => $courses->count(),
            'course_ids_found' => $courses->pluck('id')->toArray(),
        ]);

        $courses = $courses->map(function ($course) use ($accessRecords) {
                // Find all children who have access to this course (check both course_id and course_ids)
                $child_ids = $accessRecords->filter(function($access) use ($course) {
                    // Check single course_id
                    if ($access->course_id == $course->id) {
                        return true;
                    }
                    // Check course_ids JSON array
                    if ($access->course_ids && in_array($course->id, $access->course_ids)) {
                        return true;
                    }
                    return false;
                })
                ->pluck('child_id')
                ->map(fn($id) => (string) $id)
                ->unique()
                ->values();

                Log::info('Processing course', [
                    'course_id' => $course->id,
                    'course_title' => $course->title,
                    'modules_count' => $course->modules->count(),
                    'child_ids' => $child_ids->toArray(),
                ]);

                $totalLessons = 0;
                $totalAssessments = 0;

                try {
                    $totalLessons = $course->modules->sum(function ($module) {
                        return $module->lessons()->count();
                    });
                    
                    $totalAssessments = $course->modules->sum(function ($module) {
                        return $module->assessments()->count();
                    }) + $course->assessments()->count();
                } catch (\Exception $e) {
                    Log::error('Error calculating course stats', [
                        'course_id' => $course->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'description' => $course->description,
                    'thumbnail' => $course->thumbnail,
                    'category' => $course->category,
                    'level' => $course->level,
                    'estimated_duration_minutes' => $course->estimated_duration_minutes,
                    'content_stats' => [
                        'lessons' => $totalLessons,
                        'assessments' => $totalAssessments,
                    ],
                    'child_ids' => $child_ids,
                ];
            });

        Log::info('AssessmentController::portalIndex - Final courses data', [
            'courses_count' => $courses->count(),
            'courses' => $courses->toArray(),
        ]);

        return Inertia::render('@parent/Assessments/Index', [
            'assessments'   => $assessments,
            'lessons'       => $lessons,
            'submissions'   => $submissions,
            'courses'       => $courses,
        ]);
    }
    public function index(Request $request)
    {
        $user = Auth::user();
        
        $query = Assessment::with(['lesson', 'organization']);
        
        // Organization filtering
        if ($user->role === 'super_admin') {
            // Super Admin: Can see all assessments, with optional organization filter
            if ($request->filled('organization_id')) {
                $query->where('organization_id', $request->organization_id);
            }
            // Otherwise show ALL assessments
        } else {
            // Admin/Teacher: include global + their organization's assessments
            $query->visibleToOrg($user->current_organization_id);
        }
        
        // Apply filters
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }
        
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('title', 'like', '%' . $request->search . '%')
                  ->orWhere('description', 'like', '%' . $request->search . '%');
            });
        }
        
        $paginator = $query->orderBy('created_at', 'desc')->paginate(15);
        
        // Transform assessments
        $paginator->getCollection()->transform(function ($assessment) use ($user) {
            return [
                'id' => $assessment->id,
                'title' => $assessment->title,
                'description' => $assessment->description,
                'type' => $assessment->type,
                'status' => $assessment->status,
                'availability' => $assessment->availability,
                'deadline' => $assessment->deadline,
                'time_limit' => $assessment->time_limit,
                'retake_allowed' => $assessment->retake_allowed,
                'lesson' => $assessment->lesson ? [
                    'id' => $assessment->lesson->id,
                    'title' => $assessment->lesson->title,
                ] : null,
                'organization' => $assessment->organization ? [
                    'id' => $assessment->organization->id,
                    'name' => $assessment->organization->name,
                ] : null,
                'created_at' => $assessment->created_at?->format('M d, Y'),
            ];
        });
        
        // Get organizations list ONLY for super admin
        $organizations = $user->role === 'super_admin' 
            ? \App\Models\Organization::select('id', 'name')->orderBy('name')->get()
            : null;
        
        return Inertia::render('@admin/Assessments/Index', [
            'assessments' => $paginator,
            'organizations' => $organizations,
            'filters' => $request->only(['status', 'search', 'organization_id', 'type']),
        ]);
    }

    /**
     * Teacher's assessment index - shows only assessments created by this teacher
     */
    public function teacherIndex(Request $request)
    {
        $user = Auth::user();
        
        $query = Assessment::with(['lesson', 'organization']);
        
        // Teacher: include global + their organization's assessments
        $query->visibleToOrg($user->current_organization_id);
        
        // Apply filters
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }
        
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('title', 'like', '%' . $request->search . '%')
                  ->orWhere('description', 'like', '%' . $request->search . '%');
            });
        }
        
        $paginator = $query->orderBy('created_at', 'desc')->paginate(15);
        
        // Transform assessments
        $paginator->getCollection()->transform(function ($assessment) {
            return [
                'id' => $assessment->id,
                'title' => $assessment->title,
                'description' => $assessment->description,
                'type' => $assessment->type,
                'status' => $assessment->status,
                'availability' => $assessment->availability,
                'deadline' => $assessment->deadline,
                'time_limit' => $assessment->time_limit,
                'retake_allowed' => $assessment->retake_allowed,
                'lesson' => $assessment->lesson ? [
                    'id' => $assessment->lesson->id,
                    'title' => $assessment->lesson->title,
                ] : null,
                'created_at' => $assessment->created_at?->format('M d, Y'),
            ];
        });
        
        return Inertia::render('@admin/Assessments/Index', [
            'assessments' => $paginator,
            'organizations' => null,
            'filters' => $request->only(['status', 'search', 'type']),
        ]);
    }

    public function create()
    {   
        $user = auth()->user();
        $orgId = $user?->role === 'super_admin' ? null : $user?->current_organization_id;
        $journeyCategories = JourneyCategory::with('journey')
            ->when($orgId, fn($q) => $q->forOrganization($orgId))
            ->get();
        $organizations = $user?->role === 'super_admin'
            ? \App\Models\Organization::select('id', 'name')->orderBy('name')->get()
            : null;
        return Inertia::render('@admin/Assessments/Create',[
            'journeyCategories' => $journeyCategories,
            'organizations' => $organizations,
        ]);
    }

    public function store(Request $request)
    {
        Log::info('Assessment store method called', [
            'user_id' => Auth::id(),
            'request_data_keys' => array_keys($request->all()),
            'has_questions' => $request->has('questions'),
            'questions_count' => is_array($request->questions) ? count($request->questions) : 0,
            'request_all' => $request->all(),
        ]);

        try {
            $validatedData = $request->validate([
                'title'           => 'required|string|max:255',
                'year_group'      => 'nullable|string|max:50',
                'description'     => 'nullable|string',
                'lesson_id'       => 'nullable|integer|exists:live_sessions,id',
                'type'            => 'required|in:mcq,short_answer,essay,mixed',
                'status'          => 'required|in:active,inactive,archived',
                'journey_category_id' => 'nullable|integer|exists:journey_categories,id',
                'availability'    => 'required|date',
                'deadline'        => 'required|date|after:availability',
                'time_limit'      => 'nullable|integer',
                'retake_allowed'  => 'boolean',
                // Nested questions:
                'questions'                    => 'nullable|array',
                'questions.*.question_text'    => 'required_with:questions|string',
                'questions.*.question_image'   => 'nullable|image|max:40960',
                'questions.*.type'             => 'required_with:questions|in:mcq,short_answer,essay,matching,cloze,ordering,image_grid_mcq',
                'questions.*.options'          => 'nullable|array',
                'questions.*.correct_answer'   => 'nullable',
                'questions.*.marks'            => 'required_with:questions|integer',
                'questions.*.category'         => 'nullable|string|max:100',
                'questions.*.question_bank_id' => 'nullable|integer|exists:questions,id',
                'is_global' => 'nullable|boolean',
                'organization_id' => 'nullable|integer|exists:organizations,id',
            ]);

            Log::info('Validation passed', [
                'validated_data_keys' => array_keys($validatedData),
                'questions_count' => isset($validatedData['questions']) ? count($validatedData['questions']) : 0
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation failed', [
                'errors' => $e->errors(),
                'request_data' => $request->all()
            ]);
            throw $e;
        }

        $questionsInput = $validatedData['questions'] ?? [];
        unset($validatedData['questions']);

        Log::info('Processing questions', [
            'questions_input_count' => count($questionsInput),
            'questions_sample' => array_slice($questionsInput, 0, 2) // Log first 2 for debugging
        ]);

        // Separate inline questions from bank question references
        $questionsJson = [];
        $bankQuestionIds = [];

        foreach ($questionsInput as $index => $qData) {
            Log::info("Processing question {$index}", [
                'question_text' => substr($qData['question_text'] ?? '', 0, 50),
                'type' => $qData['type'] ?? 'unknown',
                'marks' => $qData['marks'] ?? 'unknown',
                'question_bank_id' => $qData['question_bank_id'] ?? null
            ]);

            // If this is a reference to a bank question, handle differently
            if (!empty($qData['question_bank_id'])) {
                $bankQuestionIds[] = [
                    'question_id' => $qData['question_bank_id'],
                    'order_position' => $index + 1,
                    'custom_points' => $qData['marks'] ?? null
                ];
                Log::info("Added bank question {$index}", [
                    'question_id' => $qData['question_bank_id'],
                    'order_position' => $index + 1
                ]);
            } else {
                // This is an inline question
                $entry = [
                    'question_text'   => $qData['question_text'],
                    'type'            => $qData['type'],
                    'options'         => $qData['options'] ?? null,
                    'correct_answer'  => $qData['correct_answer'] ?? null,
                    'marks'           => $qData['marks'],
                    'category'        => $qData['category'] ?? null,
                    'question_image'  => null,
                ];

                // Handle question_image upload
                if (!empty($qData['question_image']) && $qData['question_image'] instanceof \Illuminate\Http\UploadedFile) {
                    $path = $qData['question_image']
                            ->store('assessment_questions', 'public');
                    $entry['question_image'] = $path;
                    Log::info("Image uploaded for question {$index}", ['path' => $path]);
                }

                $questionsJson[] = $entry;
                Log::info("Added inline question {$index}", ['entry' => $entry]);
            }
        }

        // Now create the assessment, injecting our questions JSON
        $validatedData['questions_json'] = $questionsJson;
        
        $user = Auth::user();
        $isSuperAdmin = $user?->role === 'super_admin';
        $isGlobal = $isSuperAdmin && $request->boolean('is_global');
        $organizationId = $request->input('organization_id');

        if (!$isSuperAdmin) {
            $organizationId = $user?->current_organization_id;
            $isGlobal = false;
        }

        if ($isGlobal) {
            $organizationId = null;
        }

        if ($isSuperAdmin && !$isGlobal && !$organizationId) {
            throw ValidationException::withMessages([
                'organization_id' => 'Organization is required unless the assessment is global.',
            ]);
        }

        $validatedData['organization_id'] = $organizationId;
        $validatedData['is_global'] = $isGlobal;

        Log::info('Creating assessment', [
            'final_data_keys' => array_keys($validatedData),
            'questions_json_count' => count($questionsJson)
        ]);
        
        try {
            $assessment = Assessment::create($validatedData);
            Log::info('Assessment created successfully', [
                'assessment_id' => $assessment->id,
                'title' => $assessment->title
            ]);

            // Attach bank questions if any
            if (!empty($bankQuestionIds)) {
                $pivotData = [];
                foreach ($bankQuestionIds as $bankQuestion) {
                    $pivotData[$bankQuestion['question_id']] = [
                        'order_position' => $bankQuestion['order_position'],
                        'custom_points' => $bankQuestion['custom_points'],
                        'custom_settings' => null,
                    ];
                }
                
                $assessment->bankQuestions()->sync($pivotData);
                Log::info('Bank questions attached successfully', [
                    'assessment_id' => $assessment->id,
                    'bank_questions_count' => count($bankQuestionIds)
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to create assessment', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }

        Log::info('Redirecting to assessments.index');
        
        // Determine route based on user role
        $user = Auth::user();
        $indexRoute = ($user->role === 'teacher') ? 'teacher.assessments.index' : 'assessments.index';
        
        return redirect()->route($indexRoute)->with('success', 'Assessment created successfully!');
    }

    public function edit($id)
    {
        $user = auth()->user();
        $orgId = $user?->role === 'super_admin' ? null : $user?->current_organization_id;
        $journeyCategories = JourneyCategory::with('journey')
            ->when($orgId, fn($q) => $q->forOrganization($orgId))
            ->get();
        $assessment = Assessment::findOrFail($id);
        
        // Authorization check for teachers
        $user = Auth::user();
        if ($user->role === 'teacher') {
            if (!$user->current_organization_id || $assessment->organization_id !== $user->current_organization_id) {
                abort(403, 'You do not have permission to edit this assessment.');
            }
        }
        
        // Get all questions (both inline and bank questions)
        $allQuestions = $assessment->getAllQuestions();
        
        Log::info('Assessment edit - loading questions', [
            'assessment_id' => $assessment->id,
            'inline_questions_count' => count($assessment->questions_json ?? []),
            'bank_questions_count' => $assessment->bankQuestions()->count(),
            'total_questions' => count($allQuestions)
        ]);
        
        $organizations = $user?->role === 'super_admin'
            ? \App\Models\Organization::select('id', 'name')->orderBy('name')->get()
            : null;

        return Inertia::render('@admin/Assessments/Edit', [
            'assessment' => [
                'id'             => $assessment->id,
                'title'          => $assessment->title,
                'description'    => $assessment->description,
                'lesson_id'      => $assessment->lesson_id,
                'type'           => $assessment->type,
                'journey_category_id' => $assessment->journey_category_id,
                'year_group'     => $assessment->year_group,
                'status'         => $assessment->status,
                'availability'   => $assessment->availability,
                'deadline'       => $assessment->deadline,
                'time_limit'     => $assessment->time_limit,
                'retake_allowed' => $assessment->retake_allowed,
                'questions'      => $allQuestions, // All questions (inline + bank)
                'organization_id' => $assessment->organization_id,
                'is_global' => (bool) $assessment->is_global,
            ],
            'journeyCategories' => $journeyCategories,
            'organizations' => $organizations,
        ]);
    }

    public function update(Request $request, $id)
    {
        $assessment = Assessment::findOrFail($id);
        
        // Authorization check for teachers
        $user = Auth::user();
        if ($user->role === 'teacher') {
            if (!$user->current_organization_id || $assessment->organization_id !== $user->current_organization_id) {
                abort(403, 'You do not have permission to update this assessment.');
            }
        }

        Log::info('Assessment update method called', [
            'assessment_id' => $id,
            'user_id' => Auth::id(),
            'request_data_keys' => array_keys($request->all()),
            'has_questions' => $request->has('questions'),
            'questions_count' => is_array($request->questions) ? count($request->questions) : 0,
        ]);

        try {
            $validatedData = $request->validate([
                'title'           => 'required|string|max:255',
                'year_group'      => 'nullable|string|max:50',
                'description'     => 'nullable|string',
                'lesson_id'       => 'nullable|integer|exists:live_sessions,id',
                'type'            => 'required|in:mcq,short_answer,essay,mixed',
                'status'          => 'required|in:active,inactive,archived',
                'journey_category_id' => 'nullable|integer|exists:journey_categories,id',
                'availability'    => 'required|date',
                'deadline'        => 'required|date|after:availability',
                'time_limit'      => 'nullable|integer',
                'retake_allowed'  => 'boolean',
                // Nested questions:
                'questions'                    => 'nullable|array',
                'questions.*.question_text'    => 'required_with:questions|string',
                'questions.*.question_image'   => 'nullable|image|max:40960',
                'questions.*.type'             => 'required_with:questions|in:mcq,short_answer,essay,matching,cloze,ordering,image_grid_mcq',
                'questions.*.options'          => 'nullable|array',
                'questions.*.correct_answer'   => 'nullable',
                'questions.*.marks'            => 'required_with:questions|integer',
                'questions.*.category'         => 'nullable|string|max:100',
                'questions.*.question_bank_id' => 'nullable|integer|exists:questions,id',
                'is_global' => 'nullable|boolean',
                'organization_id' => 'nullable|integer|exists:organizations,id',
            ]);

            Log::info('Validation passed', [
                'validated_data_keys' => array_keys($validatedData),
                'questions_count' => isset($validatedData['questions']) ? count($validatedData['questions']) : 0
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation failed', [
                'errors' => $e->errors(),
                'request_data' => $request->all()
            ]);
            throw $e;
        }

        $questionsInput = $validatedData['questions'] ?? [];
        unset($validatedData['questions']);

        Log::info('Processing questions for update', [
            'questions_input_count' => count($questionsInput),
            'questions_sample' => array_slice($questionsInput, 0, 2)
        ]);

        // Separate inline questions from bank question references
        $questionsJson = [];
        $bankQuestionIds = [];

        foreach ($questionsInput as $index => $qData) {
            Log::info("Processing question {$index}", [
                'question_text' => substr($qData['question_text'] ?? '', 0, 50),
                'type' => $qData['type'] ?? 'unknown',
                'marks' => $qData['marks'] ?? 'unknown',
                'question_bank_id' => $qData['question_bank_id'] ?? null
            ]);

            // If this is a reference to a bank question, handle differently
            if (!empty($qData['question_bank_id'])) {
                $bankQuestionIds[] = [
                    'question_id' => $qData['question_bank_id'],
                    'order_position' => $index + 1,
                    'custom_points' => $qData['marks'] ?? null
                ];
                Log::info("Added bank question {$index}", [
                    'question_id' => $qData['question_bank_id'],
                    'order_position' => $index + 1
                ]);
            } else {
                // This is an inline question
                $entry = [
                    'question_text'   => $qData['question_text'],
                    'type'            => $qData['type'],
                    'options'         => $qData['options'] ?? null,
                    'correct_answer'  => $qData['correct_answer'] ?? null,
                    'marks'           => $qData['marks'],
                    'category'        => $qData['category'] ?? null,
                    'question_image'  => null,
                ];

                // Handle question_image upload
                if (!empty($qData['question_image']) && $qData['question_image'] instanceof \Illuminate\Http\UploadedFile) {
                    $path = $qData['question_image']->store('assessment_questions', 'public');
                    $entry['question_image'] = $path;
                    Log::info("Image uploaded for question {$index}", ['path' => $path]);
                }

                $questionsJson[] = $entry;
                Log::info("Added inline question {$index}", ['entry' => $entry]);
            }
        }

        // Update assessment's main fields + questions_json
        $validatedData['questions_json'] = $questionsJson;

        $isSuperAdmin = $user?->role === 'super_admin';
        $isGlobal = $isSuperAdmin && $request->boolean('is_global');
        $organizationId = $request->input('organization_id');

        if (!$isSuperAdmin) {
            $organizationId = $user?->current_organization_id;
            $isGlobal = false;
        }

        if ($isGlobal) {
            $organizationId = null;
        }

        if ($isSuperAdmin && !$isGlobal && !$organizationId) {
            throw ValidationException::withMessages([
                'organization_id' => 'Organization is required unless the assessment is global.',
            ]);
        }

        $validatedData['organization_id'] = $organizationId;
        $validatedData['is_global'] = $isGlobal;
        
        Log::info('Updating assessment', [
            'final_data_keys' => array_keys($validatedData),
            'questions_json_count' => count($questionsJson),
            'bank_questions_count' => count($bankQuestionIds)
        ]);
        
        try {
            $assessment->update($validatedData);
            Log::info('Assessment updated successfully', [
                'assessment_id' => $assessment->id,
                'title' => $assessment->title
            ]);

            // Sync bank questions (replace existing with new selection)
            if (!empty($bankQuestionIds)) {
                $pivotData = [];
                foreach ($bankQuestionIds as $bankQuestion) {
                    $pivotData[$bankQuestion['question_id']] = [
                        'order_position' => $bankQuestion['order_position'],
                        'custom_points' => $bankQuestion['custom_points'],
                        'custom_settings' => null,
                    ];
                }
                
                $assessment->bankQuestions()->sync($pivotData);
                Log::info('Bank questions synced successfully', [
                    'assessment_id' => $assessment->id,
                    'bank_questions_count' => count($bankQuestionIds)
                ]);
            } else {
                // No bank questions in update - detach all existing bank questions
                $assessment->bankQuestions()->detach();
                Log::info('All bank questions detached (none in update)', [
                    'assessment_id' => $assessment->id
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to update assessment', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }

        Log::info('Redirecting to assessments.show');
        
        // Determine route based on user role
        $showRoute = ($user->role === 'teacher') ? 'teacher.assessments.show' : 'assessments.show';
        
        return redirect()->route($showRoute, $assessment->id)
            ->with('success', 'Assessment updated successfully!');
    }

    public function destroy($id)
    {
        $assessment = Assessment::findOrFail($id);
        
        // Authorization check for teachers
        $user = Auth::user();
        if ($user->role === 'teacher') {
            if (!$user->current_organization_id || $assessment->organization_id !== $user->current_organization_id) {
                abort(403, 'You do not have permission to delete this assessment.');
            }
        }
        
        $assessment->delete();
        
        // Determine route based on user role
        $indexRoute = ($user->role === 'teacher') ? 'teacher.assessments.index' : 'assessments.index';
        
        return redirect()->route($indexRoute)->with('success', 'Assessment deleted successfully!');
    }

    public function show($id)
    {
        $assessment = Assessment::findOrFail($id);
        
        // Authorization check for teachers
        $user = Auth::user();
        if ($user->role === 'teacher') {
            if (!$user->current_organization_id || $assessment->organization_id !== $user->current_organization_id) {
                abort(403, 'You do not have permission to view this assessment.');
            }
        }
        
        $allQuestions = $assessment->getAllQuestions();

        return Inertia::render('@admin/Assessments/Show', [
            'assessment' => [
        'id'             => $assessment->id,
        'title'          => $assessment->title,
        'description'    => $assessment->description,
        'lesson_id'      => $assessment->lesson_id,
        'type'           => $assessment->type,
        'status'         => $assessment->status,
        'availability'   => $assessment->availability,
        'deadline'       => $assessment->deadline,
        'time_limit'     => $assessment->time_limit,
        'retake_allowed' => $assessment->retake_allowed,
        'questions_json' => $allQuestions, // pass array to front-end
    ],
        ]);
    }
    public function attempt(Request $request, $id)
    {
        $assessment = Assessment::findOrFail($id);

        $user = Auth::user();

        // Trigger billing reconciliation when an assessment page is accessed so that
        // any recent payments are reconciled and access is available immediately.
        try {
            if (! empty($user->billing_customer_id) && class_exists(\App\Jobs\SyncBillingInvoicesJob::class)) {
                \App\Jobs\SyncBillingInvoicesJob::dispatch($user->billing_customer_id);
                Log::info('attempt: dispatched SyncBillingInvoicesJob for user', [
                    'user_id' => $user->id ?? null,
                    'billing_customer_id' => $user->billing_customer_id,
                    'assessment_id' => $assessment->id ?? null,
                ]);
            } elseif (class_exists(\App\Jobs\SyncAllOpenOrders::class)) {
                \App\Jobs\SyncAllOpenOrders::dispatch();
                Log::info('attempt: dispatched SyncAllOpenOrders for user', [
                    'user_id' => $user->id ?? null,
                    'assessment_id' => $assessment->id ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('attempt: failed to dispatch billing sync job', [
                'error' => $e->getMessage(),
                'user_id' => $user->id ?? null,
                'assessment_id' => $assessment->id ?? null,
            ]);
        }

        // Determine which children to check (admin: all, parent: own)
        if ($user->role === 'admin') {
            $childQuery = Child::query();
        } else {
            $childQuery = Child::where('user_id', $user->id);
        }

        // Get children who have access to this assessment (via access table)
        $children = $childQuery
            ->whereIn('id', function ($query) use ($assessment) {
                $query->select('child_id')
                    ->from('access')
                    ->where('assessment_id', $assessment->id)
                    ->where('access', true)
                    ->where('payment_status', 'paid');
            })
            ->withCount([
                'assessmentSubmissions as attempts_so_far' => function ($q) use ($assessment, $user) {
                    $q->where('assessment_id', $assessment->id);
                    if ($user->role !== 'admin') {
                        $q->where('user_id', $user->id);
                    }
                }
            ])
            ->get();

        $startToken = now()->toIso8601String();
        if (!$assessment->retake_allowed) {
            $already = $assessment->submissions()
                    ->where('child_id', $request->input('child_id'))
                    ->count();

            if ($already >= 1) {
                return back()->with(
                    'error',
                    'This assessment can only be taken once. Retake not allowed.'
                );
            }
        }

        // expose whether this is a guest_parent and whether onboarding is complete
        $isGuestParent = ($user->role ?? '') === User::ROLE_GUEST_PARENT;
        $onboardingComplete = $user->onboarding_complete ?? false;

        // Get all questions (both inline and bank questions)
        $allQuestions = $assessment->getAllQuestions();
        
        return Inertia::render('@parent/Assessments/Attempt', [
            'assessment' => [
                'id'             => $assessment->id,
                'title'          => $assessment->title,
                'type'           => $assessment->type,
                'time_limit'     => $assessment->time_limit,
                'questions_json' => $allQuestions, // Use combined questions
                'startToken'     => $startToken,
                'retake_allowed' => $assessment->retake_allowed,
            ],
            'children' => $children,
            'startToken' => $startToken, // also send at root for validation
            'showTutorWidget' => false,
            'isGuestParent' => $isGuestParent,
            'onboardingComplete' => $onboardingComplete,
        ]);
    }

 
   
public function attemptSubmit(Request $request, $id)
{
    $assessment = Assessment::findOrFail($id);
    $gradingService = app(\App\Services\SubmissionGradingService::class);

    Log::info('=== ASSESSMENT SUBMISSION STARTED ===', [
        'assessment_id' => $assessment->id,
        'assessment_title' => $assessment->title,
        'user_id' => Auth::id(),
        'timestamp' => now()->toDateTimeString()
    ]);

    $payload = $request->validate([
        'child_id'    => 'required|exists:children,id',
        'answers'     => 'required|array',
        'started_at'  => 'required|date',
    ]);

    Log::info('📝 INCOMING SUBMISSION DATA:', [
        'child_id' => $payload['child_id'],
        'started_at' => $payload['started_at'],
        'answers_received' => count($payload['answers']),
        'raw_answers' => $payload['answers']
    ]);

    // Get assessment's bank questions directly
    $bankQuestions = $assessment->bankQuestions()->orderBy('order_position')->get();
    
    Log::info('🏦 ASSESSMENT BANK QUESTIONS LOADED:', [
        'total_questions' => $bankQuestions->count(),
        'questions_summary' => $bankQuestions->map(function($q, $index) {
            return [
                'index' => $index,
                'id' => $q->id,
                'title' => $q->title,
                'type' => $q->question_type,
                'marks' => $q->marks,
                'category' => $q->category,
                'subcategory' => $q->subcategory
            ];
        })->toArray()
    ]);
    
    $totalMarks = 0;
    $obtainedMarks = 0;
    $needsManual = false;
    $submissionItems = [];

    Log::info('🎯 STARTING QUESTION-BY-QUESTION GRADING...');

    foreach ($bankQuestions as $index => $question) {
        Log::info("", []); // Empty line for readability
        Log::info("🔍 GRADING QUESTION #{$index}", [
            'question_id' => $question->id,
            'question_title' => $question->title,
            'question_type' => $question->question_type,
            'question_marks' => $question->marks,
            'question_data' => $question->question_data,
            'answer_schema' => $question->answer_schema
        ]);

        $questionMarks = (int) $question->marks; // Cast to int for database
        $totalMarks += $questionMarks;
        
        // Get student answer for this question index
        $studentAnswer = $payload['answers'][$index] ?? [];
        
        // Ensure student answer is array format
        if (!is_array($studentAnswer)) {
            $studentAnswer = ['response' => $studentAnswer];
        }
        
        Log::info("📋 STUDENT ANSWER FOR QUESTION #{$index}:", [
            'raw_answer' => $payload['answers'][$index] ?? 'NO_ANSWER',
            'formatted_answer' => $studentAnswer,
            'answer_type' => gettype($studentAnswer),
            'answer_keys' => is_array($studentAnswer) ? array_keys($studentAnswer) : 'N/A'
        ]);
        
        Log::info("⚙️ CALLING GRADING SERVICE FOR QUESTION #{$index}...");
        
        // Grade the question
        $gradingResult = $gradingService->gradeQuestionResponse($question, $studentAnswer, $index);
        
        Log::info("✅ GRADING RESULT FOR QUESTION #{$index}:", [
            'question_id' => $question->id,
            'grading_result' => $gradingResult,
            'is_auto_graded' => $gradingResult['grading_metadata']['auto_graded'] ?? false,
            'requires_manual_review' => $gradingResult['grading_metadata']['requires_human_review'] ?? false,
            'marks_awarded' => $gradingResult['marks_awarded'],
            'is_correct' => $gradingResult['is_correct']
        ]);
        
        // Update totals - convert to int for database
        $marksEarned = (int) round($gradingResult['marks_awarded']);
        $obtainedMarks += $marksEarned;
        
        Log::info("🏆 MARKS UPDATE FOR QUESTION #{$index}:", [
            'question_marks' => $questionMarks,
            'marks_awarded_decimal' => $gradingResult['marks_awarded'],
            'marks_awarded_rounded' => $marksEarned,
            'running_total' => $obtainedMarks,
            'running_possible' => $totalMarks
        ]);
        
        // Check if manual review is needed
        if ($gradingResult['grading_metadata']['requires_human_review'] ?? false) {
            $needsManual = true;
            Log::info("⚠️ QUESTION #{$index} REQUIRES MANUAL REVIEW", [
                'reason' => $gradingResult['grading_metadata']['manual_reason'] ?? 'Unknown',
                'grading_method' => $gradingResult['grading_metadata']['grading_method'] ?? 'Unknown'
            ]);
        }
        
        $submissionItems[] = $gradingResult;
        
        Log::info("✔️ QUESTION #{$index} PROCESSING COMPLETE");
    }

    // Determine status based on enum values
    $status = $needsManual ? 'pending' : 'graded';

    Log::info('📊 FINAL GRADING SUMMARY:', [
        'total_questions' => count($bankQuestions),
        'total_possible_marks' => $totalMarks,
        'total_obtained_marks' => $obtainedMarks,
        'percentage' => $totalMarks > 0 ? round(($obtainedMarks / $totalMarks) * 100, 2) : 0,
        'status' => $status,
        'needs_manual_grading' => $needsManual,
        'auto_graded_questions' => collect($submissionItems)->where('grading_metadata.auto_graded', true)->count(),
        'manual_review_questions' => collect($submissionItems)->where('grading_metadata.requires_human_review', true)->count(),
        'submission_items_count' => count($submissionItems)
    ]);

    Log::info('💾 CREATING SUBMISSION RECORD...');

    $submission = DB::transaction(function () use (
        $assessment, $payload, $totalMarks, $obtainedMarks, $status, $submissionItems
    ) {
        Log::info('🗂️ CREATING MAIN SUBMISSION RECORD:', [
            'user_id' => Auth::id(),
            'child_id' => (int) $payload['child_id'],
            'assessment_id' => $assessment->id,
            'total_marks' => $totalMarks,
            'marks_obtained' => $obtainedMarks,
            'status' => $status
        ]);

        // Create main submission record
        $submission = $assessment->submissions()->create([
            'user_id'        => Auth::id(),
            'child_id'       => (int) $payload['child_id'],
            'retake_number'  => $assessment->submissions()
                                   ->where('child_id', $payload['child_id'])
                                   ->count() + 1,
            'total_marks'    => $totalMarks,    // int unsigned
            'marks_obtained' => $obtainedMarks, // int unsigned  
            'status'         => $status,        // ENUM('pending','graded','late')
            'started_at'     => Carbon::parse($payload['started_at']),
            'finished_at'    => now(),
            'answers_json'   => $submissionItems, // Store detailed data
        ]);

        Log::info('✅ MAIN SUBMISSION RECORD CREATED:', [
            'submission_id' => $submission->id,
            'retake_number' => $submission->retake_number
        ]);

        Log::info('📋 CREATING INDIVIDUAL SUBMISSION ITEMS...');

        // Create submission items according to database schema
        foreach ($submissionItems as $itemIndex => $itemData) {
            Log::info("💾 Creating submission item #{$itemIndex}:", [
                'question_type' => $itemData['question_type'],
                'bank_question_id' => $itemData['bank_question_id'],
                'marks_awarded' => $itemData['marks_awarded'],
                'is_correct' => $itemData['is_correct'],
                'auto_graded' => $itemData['grading_metadata']['auto_graded'] ?? false
            ]);

            $submissionItem = $submission->items()->create([
                // Database schema fields
                'question_id' => null, // Legacy field, nullable
                'question_type' => $itemData['question_type'], // ENUM('bank','inline')
                'bank_question_id' => $itemData['bank_question_id'], // For bank questions
                'inline_question_index' => $itemData['inline_question_index'], // NULL for bank questions
                'question_data' => $itemData['question_data'], // JSON
                'answer' => $itemData['answer'], // JSON, NOT NULL
                'is_correct' => $itemData['is_correct'], // boolean, nullable
                'marks_awarded' => $itemData['marks_awarded'], // decimal(8,2), nullable
                'grading_metadata' => $itemData['grading_metadata'], // JSON, nullable
                'detailed_feedback' => $itemData['detailed_feedback'], // text, nullable
                'time_spent' => $itemData['time_spent'], // int unsigned, nullable
            ]);

            Log::info("✅ Submission item #{$itemIndex} created with ID: {$submissionItem->id}");
        }

        Log::info('✅ ALL SUBMISSION ITEMS CREATED SUCCESSFULLY');

        return $submission;
    });

    Log::info('🔧 HANDLING POST-SUBMISSION TASKS...');

    // Handle post-submission tasks
    if ($status === 'pending') {
        Log::info('📧 CREATING GRADING TASK FOR TEACHER...');
        
        // Get the child who submitted
        $child = Child::find($payload['child_id']);
        
        // Check for assigned teacher
        $assignedTeacher = $child->assignedTeachers()->first();
        
        // Determine who should grade this: assigned teacher or all teachers
        $assignedTo = $assignedTeacher ? $assignedTeacher->id : null;

        // Pick correct grading link based on assignee role
        $relatedLink = route('admin.submissions.grade', $submission->id);
        if ($assignedTeacher && $assignedTeacher->role === 'teacher' && route()->has('teacher.submissions.grade')) {
            $relatedLink = route('teacher.submissions.grade', $submission->id);
        }
        
        AdminTask::create([
            'task_type'      => 'Grade Assessment Submission',
            'assigned_to'    => $assignedTo,
            'status'         => 'Pending',
            'related_entity' => $relatedLink,
            'priority'       => 'Medium',
            'description'    => "Manual grading required for submission #{$submission->id}. Assessment: {$assessment->title}. Student: {$child->child_name}",
        ]);
        
        Log::info('✅ GRADING TASK CREATED FOR TEACHER:', [
            'submission_id' => $submission->id,
            'assessment_title' => $assessment->title,
            'assigned_to' => $assignedTo,
            'child_id' => $child->id,
            'child_name' => $child->child_name,
            'has_assigned_teacher' => $assignedTeacher ? true : false
        ]);
    }
    
    if ($status === 'graded') {
        Log::info('📊 DISPATCHING REPORT GENERATION JOB...');
        
        dispatch(new GenerateAssessmentReportJob($submission));
        
        Log::info('✅ REPORT GENERATION JOB DISPATCHED:', [
            'submission_id' => $submission->id
        ]);
    }

    Log::info('=== ASSESSMENT SUBMISSION COMPLETED SUCCESSFULLY ===', [
        'submission_id' => $submission->id,
        'final_status' => $status,
        'total_marks' => $totalMarks,
        'obtained_marks' => $obtainedMarks,
        'success_message' => $status === 'graded' 
            ? 'Assessment submitted and graded successfully!'
            : 'Assessment submitted! Some questions require manual grading.',
        'redirect_route' => 'submissions.show',
        'timestamp' => now()->toDateTimeString()
    ]);

    return redirect()->route('submissions.show', $submission->id)
        ->with('success', $status === 'graded' 
            ? 'Assessment submitted and graded successfully!'
            : 'Assessment submitted! Some questions require manual grading.');
}

/**
 * API endpoint to get assessment questions (both inline and bank)
 */
public function getQuestionsApi(Assessment $assessment)
{
    $questions = $assessment->getAllQuestions();
    
    return response()->json([
        'questions' => $questions,
        'total_marks' => array_sum(array_column($questions, 'marks')),
        'question_count' => count($questions),
    ]);
}

/**
 * API endpoint to attach bank questions to assessment
 */
public function attachQuestionsApi(Request $request, Assessment $assessment)
{
    $request->validate([
        'question_ids' => 'required|array',
        'question_ids.*' => 'exists:questions,id',
    ]);

    $questionIds = $request->question_ids;
    $currentMaxOrder = $assessment->bankQuestions()->max('order_position') ?? 0;
    
    // Prepare pivot data with order positions
    $pivotData = [];
    foreach ($questionIds as $index => $questionId) {
        $pivotData[$questionId] = [
            'order_position' => $currentMaxOrder + $index + 1,
            'custom_points' => null, // Use question default
            'custom_settings' => null,
        ];
    }

    // Attach questions (will skip if already attached)
    $assessment->bankQuestions()->syncWithoutDetaching($pivotData);

    $questions = $assessment->getAllQuestions();
    
    return response()->json([
        'message' => 'Questions attached successfully!',
        'questions' => $questions,
        'total_marks' => array_sum(array_column($questions, 'marks')),
        'question_count' => count($questions),
    ]);
}

/**
 * API endpoint to detach a bank question from assessment
 */
public function detachQuestionApi(Assessment $assessment, \App\Models\Question $question)
{
    $assessment->bankQuestions()->detach($question->id);
    
    $questions = $assessment->getAllQuestions();
    
    return response()->json([
        'message' => 'Question removed successfully!',
        'questions' => $questions,
        'total_marks' => array_sum(array_column($questions, 'marks')),
        'question_count' => count($questions),
    ]);
}

}
