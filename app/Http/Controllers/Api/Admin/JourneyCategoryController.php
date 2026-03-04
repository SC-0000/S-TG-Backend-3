<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\JourneyCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $this->resolveOrgId($request, $user);

        $query = JourneyCategory::with('journey:id,title');

        if ($user?->role === 'super_admin') {
            if ($request->filled('organization_id') && $orgId) {
                $query->where('organization_id', $orgId);
            }
        } else if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        $categories = $query->orderBy('topic')->orderBy('name')->get()
            ->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'topic' => $category->topic,
                'description' => $category->description,
                'organization_id' => $category->organization_id,
                'journey' => $category->journey ? [
                    'id' => $category->journey->id,
                    'title' => $category->journey->title,
                ] : null,
            ]);

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

        $validated = $request->validate([
            'journey_id' => ['required', 'exists:journeys,id'],
            'topic' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
        ]);

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
        ]);

        return $this->success([
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'topic' => $category->topic,
                'description' => $category->description,
                'organization_id' => $category->organization_id,
                'journey_id' => $category->journey_id,
            ],
        ], status: 201);
    }

    public function show(Request $request, JourneyCategory $journeyCategory): JsonResponse
    {
        if ($response = $this->ensureOrgAccess($request, $journeyCategory)) {
            return $response;
        }

        $journeyCategory->load('journey:id,title');

        return $this->success([
            'category' => [
                'id' => $journeyCategory->id,
                'name' => $journeyCategory->name,
                'topic' => $journeyCategory->topic,
                'description' => $journeyCategory->description,
                'organization_id' => $journeyCategory->organization_id,
                'journey' => $journeyCategory->journey ? [
                    'id' => $journeyCategory->journey->id,
                    'title' => $journeyCategory->journey->title,
                ] : null,
                'journey_id' => $journeyCategory->journey_id,
            ],
        ]);
    }

    public function update(Request $request, JourneyCategory $journeyCategory): JsonResponse
    {
        if ($response = $this->ensureOrgAccess($request, $journeyCategory)) {
            return $response;
        }

        $validated = $request->validate([
            'journey_id' => ['required', 'exists:journeys,id'],
            'topic' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
        ]);

        $user = $request->user();
        $organizationId = $journeyCategory->organization_id;
        if ($user?->isSuperAdmin() && array_key_exists('organization_id', $validated)) {
            $organizationId = $validated['organization_id'];
        }

        $journeyCategory->update([
            'organization_id' => $organizationId,
            'journey_id' => $validated['journey_id'],
            'topic' => $validated['topic'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        return $this->success([
            'category' => [
                'id' => $journeyCategory->id,
                'name' => $journeyCategory->name,
                'topic' => $journeyCategory->topic,
                'description' => $journeyCategory->description,
                'organization_id' => $journeyCategory->organization_id,
                'journey_id' => $journeyCategory->journey_id,
            ],
        ]);
    }

    public function destroy(Request $request, JourneyCategory $journeyCategory): JsonResponse
    {
        if ($response = $this->ensureOrgAccess($request, $journeyCategory)) {
            return $response;
        }

        $journeyCategory->delete();

        return $this->success([
            'deleted' => true,
        ]);
    }
}
