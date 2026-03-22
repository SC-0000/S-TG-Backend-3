<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Assessment;
use App\Models\ContentLesson;
use App\Models\Course;
use App\Models\Journey;
use App\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ContentDashboardController extends ApiController
{
    private function resolveOrgId(Request $request): ?int
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?: $user?->current_organization_id;
        if ($user?->isSuperAdmin() && $request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
        }
        return $orgId;
    }

    /**
     * Returns counts of content in draft/review/needs_approval status, grouped by content type.
     */
    public function approvalSummary(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $this->resolveOrgId($request);

        // Content Lessons: draft, review, needs_approval
        $lessonCounts = ContentLesson::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->selectRaw("status, COUNT(*) as count")
            ->whereIn('status', ['draft', 'review', 'needs_approval'])
            ->groupBy('status')
            ->pluck('count', 'status');

        // Questions: draft, under_review, needs_approval
        $questionCounts = Question::when($orgId, fn ($q) => $q->forOrganization($orgId))
            ->selectRaw("status, COUNT(*) as count")
            ->whereIn('status', ['draft', 'under_review', 'needs_approval'])
            ->groupBy('status')
            ->pluck('count', 'status');

        // Courses: draft, review, needs_approval
        $courseCounts = Course::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->selectRaw("status, COUNT(*) as count")
            ->whereIn('status', ['draft', 'review', 'needs_approval'])
            ->groupBy('status')
            ->pluck('count', 'status');

        // Assessments: inactive, needs_approval
        $assessmentCounts = Assessment::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->selectRaw("status, COUNT(*) as count")
            ->whereIn('status', ['inactive', 'needs_approval'])
            ->groupBy('status')
            ->pluck('count', 'status');

        $types = [
            [
                'key' => 'lesson',
                'label' => 'E-Learning',
                'draft' => (int) ($lessonCounts['draft'] ?? 0),
                'review' => (int) ($lessonCounts['review'] ?? 0),
                'needs_approval' => (int) ($lessonCounts['needs_approval'] ?? 0),
                'href' => '/admin/content-lessons',
            ],
            [
                'key' => 'question',
                'label' => 'Questions',
                'draft' => (int) ($questionCounts['draft'] ?? 0),
                'review' => (int) ($questionCounts['under_review'] ?? 0),
                'needs_approval' => (int) ($questionCounts['needs_approval'] ?? 0),
                'href' => '/admin/questions',
            ],
            [
                'key' => 'course',
                'label' => 'Courses',
                'draft' => (int) ($courseCounts['draft'] ?? 0),
                'review' => (int) ($courseCounts['review'] ?? 0),
                'needs_approval' => (int) ($courseCounts['needs_approval'] ?? 0),
                'href' => '/admin/courses',
            ],
            [
                'key' => 'assessment',
                'label' => 'Assessments',
                'draft' => (int) ($assessmentCounts['inactive'] ?? 0),
                'review' => 0,
                'needs_approval' => (int) ($assessmentCounts['needs_approval'] ?? 0),
                'href' => '/admin/assessments',
            ],
        ];

        $totalDraft = collect($types)->sum('draft');
        $totalReview = collect($types)->sum('review');
        $totalNeedsApproval = collect($types)->sum('needs_approval');

        return $this->success([
            'types' => $types,
            'total_draft' => $totalDraft,
            'total_review' => $totalReview,
            'total_needs_approval' => $totalNeedsApproval,
            'total' => $totalDraft + $totalReview + $totalNeedsApproval,
        ]);
    }

    /**
     * Returns journey completeness overview — how much content each journey has.
     * Includes cover_image_url for thumbnail previews.
     */
    public function journeyCompleteness(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $this->resolveOrgId($request);

        $journeys = Journey::when($orgId, fn ($q) => $q->forOrganization($orgId))
            ->with([
                'categories' => fn ($q) => $q
                    ->withCount(['lessons', 'assessments', 'contentLessons', 'courses', 'mediaAssets'])
                    ->orderBy('topic')
                    ->orderBy('sort_order')
                    ->orderBy('name'),
            ])
            ->orderBy('title')
            ->get();

        $data = $journeys->map(function (Journey $journey) {
            $totalCategories = $journey->categories->count();
            $categoriesWithContent = $journey->categories->filter(function ($cat) {
                return ($cat->lessons_count + $cat->content_lessons_count + $cat->assessments_count + $cat->courses_count) > 0;
            })->count();

            $totalContent = $journey->categories->sum(function ($cat) {
                return $cat->lessons_count + $cat->content_lessons_count + $cat->assessments_count + $cat->courses_count;
            });

            $totalMedia = $journey->categories->sum('media_assets_count');

            return [
                'id' => $journey->id,
                'title' => $journey->title,
                'curriculum_level' => $journey->curriculum_level,
                'exam_board' => $journey->exam_board,
                'cover_image_url' => $journey->cover_image
                    ? Storage::disk('public')->url($journey->cover_image)
                    : null,
                'total_categories' => $totalCategories,
                'categories_with_content' => $categoriesWithContent,
                'completeness_pct' => $totalCategories > 0
                    ? round(($categoriesWithContent / $totalCategories) * 100)
                    : 0,
                'total_content' => $totalContent,
                'total_media' => $totalMedia,
                'content_breakdown' => [
                    'lessons' => $journey->categories->sum('lessons_count'),
                    'content_lessons' => $journey->categories->sum('content_lessons_count'),
                    'assessments' => $journey->categories->sum('assessments_count'),
                    'courses' => $journey->categories->sum('courses_count'),
                ],
            ];
        })->values();

        return $this->success($data);
    }
}
