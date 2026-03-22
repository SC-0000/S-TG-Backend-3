<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Journey;
use App\Services\MediaAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class JourneyController extends ApiController
{
    private function resolveOrgId(Request $request, $user): ?int
    {
        $orgId = $request->attributes->get('organization_id') ?: $user?->current_organization_id;
        if ($user?->isSuperAdmin() && $request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
        }
        return $orgId;
    }

    private function ensureOrgAccess(Request $request, Journey $journey): ?JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $this->resolveOrgId($request, $user);
        if (!$user->isSuperAdmin()) {
            if ($orgId && (int) $journey->organization_id !== (int) $orgId) {
                return $this->error('Forbidden.', [], 403);
            }
        } elseif ($request->filled('organization_id') && $orgId && (int) $journey->organization_id !== (int) $orgId) {
            return $this->error('Forbidden.', [], 403);
        }

        return null;
    }

    private function transformJourney(Journey $journey): array
    {
        return [
            'id' => $journey->id,
            'title' => $journey->title,
            'description' => $journey->description,
            'exam_end_date' => $journey->exam_end_date,
            'exam_board' => $journey->exam_board,
            'curriculum_level' => $journey->curriculum_level,
            'year_groups' => $journey->year_groups ?? [],
            'exam_dates' => $journey->exam_dates ?? [],
            'exam_website_url' => $journey->exam_website_url,
            'specification_reference' => $journey->specification_reference,
            'cover_image' => $journey->cover_image,
            'cover_image_url' => $journey->cover_image ? Storage::disk('public')->url($journey->cover_image) : null,
            'organization_id' => $journey->organization_id,
            'created_at' => $journey->created_at,
            'updated_at' => $journey->updated_at,
        ];
    }

    private function journeyValidationRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'exam_end_date' => ['nullable', 'date'],
            'exam_board' => ['nullable', 'string', 'max:255'],
            'curriculum_level' => ['nullable', 'string', 'max:100'],
            'year_groups' => ['nullable', 'array'],
            'year_groups.*' => ['string', 'max:50'],
            'exam_dates' => ['nullable', 'array'],
            'exam_website_url' => ['nullable', 'string', 'max:500'],
            'specification_reference' => ['nullable', 'string', 'max:500'],
            'cover_image' => ['nullable', 'file', 'image', 'max:5120'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $this->resolveOrgId($request, $user);

        $journeys = Journey::when($orgId, fn ($q) => $q->forOrganization($orgId))
            ->withCount('categories')
            ->orderBy('title')
            ->get();

        $data = $journeys->map(fn (Journey $j) => array_merge($this->transformJourney($j), [
            'categories_count' => $j->categories_count,
        ]))->values();

        return $this->success($data);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $validated = $request->validate($this->journeyValidationRules());

        $organizationId = $user->current_organization_id;
        if ($user->isSuperAdmin() && !empty($validated['organization_id'])) {
            $organizationId = $validated['organization_id'];
        }

        $coverImage = null;
        if ($request->hasFile('cover_image')) {
            $file = $request->file('cover_image');
            $coverImage = $file->store("journeys/covers", 'public');
            MediaAssetService::track($coverImage, $organizationId, $user->id, 'public', [
                'original_filename' => $file->getClientOriginalName(),
            ]);
        }

        $journey = Journey::create([
            'organization_id' => $organizationId,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'exam_end_date' => $validated['exam_end_date'] ?? null,
            'exam_board' => $validated['exam_board'] ?? null,
            'curriculum_level' => $validated['curriculum_level'] ?? null,
            'year_groups' => $validated['year_groups'] ?? null,
            'exam_dates' => $validated['exam_dates'] ?? null,
            'exam_website_url' => $validated['exam_website_url'] ?? null,
            'specification_reference' => $validated['specification_reference'] ?? null,
            'cover_image' => $coverImage,
        ]);

        return $this->success(['journey' => $this->transformJourney($journey)], status: 201);
    }

    public function show(Request $request, Journey $journey): JsonResponse
    {
        if ($response = $this->ensureOrgAccess($request, $journey)) {
            return $response;
        }

        $journey->load(['categories' => fn ($q) => $q->orderBy('topic')->orderBy('sort_order')->orderBy('name')]);

        $data = $this->transformJourney($journey);
        $data['categories'] = $journey->categories->map(fn ($cat) => [
            'id' => $cat->id,
            'topic' => $cat->topic,
            'name' => $cat->name,
            'description' => $cat->description,
            'difficulty_weighting' => $cat->difficulty_weighting,
            'estimated_hours' => $cat->estimated_hours,
            'sort_order' => $cat->sort_order,
        ])->values();

        return $this->success(['journey' => $data]);
    }

    public function update(Request $request, Journey $journey): JsonResponse
    {
        if ($response = $this->ensureOrgAccess($request, $journey)) {
            return $response;
        }

        $validated = $request->validate($this->journeyValidationRules());

        $user = $request->user();
        $organizationId = $journey->organization_id;
        if ($user?->isSuperAdmin() && array_key_exists('organization_id', $validated)) {
            $organizationId = $validated['organization_id'];
        }

        $updateData = [
            'organization_id' => $organizationId,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'exam_end_date' => $validated['exam_end_date'] ?? null,
            'exam_board' => $validated['exam_board'] ?? null,
            'curriculum_level' => $validated['curriculum_level'] ?? null,
            'year_groups' => $validated['year_groups'] ?? null,
            'exam_dates' => $validated['exam_dates'] ?? null,
            'exam_website_url' => $validated['exam_website_url'] ?? null,
            'specification_reference' => $validated['specification_reference'] ?? null,
        ];

        if ($request->hasFile('cover_image')) {
            $file = $request->file('cover_image');
            $updateData['cover_image'] = $file->store("journeys/covers", 'public');
            MediaAssetService::track($updateData['cover_image'], $organizationId, $user->id, 'public', [
                'original_filename' => $file->getClientOriginalName(),
            ]);
        }

        $journey->update($updateData);

        return $this->success(['journey' => $this->transformJourney($journey)]);
    }

    public function destroy(Request $request, Journey $journey): JsonResponse
    {
        if ($response = $this->ensureOrgAccess($request, $journey)) {
            return $response;
        }

        $journey->delete();

        return $this->success(['deleted' => true]);
    }

    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $this->resolveOrgId($request, $user);

        $journeys = Journey::when($orgId, fn ($q) => $q->forOrganization($orgId))
            ->with([
                'categories' => fn ($q) => $q->with([
                    'lessons:id,title,journey_category_id',
                    'assessments:id,title,journey_category_id',
                ])->orderBy('topic')->orderBy('sort_order')->orderBy('name'),
            ])->orderBy('title')->get();

        $data = $journeys->map(function (Journey $journey) {
            $byTopic = $journey->categories
                ->groupBy('topic')
                ->map(function ($cats) {
                    return $cats->map(function ($cat) {
                        return [
                            'id' => $cat->id,
                            'name' => $cat->name,
                            'description' => $cat->description,
                            'difficulty_weighting' => $cat->difficulty_weighting,
                            'lessons' => $cat->lessons->map->only(['id', 'title']),
                            'assessments' => $cat->assessments->map->only(['id', 'title']),
                        ];
                    })->values();
                });

            return [
                'id' => $journey->id,
                'title' => $journey->title,
                'topics' => $byTopic,
            ];
        })->values();

        return $this->success($data);
    }

    /**
     * Rich endpoint for the unified journey management page.
     */
    public function showFull(Request $request, Journey $journey): JsonResponse
    {
        if ($response = $this->ensureOrgAccess($request, $journey)) {
            return $response;
        }

        $journey->load([
            'categories' => fn ($q) => $q
                ->withCount(['lessons', 'assessments', 'contentLessons', 'courses', 'mediaAssets'])
                ->orderBy('topic')
                ->orderBy('sort_order')
                ->orderBy('name'),
        ]);

        $data = $this->transformJourney($journey);

        $grouped = $journey->categories->groupBy('topic')->map(function ($cats, $topic) {
            return [
                'topic' => $topic,
                'categories' => $cats->map(function ($cat) {
                    return [
                        'id' => $cat->id,
                        'topic' => $cat->topic,
                        'name' => $cat->name,
                        'description' => $cat->description,
                        'ai_context' => $cat->ai_context,
                        'learning_objectives' => $cat->learning_objectives ?? [],
                        'key_topics' => $cat->key_topics ?? [],
                        'difficulty_weighting' => $cat->difficulty_weighting,
                        'estimated_hours' => $cat->estimated_hours,
                        'specification_reference' => $cat->specification_reference,
                        'parent_summary' => $cat->parent_summary,
                        'sort_order' => $cat->sort_order,
                        'counts' => [
                            'lessons' => $cat->lessons_count,
                            'assessments' => $cat->assessments_count,
                            'content_lessons' => $cat->content_lessons_count,
                            'courses' => $cat->courses_count,
                            'media' => $cat->media_assets_count,
                        ],
                    ];
                })->values(),
            ];
        })->values();

        $data['topics'] = $grouped;
        $data['totals'] = [
            'topics' => $grouped->count(),
            'categories' => $journey->categories->count(),
            'lessons' => $journey->categories->sum('lessons_count'),
            'assessments' => $journey->categories->sum('assessments_count'),
            'content_lessons' => $journey->categories->sum('content_lessons_count'),
            'courses' => $journey->categories->sum('courses_count'),
            'media' => $journey->categories->sum('media_assets_count'),
        ];

        return $this->success($data);
    }

    /**
     * Returns all content items for a journey category — used by the journey content viewer.
     */
    public function categoryContent(Request $request, int $categoryId): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $category = \App\Models\JourneyCategory::with([
            'lessons:id,title,journey_category_id',
            'contentLessons:id,uid,title,status,lesson_type,estimated_minutes,journey_category_id',
            'assessments:id,title,type,status,journey_category_id',
            'courses:id,uid,title,status,journey_category_id',
            'mediaAssets',
        ])->findOrFail($categoryId);

        // Verify org access
        $orgId = $this->resolveOrgId($request, $user);
        if (!$user->isSuperAdmin() && $orgId && (int) $category->organization_id !== (int) $orgId) {
            return $this->error('Forbidden.', [], 403);
        }

        return $this->success([
            'category' => [
                'id' => $category->id,
                'topic' => $category->topic,
                'name' => $category->name,
                'description' => $category->description,
            ],
            'content' => [
                'lessons' => $category->lessons->map(fn ($l) => [
                    'id' => $l->id,
                    'title' => $l->title,
                    'type' => 'lesson',
                    'href' => "/admin/lessons/{$l->id}",
                ])->values(),
                'content_lessons' => $category->contentLessons->map(fn ($l) => [
                    'id' => $l->id,
                    'uid' => $l->uid,
                    'title' => $l->title,
                    'status' => $l->status,
                    'lesson_type' => $l->lesson_type,
                    'estimated_minutes' => $l->estimated_minutes,
                    'type' => 'content_lesson',
                    'href' => "/admin/content-lessons/{$l->id}",
                ])->values(),
                'assessments' => $category->assessments->map(fn ($a) => [
                    'id' => $a->id,
                    'title' => $a->title,
                    'status' => $a->status,
                    'assessment_type' => $a->type,
                    'type' => 'assessment',
                    'href' => "/assessments/{$a->id}",
                ])->values(),
                'courses' => $category->courses->map(fn ($c) => [
                    'id' => $c->id,
                    'uid' => $c->uid,
                    'title' => $c->title,
                    'status' => $c->status,
                    'type' => 'course',
                    'href' => "/admin/courses/{$c->id}",
                ])->values(),
                'media' => $category->mediaAssets->map(fn ($m) => [
                    'id' => $m->id,
                    'title' => $m->title,
                    'file_name' => $m->original_filename,
                    'file_type' => $m->mime_type,
                    'file_size' => $m->size_bytes,
                    'type' => 'media',
                    'href' => "/admin/files",
                ])->values(),
            ],
        ]);
    }
}
