<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\AIUploadLogResource;
use App\Http\Resources\AIUploadProposalResource;
use App\Http\Resources\AIUploadSessionResource;
use App\Jobs\ProcessAIUploadJob;
use App\Models\AIUploadLog;
use App\Models\AIUploadProposal;
use App\Models\AIUploadSession;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\Module;
use App\Services\AI\Agents\ContentUploadAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AIUploadController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $this->resolveOrganizationId($request);

        $activeSessions = AIUploadSession::forUser($user->id)
            ->forOrganization($orgId)
            ->active()
            ->with('proposals')
            ->orderBy('created_at', 'desc')
            ->get();

        $recentSessions = AIUploadSession::forUser($user->id)
            ->forOrganization($orgId)
            ->whereIn('status', [
                AIUploadSession::STATUS_COMPLETED,
                AIUploadSession::STATUS_REVIEW_PENDING,
                AIUploadSession::STATUS_APPROVED,
                AIUploadSession::STATUS_FAILED,
            ])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $courses = Course::visibleToOrg($orgId)
            ->select('id', 'title', 'year_group')
            ->orderBy('title')
            ->get();

        return $this->success([
            'active_sessions' => AIUploadSessionResource::collection($activeSessions)->resolve(),
            'recent_sessions' => AIUploadSessionResource::collection($recentSessions)->resolve(),
            'courses' => $courses,
            'content_types' => $this->contentTypes(),
        ]);
    }

    public function store(Request $request): JsonResponse
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

        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $organizationId = $this->resolveOrganizationId($request);

        $session = AIUploadSession::create([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'content_type' => $validated['content_type'],
            'status' => AIUploadSession::STATUS_PENDING,
            'user_prompt' => $validated['user_prompt'] ?? null,
            'source_type' => $validated['source_type'] ?? AIUploadSession::SOURCE_PROMPT,
            'source_data' => $validated['source_data'] ?? null,
            'input_settings' => $validated['input_settings'] ?? [],
            'quality_threshold' => $validated['quality_threshold'] ?? 0.85,
            'max_iterations' => $validated['max_iterations'] ?? 10,
        ]);

        if ($request->boolean('process_now', true)) {
            $agent = app(ContentUploadAgent::class);
            $result = $agent->process($session);

            $session->load('proposals');

            return $this->success([
                'success' => (bool) ($result['success'] ?? false),
                'session' => (new AIUploadSessionResource($session))->resolve(),
                'stats' => $result['stats'] ?? null,
                'error' => $result['error'] ?? null,
            ], status: ($result['success'] ?? false) ? 200 : 422);
        }

        ProcessAIUploadJob::dispatch($session);

        return $this->success([
            'message' => 'Session queued for processing.',
            'session' => (new AIUploadSessionResource($session))->resolve(),
        ], status: 201);
    }

    public function show(Request $request, AIUploadSession $session): JsonResponse
    {
        if ($response = $this->ensureSessionScope($request, $session)) {
            return $response;
        }

        $session->load([
            'proposals' => function ($query) {
                $query->orderBy('parent_proposal_id')
                      ->orderBy('order_position');
            },
            'logs' => function ($query) {
                $query->orderBy('created_at', 'desc')->limit(50);
            },
        ]);

        $proposalTree = $this->buildProposalTree($session->proposals);

        return $this->success([
            'session' => (new AIUploadSessionResource($session))->resolve(),
            'proposal_tree' => $proposalTree,
            'token_usage' => AIUploadLog::getSessionTokenUsage($session->id),
        ]);
    }

    public function updateProposal(Request $request, AIUploadProposal $proposal): JsonResponse
    {
        if ($response = $this->ensureProposalScope($request, $proposal)) {
            return $response;
        }

        $validated = $request->validate([
            'proposed_data' => 'required|array',
            'status' => 'nullable|in:pending,approved,rejected',
        ]);

        $user = $request->user();

        if (isset($validated['proposed_data'])) {
            $proposal->updateProposedData($validated['proposed_data'], $user?->id ?? 0);
            $proposal->validate();
        }

        if (isset($validated['status'])) {
            $proposal->update(['status' => $validated['status']]);
        }

        return $this->success([
            'proposal' => (new AIUploadProposalResource($proposal->fresh()))->resolve(),
        ]);
    }

    public function refineProposal(Request $request, AIUploadProposal $proposal): JsonResponse
    {
        if ($response = $this->ensureProposalScope($request, $proposal)) {
            return $response;
        }

        $validated = $request->validate([
            'feedback' => 'required|string|max:2000',
        ]);

        $agent = app(ContentUploadAgent::class);

        try {
            $refined = $agent->refineProposal($proposal, $validated['feedback']);
        } catch (\Throwable $e) {
            Log::error('[AIUploadApi] Failed to refine proposal', [
                'proposal_id' => $proposal->id,
                'error' => $e->getMessage(),
            ]);
            return $this->error('Failed to refine proposal.', [], 500);
        }

        return $this->success([
            'proposal' => (new AIUploadProposalResource($refined))->resolve(),
        ]);
    }

    public function approveProposals(Request $request, AIUploadSession $session): JsonResponse
    {
        if ($response = $this->ensureSessionScope($request, $session)) {
            return $response;
        }

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

        return $this->success([
            'approved_count' => $approved,
        ]);
    }

    public function rejectProposals(Request $request, AIUploadSession $session): JsonResponse
    {
        if ($response = $this->ensureSessionScope($request, $session)) {
            return $response;
        }

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

        return $this->success([
            'rejected_count' => $rejected,
        ]);
    }

    public function upload(Request $request, AIUploadSession $session): JsonResponse
    {
        if ($response = $this->ensureSessionScope($request, $session)) {
            return $response;
        }

        $user = $request->user();
        $organizationId = $this->resolveOrganizationId($request);

        $proposals = $session->proposals()
            ->whereIn('status', [AIUploadProposal::STATUS_APPROVED, AIUploadProposal::STATUS_MODIFIED])
            ->where('is_valid', true)
            ->orderBy('parent_proposal_id')
            ->orderBy('order_position')
            ->get();

        if ($proposals->isEmpty()) {
            return $this->error('No approved proposals to upload.', [], 400);
        }

        $results = [
            'created' => [],
            'errors' => [],
        ];

        $createdModels = [];
        $assessmentQuestions = [];

        DB::beginTransaction();

        try {
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

            foreach ($proposals as $proposal) {
                if ($proposal->content_type === AIUploadProposal::TYPE_QUESTION
                    && $proposal->parent_type === 'assessment') {
                    continue;
                }

                $data = $proposal->proposed_data;
                $data['organization_id'] = $organizationId;

                if ($proposal->parent_proposal_id && isset($createdModels[$proposal->parent_proposal_id])) {
                    $parentModel = $createdModels[$proposal->parent_proposal_id];

                    switch ($proposal->parent_type) {
                        case 'course':
                            $data['course_id'] = $parentModel->id;
                            break;
                        case 'lesson':
                            $data['lesson_id'] = $parentModel->id;
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

            return $this->success([
                'results' => $results,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            AIUploadLog::error($session->id, AIUploadLog::ACTION_ERROR, 'Upload failed: ' . $e->getMessage());

            return $this->error('Upload failed.', [
                ['message' => $e->getMessage()],
            ], 500);
        }
    }

    public function cancel(Request $request, AIUploadSession $session): JsonResponse
    {
        if ($response = $this->ensureSessionScope($request, $session)) {
            return $response;
        }

        $session->update(['status' => AIUploadSession::STATUS_CANCELLED]);

        return $this->success(['message' => 'Session cancelled']);
    }

    public function destroy(Request $request, AIUploadSession $session): JsonResponse
    {
        if ($response = $this->ensureSessionScope($request, $session)) {
            return $response;
        }

        $session->delete();

        return $this->success(['message' => 'Session deleted']);
    }

    public function logs(Request $request, AIUploadSession $session): JsonResponse
    {
        if ($response = $this->ensureSessionScope($request, $session)) {
            return $response;
        }

        $logs = $session->logs()
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        return $this->success([
            'logs' => AIUploadLogResource::collection($logs)->resolve(),
            'token_usage' => AIUploadLog::getSessionTokenUsage($session->id),
        ]);
    }

    private function resolveOrganizationId(Request $request): ?int
    {
        $user = $request->user();
        if ($user?->isSuperAdmin() && $request->filled('organization_id')) {
            return $request->integer('organization_id');
        }

        $orgId = $request->attributes->get('organization_id') ?: $user?->current_organization_id;

        return $orgId ? (int) $orgId : null;
    }

    private function ensureSessionScope(Request $request, AIUploadSession $session): ?JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $this->resolveOrganizationId($request);
        if ($orgId && (int) $session->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        if (!$user->isAdmin() && !$user->isSuperAdmin() && (int) $session->user_id !== (int) $user->id) {
            return $this->error('Unauthorized access.', [], 403);
        }

        return null;
    }

    private function ensureProposalScope(Request $request, AIUploadProposal $proposal): ?JsonResponse
    {
        $proposal->loadMissing('session');

        if (!$proposal->session) {
            return $this->error('Not found.', [], 404);
        }

        return $this->ensureSessionScope($request, $proposal->session);
    }

    private function buildProposalTree($proposals): array
    {
        $tree = [];
        $map = [];

        foreach ($proposals as $proposal) {
            $map[$proposal->id] = [
                'proposal' => (new AIUploadProposalResource($proposal))->resolve(),
                'children' => [],
            ];
        }

        foreach ($proposals as $proposal) {
            if ($proposal->parent_proposal_id && isset($map[$proposal->parent_proposal_id])) {
                $map[$proposal->parent_proposal_id]['children'][] = &$map[$proposal->id];
            } else {
                $tree[] = &$map[$proposal->id];
            }
        }

        return $tree;
    }

    private function contentTypes(): array
    {
        return [
            ['value' => 'question', 'label' => 'Questions', 'icon' => 'question'],
            ['value' => 'assessment', 'label' => 'Assessments', 'icon' => 'assessment'],
            ['value' => 'course', 'label' => 'Courses', 'icon' => 'course'],
            ['value' => 'module', 'label' => 'Modules', 'icon' => 'module'],
            ['value' => 'lesson', 'label' => 'Lessons', 'icon' => 'lesson'],
            ['value' => 'slide', 'label' => 'Slides', 'icon' => 'slide'],
            ['value' => 'article', 'label' => 'Articles', 'icon' => 'article'],
        ];
    }
}
