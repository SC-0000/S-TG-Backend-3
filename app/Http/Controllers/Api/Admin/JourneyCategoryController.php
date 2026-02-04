<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\JourneyCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JourneyCategoryController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id');

        $query = JourneyCategory::with('journey:id,title');

        if ($user?->role === 'super_admin') {
            if ($request->filled('organization_id')) {
                $query->where('organization_id', $request->integer('organization_id'));
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
}
