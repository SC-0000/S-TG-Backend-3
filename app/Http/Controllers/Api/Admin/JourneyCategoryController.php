<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\JourneyCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JourneyCategoryController extends ApiController
{
    private function resolveOrgId(Request $request, $user): ?int
    {
        $orgId = $request->attributes->get('organization_id') ?: $user?->current_organization_id;
        if ($user?->isSuperAdmin() && $request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
        }
        return $orgId;
    }

    private function ensureOrgAccess(Request $request, JourneyCategory $category): ?JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $this->resolveOrgId($request, $user);
        if (!$user->isSuperAdmin()) {
            if ($orgId && (int) $category->organization_id !== (int) $orgId) {
                return $this->error('Forbidden.', [], 403);
            }
        } elseif ($request->filled('organization_id') && $orgId && (int) $category->organization_id !== (int) $orgId) {
            return $this->error('Forbidden.', [], 403);
        }

        return null;
    }

    private function categoryValidationRules(bool $isUpdate = false): array
    {
        return [
            'journey_id' => [$isUpdate ? 'sometimes' : 'required', 'exists:journeys,id'],
            'topic' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'ai_context' => ['nullable', 'string'],
            'learning_objectives' => ['nullable', 'array'],
            'learning_objectives.*' => ['string', 'max:500'],
            'key_topics' => ['nullable', 'array'],
            'key_topics.*' => ['string', 'max:255'],
            'difficulty_weighting' => ['nullable', 'integer', 'min:1', 'max:10'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'specification_reference' => ['nullable', 'string', 'max:500'],
            'parent_summary' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
        ];
    }

    private function transformCategory(JourneyCategory $cat): array
    {
        $data = [
            'id' => $cat->id,
            'journey_id' => $cat->journey_id,
            'organization_id' => $cat->organization_id,
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
            'created_at' => $cat->created_at,
            'updated_at' => $cat->updated_at,
        ];

        if ($cat->relationLoaded('journey')) {
            $data['journey'] = $cat->journey ? [
                'id' => $cat->journey->id,
                'title' => $cat->journey->title,
            ] : null;
        }

        return $data;
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $this->resolveOrgId($request, $user);

        $query = JourneyCategory::with('journey:id,title');

        if ($user?->role === 'super_admin') {
            if ($request->filled('organization_id') && $orgId) {
                $query->where('organization_id', $orgId);
            }
        } elseif ($orgId) {
            $query->where('organization_id', $orgId);
        }

        $categories = $query->orderBy('topic')->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn ($cat) => $this->transformCategory($cat));

        return $this->success([
            'categories' => $categories,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $validated = $request->validate($this->categoryValidationRules());

        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;
        if ($user->isSuperAdmin() && !empty($validated['organization_id'])) {
            $orgId = $validated['organization_id'];
        }

        $category = JourneyCategory::create([
            'organization_id' => $orgId,
            'journey_id' => $validated['journey_id'],
            'topic' => $validated['topic'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'ai_context' => $validated['ai_context'] ?? null,
            'learning_objectives' => $validated['learning_objectives'] ?? null,
            'key_topics' => $validated['key_topics'] ?? null,
            'difficulty_weighting' => $validated['difficulty_weighting'] ?? null,
            'estimated_hours' => $validated['estimated_hours'] ?? null,
            'specification_reference' => $validated['specification_reference'] ?? null,
            'parent_summary' => $validated['parent_summary'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return $this->success(['category' => $this->transformCategory($category)], status: 201);
    }

    public function show(Request $request, JourneyCategory $journeyCategory): JsonResponse
    {
        if ($response = $this->ensureOrgAccess($request, $journeyCategory)) {
            return $response;
        }

        $journeyCategory->load('journey:id,title');
        $journeyCategory->loadCount(['lessons', 'assessments', 'contentLessons', 'courses', 'mediaAssets']);

        $data = $this->transformCategory($journeyCategory);
        $data['counts'] = [
            'lessons' => $journeyCategory->lessons_count,
            'assessments' => $journeyCategory->assessments_count,
            'content_lessons' => $journeyCategory->content_lessons_count,
            'courses' => $journeyCategory->courses_count,
            'media' => $journeyCategory->media_assets_count,
        ];
        $data['ai_context_composed'] = $journeyCategory->getAIContext();

        return $this->success(['category' => $data]);
    }

    public function update(Request $request, JourneyCategory $journeyCategory): JsonResponse
    {
        if ($response = $this->ensureOrgAccess($request, $journeyCategory)) {
            return $response;
        }

        $validated = $request->validate($this->categoryValidationRules(isUpdate: true));

        $user = $request->user();
        $organizationId = $journeyCategory->organization_id;
        if ($user?->isSuperAdmin() && array_key_exists('organization_id', $validated)) {
            $organizationId = $validated['organization_id'];
        }

        $updateData = array_filter([
            'organization_id' => $organizationId,
            'journey_id' => $validated['journey_id'] ?? $journeyCategory->journey_id,
            'topic' => $validated['topic'] ?? $journeyCategory->topic,
            'name' => $validated['name'] ?? $journeyCategory->name,
        ], fn ($v) => $v !== null);

        // Nullable fields — allow explicit null
        $nullableFields = [
            'description', 'ai_context', 'learning_objectives', 'key_topics',
            'difficulty_weighting', 'estimated_hours', 'specification_reference',
            'parent_summary', 'sort_order',
        ];
        foreach ($nullableFields as $field) {
            if (array_key_exists($field, $validated)) {
                $updateData[$field] = $validated[$field];
            }
        }

        $journeyCategory->update($updateData);

        return $this->success(['category' => $this->transformCategory($journeyCategory)]);
    }

    public function destroy(Request $request, JourneyCategory $journeyCategory): JsonResponse
    {
        if ($response = $this->ensureOrgAccess($request, $journeyCategory)) {
            return $response;
        }

        $journeyCategory->delete();

        return $this->success(['deleted' => true]);
    }

    /**
     * Attach media assets to a category.
     */
    public function attachMedia(Request $request, JourneyCategory $journeyCategory): JsonResponse
    {
        if ($response = $this->ensureOrgAccess($request, $journeyCategory)) {
            return $response;
        }

        $validated = $request->validate([
            'media_asset_ids' => ['required', 'array'],
            'media_asset_ids.*' => ['integer', 'exists:media_assets,id'],
        ]);

        $journeyCategory->mediaAssets()->syncWithoutDetaching($validated['media_asset_ids']);

        return $this->success(['attached' => true]);
    }

    /**
     * Detach media assets from a category.
     */
    public function detachMedia(Request $request, JourneyCategory $journeyCategory): JsonResponse
    {
        if ($response = $this->ensureOrgAccess($request, $journeyCategory)) {
            return $response;
        }

        $validated = $request->validate([
            'media_asset_ids' => ['required', 'array'],
            'media_asset_ids.*' => ['integer', 'exists:media_assets,id'],
        ]);

        $journeyCategory->mediaAssets()->detach($validated['media_asset_ids']);

        return $this->success(['detached' => true]);
    }

    /**
     * Batch update sort_order for categories.
     */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'categories' => ['required', 'array'],
            'categories.*.id' => ['required', 'integer', 'exists:journey_categories,id'],
            'categories.*.sort_order' => ['required', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['categories'] as $item) {
                JourneyCategory::where('id', $item['id'])->update(['sort_order' => $item['sort_order']]);
            }
        });

        return $this->success(['reordered' => true]);
    }
}
