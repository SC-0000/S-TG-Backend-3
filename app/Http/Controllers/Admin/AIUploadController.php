<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessAIUploadJob;
use App\Models\AIUploadSession;
use App\Models\AIUploadProposal;
use App\Models\AIUploadLog;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\Module;
use App\Models\ContentLesson;
use App\Models\LessonSlide;
use App\Services\AI\Agents\ContentUploadAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AIUploadController extends Controller
{
    /**
     * Display the AI Upload dashboard
     */
    public function index()
    {
        $user = Auth::user();
        $organizationId = $user->current_organization_id;

        // Get active sessions
        $activeSessions = AIUploadSession::forUser($user->id)
            ->active()
            ->with('proposals')
            ->orderBy('created_at', 'desc')
            ->get();

        // Get recent completed sessions
        $recentSessions = AIUploadSession::forUser($user->id)
            ->whereIn('status', [
                AIUploadSession::STATUS_COMPLETED,
                AIUploadSession::STATUS_REVIEW_PENDING,
                AIUploadSession::STATUS_APPROVED,
                AIUploadSession::STATUS_FAILED,
            ])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Get existing courses for module attachment
        $courses = Course::visibleToOrg($organizationId)
            ->select('id', 'title', 'year_group')
            ->orderBy('title')
            ->get();

        return Inertia::render('@admin/AIUpload/Index', [
            'activeSessions' => $activeSessions,
            'recentSessions' => $recentSessions,
            'courses' => $courses,
            'contentTypes' => [
                ['value' => 'question', 'label' => 'Questions', 'icon' => '❓'],
                ['value' => 'assessment', 'label' => 'Assessments', 'icon' => '📝'],
                ['value' => 'course', 'label' => 'Courses', 'icon' => '📚'],
                ['value' => 'module', 'label' => 'Modules', 'icon' => '📦'],
                ['value' => 'lesson', 'label' => 'Lessons', 'icon' => '📖'],
                ['value' => 'slide', 'label' => 'Slides', 'icon' => '🎞️'],
                ['value' => 'article', 'label' => 'Articles', 'icon' => '📰'],
            ],
        ]);
    }

    /**
     * Create a new AI upload session
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'content_type' => 'required|in:question,assessment,course,module,lesson,slide,article',
            'user_prompt' => 'nullable|string|max:5000',
            'source_type' => 'nullable|in:prompt,text,file,url',
            'source_data' => 'nullable|array',
            'input_settings' => 'nullable|array',
            'input_settings.year_group' => 'nullable|string',
            'input_settings.category' => 'nullable|string',
            'input_settings.item_count' => 'nullable|integer|min:1|max:50',
            'input_settings.difficulty_min' => 'nullable|integer|min:1|max:10',
            'input_settings.difficulty_max' => 'nullable|integer|min:1|max:10',
            'input_settings.question_types' => 'nullable|array',
            'input_settings.modules_count' => 'nullable|integer|min:1|max:20',
            'input_settings.lessons_per_module' => 'nullable|integer|min:1|max:20',
            'input_settings.slides_per_lesson' => 'nullable|integer|min:1|max:20',
            'input_settings.questions_per_assessment' => 'nullable|integer|min:1|max:50',
            'input_settings.course_id' => 'nullable|integer|exists:courses,id',
            'quality_threshold' => 'nullable|numeric|min:0.5|max:1',
            'max_iterations' => 'nullable|integer|min:1|max:20',
            'process_now' => 'nullable|boolean',
        ]);

        $user = Auth::user();

        $session = AIUploadSession::create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'content_type' => $validated['content_type'],
            'status' => AIUploadSession::STATUS_PENDING,
            'user_prompt' => $validated['user_prompt'] ?? null,
            'source_type' => $validated['source_type'] ?? AIUploadSession::SOURCE_PROMPT,
            'source_data' => $validated['source_data'] ?? null,
            'input_settings' => $validated['input_settings'] ?? [],
            'quality_threshold' => $validated['quality_threshold'] ?? 0.85,
            'max_iterations' => $validated['max_iterations'] ?? 10,
        ]);

        // Process immediately or queue
        if ($request->input('process_now', true)) {
            // For immediate processing (synchronous)
            $agent = app(ContentUploadAgent::class);
            $result = $agent->process($session);

            return response()->json([
                'success' => $result['success'],
                'session' => $session->fresh()->load('proposals'),
                'stats' => $result['stats'] ?? null,
                'error' => $result['error'] ?? null,
            ]);
        } else {
            // Queue for background processing
            ProcessAIUploadJob::dispatch($session);

            return response()->json([
                'success' => true,
                'session' => $session,
                'message' => 'Session queued for processing',
            ]);
        }
    }

    /**
     * Get session details with proposals
     */
    public function show(AIUploadSession $session)
    {
        $this->authorize('view', $session);

        $session->load([
            'proposals' => function ($query) {
                $query->orderBy('parent_proposal_id')
                      ->orderBy('order_position');
            },
            'logs' => function ($query) {
                $query->orderBy('created_at', 'desc')->limit(50);
            },
        ]);

        // Build hierarchical structure for proposals
        $proposalTree = $this->buildProposalTree($session->proposals);

        return response()->json([
            'session' => $session,
            'proposalTree' => $proposalTree,
            'tokenUsage' => AIUploadLog::getSessionTokenUsage($session->id),
        ]);
    }

    /**
     * Update a proposal
     */
    public function updateProposal(Request $request, AIUploadProposal $proposal)
    {
        $this->authorize('update', $proposal->session);

        $validated = $request->validate([
            'proposed_data' => 'required|array',
            'status' => 'nullable|in:pending,approved,rejected',
        ]);

        $user = Auth::user();

        if (isset($validated['proposed_data'])) {
            $proposal->updateProposedData($validated['proposed_data'], $user->id);
            $proposal->validate();
        }

        if (isset($validated['status'])) {
            $proposal->update(['status' => $validated['status']]);
        }

        return response()->json([
            'success' => true,
            'proposal' => $proposal->fresh(),
        ]);
    }

    /**
     * Approve multiple proposals
     */
    public function approveProposals(Request $request, AIUploadSession $session)
    {
        $this->authorize('update', $session);

        $validated = $request->validate([
            'proposal_ids' => 'required|array',
            'proposal_ids.*' => 'integer|exists:ai_upload_proposals,id',
        ]);

        $approved = AIUploadProposal::whereIn('id', $validated['proposal_ids'])
            ->where('session_id', $session->id)
            ->update(['status' => AIUploadProposal::STATUS_APPROVED]);

        $session->update([
            'items_approved' => $session->proposals()->approved()->count(),
        ]);

        return response()->json([
            'success' => true,
            'approved_count' => $approved,
        ]);
    }

    /**
     * Reject multiple proposals
     */
    public function rejectProposals(Request $request, AIUploadSession $session)
    {
        $this->authorize('update', $session);

        $validated = $request->validate([
            'proposal_ids' => 'required|array',
            'proposal_ids.*' => 'integer|exists:ai_upload_proposals,id',
        ]);

        $rejected = AIUploadProposal::whereIn('id', $validated['proposal_ids'])
            ->where('session_id', $session->id)
            ->update(['status' => AIUploadProposal::STATUS_REJECTED]);

        $session->update([
            'items_rejected' => $session->proposals()->where('status', 'rejected')->count(),
        ]);

        return response()->json([
            'success' => true,
            'rejected_count' => $rejected,
        ]);
    }

    /**
     * Refine a proposal with AI
     */
    public function refineProposal(Request $request, AIUploadProposal $proposal)
    {
        $this->authorize('update', $proposal->session);

        $validated = $request->validate([
            'feedback' => 'required|string|max:2000',
        ]);

        $agent = app(ContentUploadAgent::class);
        $refined = $agent->refineProposal($proposal, $validated['feedback']);

        return response()->json([
            'success' => true,
            'proposal' => $refined,
        ]);
    }

    /**
     * Upload approved proposals to database
     */
    public function upload(Request $request, AIUploadSession $session)
    {
        $this->authorize('update', $session);

        $user = Auth::user();
        $organizationId = $user->current_organization_id;

        // Get approved proposals in order
        $proposals = $session->proposals()
            ->whereIn('status', [AIUploadProposal::STATUS_APPROVED, AIUploadProposal::STATUS_MODIFIED])
            ->where('is_valid', true)
            ->orderBy('parent_proposal_id')
            ->orderBy('order_position')
            ->get();

        if ($proposals->isEmpty()) {
            return response()->json([
                'success' => false,
                'error' => 'No approved proposals to upload',
            ], 400);
        }

        $results = [
            'created' => [],
            'errors' => [],
        ];

        // Map to track created model IDs for parent-child relationships
        $createdModels = [];
        $assessmentQuestions = [];

        DB::beginTransaction();

        try {
            // Create assessment-linked question bank items first so we can attach them later.
            foreach ($proposals as $proposal) {
                if ($proposal->content_type === AIUploadProposal::TYPE_QUESTION
                    && $proposal->parent_type === 'assessment') {
                    $questionModel = $proposal->createModel($organizationId);

                    if ($questionModel) {
                        $assessmentQuestions[$proposal->parent_proposal_id][] = [
                            'model' => $questionModel,
                            'order_position' => $proposal->order_position,
                            'marks' => $proposal->getProposedField('marks'),
                        ];
                        $createdModels[$proposal->id] = $questionModel;
                        $results['created'][] = [
                            'proposal_id' => $proposal->id,
                            'content_type' => $proposal->content_type,
                            'model_id' => $questionModel->id,
                            'title' => $proposal->getDisplayTitle(),
                        ];
                    } else {
                        $results['errors'][] = [
                            'proposal_id' => $proposal->id,
                            'title' => $proposal->getDisplayTitle(),
                            'error' => 'Failed to create model',
                        ];
                    }
                }
            }

            // Create remaining items (assessments, courses, lessons, etc.)
            foreach ($proposals as $proposal) {
                if ($proposal->content_type === AIUploadProposal::TYPE_QUESTION
                    && $proposal->parent_type === 'assessment') {
                    continue;
                }

                $data = $proposal->proposed_data;
                $data['organization_id'] = $organizationId;

                // Handle parent relationships
                if ($proposal->parent_proposal_id && isset($createdModels[$proposal->parent_proposal_id])) {
                    $parentModel = $createdModels[$proposal->parent_proposal_id];
                    
                    switch ($proposal->parent_type) {
                        case 'course':
                            $data['course_id'] = $parentModel->id;
                            break;
                        case 'module':
                            // For lessons, we need to link via pivot table
                            break;
                        case 'lesson':
                            $data['lesson_id'] = $parentModel->id;
                            break;
                        case 'assessment':
                            // Questions for assessments handled differently
                            break;
                    }
                }
                if ($proposal->content_type === AIUploadProposal::TYPE_MODULE && empty($data['course_id'])) {
                    $courseId = $session->input_settings['course_id'] ?? null;
                    if ($courseId) {
                        $data['course_id'] = $courseId;
                    } else {
                        throw new \RuntimeException('Module creation requires a course_id. Please select a course in AI Upload.');
                    }
                }

                // Remove nested data before creating
                unset($data['modules'], $data['lessons'], $data['slides'], $data['questions']);

                if ($proposal->content_type === AIUploadProposal::TYPE_ASSESSMENT) {
                    $assessmentData = $proposal->proposed_data;
                    $assessmentData['organization_id'] = $organizationId;

                    if (empty($assessmentData['availability'])) {
                        $assessmentData['availability'] = now();
                    }

                    if (empty($assessmentData['deadline'])) {
                        $assessmentData['deadline'] = now()->addDays(7);
                    }

                    unset($assessmentData['questions']);

                    $model = Assessment::create($assessmentData);

                    if ($model && !empty($assessmentQuestions[$proposal->id])) {
                        foreach ($assessmentQuestions[$proposal->id] as $questionEntry) {
                            $model->bankQuestions()->attach($questionEntry['model']->id, [
                                'order_position' => $questionEntry['order_position'] ?? 0,
                                'custom_points' => $questionEntry['marks'] ?? null,
                            ]);
                        }
                    }

                    if ($model) {
                        $proposal->markAsUploaded(get_class($model), $model->id);
                    }
                } else {
                    $proposal->update([
                        'proposed_data' => $data,
                    ]);
                    $model = $proposal->createModel($organizationId);
                }

                if ($model) {
                    $createdModels[$proposal->id] = $model;
                    $results['created'][] = [
                        'proposal_id' => $proposal->id,
                        'content_type' => $proposal->content_type,
                        'model_id' => $model->id,
                        'title' => $proposal->getDisplayTitle(),
                    ];

                    // Handle module-lesson pivot relationship
                    if ($proposal->content_type === 'lesson' && $proposal->parent_type === 'module') {
                        $parentModule = $createdModels[$proposal->parent_proposal_id] ?? null;
                        if ($parentModule instanceof Module) {
                            $parentModule->lessons()->attach($model->id, [
                                'order_position' => $proposal->order_position,
                            ]);
                        }
                    }
                } else {
                    $results['errors'][] = [
                        'proposal_id' => $proposal->id,
                        'title' => $proposal->getDisplayTitle(),
                        'error' => 'Failed to create model',
                    ];
                }
            }

            DB::commit();

            $session->update(['status' => AIUploadSession::STATUS_APPROVED]);

            AIUploadLog::info($session->id, AIUploadLog::ACTION_UPLOAD, 'Content uploaded to database', [
                'created_count' => count($results['created']),
                'error_count' => count($results['errors']),
            ]);

            return response()->json([
                'success' => true,
                'results' => $results,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            AIUploadLog::error($session->id, AIUploadLog::ACTION_ERROR, 'Upload failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cancel a session
     */
    public function cancel(AIUploadSession $session)
    {
        $this->authorize('update', $session);

        $session->update(['status' => AIUploadSession::STATUS_CANCELLED]);

        return response()->json([
            'success' => true,
            'message' => 'Session cancelled',
        ]);
    }

    /**
     * Delete a session
     */
    public function destroy(AIUploadSession $session)
    {
        $this->authorize('delete', $session);

        $session->delete();

        return response()->json([
            'success' => true,
            'message' => 'Session deleted',
        ]);
    }

    /**
     * Get session logs
     */
    public function logs(AIUploadSession $session)
    {
        $this->authorize('view', $session);

        $logs = $session->logs()
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        return response()->json([
            'logs' => $logs,
            'tokenUsage' => AIUploadLog::getSessionTokenUsage($session->id),
        ]);
    }

    /**
     * Build hierarchical tree from flat proposals
     */
    protected function buildProposalTree($proposals): array
    {
        $tree = [];
        $map = [];

        // First pass: create map
        foreach ($proposals as $proposal) {
            $map[$proposal->id] = [
                'proposal' => $proposal,
                'children' => [],
            ];
        }

        // Second pass: build tree
        foreach ($proposals as $proposal) {
            if ($proposal->parent_proposal_id && isset($map[$proposal->parent_proposal_id])) {
                $map[$proposal->parent_proposal_id]['children'][] = &$map[$proposal->id];
            } else {
                $tree[] = &$map[$proposal->id];
            }
        }

        return $tree;
    }

    /**
     * Authorization check (simplified - implement proper policy)
     */
    protected function authorize(string $ability, $model): void
    {
        $user = Auth::user();
        
        if ($model instanceof AIUploadSession) {
            if ($model->user_id !== $user->id && !$user->hasRole(['admin', 'super_admin'])) {
                abort(403, 'Unauthorized');
            }
        }
    }
}
