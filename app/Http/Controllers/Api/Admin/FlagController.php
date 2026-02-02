<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\AIGradingFlagResource;
use App\Models\AIGradingFlag;
use App\Models\AdminTask;
use App\Models\AssessmentSubmissionItem;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FlagController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = AIGradingFlag::with([
            'child.user',
            'submissionItem.submission.assessment',
            'adminUser',
        ]);

        $this->applyOrgScope($request, $query);

        ApiQuery::applyFilters($query, $request, [
            'status' => true,
            'flag_reason' => true,
        ]);

        ApiQuery::applySort($query, $request, ['created_at', 'status'], '-created_at');

        $flags = $query->paginate(ApiPagination::perPage($request, 15));
        $data = AIGradingFlagResource::collection($flags->items())->resolve();

        return $this->paginated($flags, $data, [
            'stats' => $this->statsData($request),
        ]);
    }

    public function show(Request $request, AIGradingFlag $flag): JsonResponse
    {
        if ($response = $this->ensureFlagScope($request, $flag)) {
            return $response;
        }

        $flag->load([
            'child.user',
            'submissionItem.submission.assessment',
            'submissionItem.question',
            'adminUser',
        ]);

        return $this->success((new AIGradingFlagResource($flag))->resolve());
    }

    public function resolve(Request $request, AIGradingFlag $flag): JsonResponse
    {
        if ($response = $this->ensureFlagScope($request, $flag)) {
            return $response;
        }

        $validated = $request->validate([
            'resolution' => 'required|in:approved,dismissed',
            'admin_comment' => 'nullable|string|max:1000',
            'new_grade' => 'nullable|numeric|min:0',
        ]);

        DB::transaction(function () use ($request, $flag, $validated) {
            $status = $validated['resolution'] === 'dismissed' ? 'dismissed' : 'resolved';

            $finalGrade = $validated['new_grade'] ?? null;
            $flag->update([
                'status' => $status,
                'admin_user_id' => $request->user()?->id,
                'admin_response' => $validated['admin_comment'] ?? null,
                'final_grade' => $finalGrade,
                'grade_changed' => $finalGrade !== null && (float) $finalGrade != (float) $flag->original_grade,
                'reviewed_at' => now(),
            ]);

            if ($validated['resolution'] === 'approved' && $request->filled('new_grade')) {
                $flag->submissionItem->update([
                    'marks_awarded' => $validated['new_grade'],
                    'grading_notes' => 'Grade adjusted due to student flag: ' . ($validated['admin_comment'] ?: 'No comment provided'),
                ]);

                $this->recalculateSubmissionTotal($flag->submissionItem->submission);
            }

            try {
                AdminTask::where('flag_id', $flag->id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'completed',
                        'completed_by' => $request->user()?->id,
                        'completed_at' => now(),
                        'notes' => 'Flag resolved: ' . $validated['resolution'],
                    ]);
            } catch (\Throwable $e) {
                // Ignore task update failures if schema doesn't support it.
            }
        });

        $flag->load([
            'child.user',
            'submissionItem.submission.assessment',
            'submissionItem.question',
            'adminUser',
        ]);

        return $this->success([
            'flag' => (new AIGradingFlagResource($flag))->resolve(),
            'message' => 'Flag has been resolved successfully.',
        ]);
    }

    public function bulkResolve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'flag_ids' => 'required|array',
            'flag_ids.*' => 'exists:ai_grading_flags,id',
            'resolution' => 'required|in:approved,dismissed',
            'admin_comment' => 'nullable|string|max:500',
        ]);

        $flagsQuery = AIGradingFlag::whereIn('id', $validated['flag_ids'])
            ->where('status', 'pending');

        $this->applyOrgScope($request, $flagsQuery);

        $flags = $flagsQuery->get();

        if ($flags->isEmpty()) {
            return $this->error('No pending flags found for resolution.', [], 404);
        }

        $updatedCount = 0;

        DB::transaction(function () use ($flags, $validated, $request, &$updatedCount) {
            foreach ($flags as $flag) {
                $status = $validated['resolution'] === 'dismissed' ? 'dismissed' : 'resolved';

                $flag->update([
                    'status' => $status,
                    'admin_user_id' => $request->user()?->id,
                    'admin_response' => $validated['admin_comment'] ?? null,
                    'reviewed_at' => now(),
                ]);

                try {
                    AdminTask::where('flag_id', $flag->id)
                        ->where('status', 'pending')
                        ->update([
                            'status' => 'completed',
                            'completed_by' => $request->user()?->id,
                            'completed_at' => now(),
                            'notes' => 'Bulk resolved: ' . $validated['resolution'],
                        ]);
                } catch (\Throwable $e) {
                    // Ignore task update failures if schema doesn't support it.
                }

                $updatedCount++;
            }
        });

        return $this->success([
            'message' => "Successfully resolved {$updatedCount} flags.",
            'updated_count' => $updatedCount,
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        return $this->success($this->statsData($request));
    }

    private function applyOrgScope(Request $request, Builder $query): void
    {
        $user = $request->user();
        if (!$user) {
            return;
        }

        $orgId = null;
        if ($user->isSuperAdmin() && $request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
        } else {
            $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;
        }

        if ($orgId) {
            $query->whereHas('submissionItem.submission.assessment', function ($q) use ($orgId) {
                $q->where('organization_id', $orgId);
            });
        }
    }

    private function ensureFlagScope(Request $request, AIGradingFlag $flag): ?JsonResponse
    {
        $query = AIGradingFlag::where('id', $flag->id);
        $this->applyOrgScope($request, $query);

        if (!$query->exists()) {
            return $this->error('Not found.', [], 404);
        }

        return null;
    }

    private function statsData(Request $request): array
    {
        $baseQuery = AIGradingFlag::query();
        $this->applyOrgScope($request, $baseQuery);

        $pending = (clone $baseQuery)->where('status', 'pending')->count();
        $resolved = (clone $baseQuery)->where('status', 'resolved')->count();
        $dismissed = (clone $baseQuery)->where('status', 'dismissed')->count();
        $total = (clone $baseQuery)->count();

        $resolvedToday = (clone $baseQuery)->where('status', 'resolved')
            ->whereDate('reviewed_at', today())
            ->count();

        $avgResolutionTime = (clone $baseQuery)->where('status', 'resolved')
            ->whereNotNull('reviewed_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, reviewed_at)) as avg_hours')
            ->value('avg_hours');

        $flagReasons = (clone $baseQuery)->where('status', 'pending')
            ->groupBy('flag_reason')
            ->selectRaw('flag_reason, count(*) as count')
            ->pluck('count', 'flag_reason')
            ->toArray();

        return [
            'pending' => $pending,
            'resolved' => $resolved,
            'dismissed' => $dismissed,
            'total' => $total,
            'resolved_today' => $resolvedToday,
            'avg_resolution_time_hours' => $avgResolutionTime,
            'flag_reasons' => $flagReasons,
        ];
    }

    private function recalculateSubmissionTotal($submission): void
    {
        if (!$submission) {
            return;
        }

        $totalMarks = $submission->items()->sum('marks');
        $obtainedMarks = $submission->items()->sum('marks_awarded');

        $submission->update([
            'total_marks' => $totalMarks,
            'marks_obtained' => $obtainedMarks,
        ]);
    }
}
