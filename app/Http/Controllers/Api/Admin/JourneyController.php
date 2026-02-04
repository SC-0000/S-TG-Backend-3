<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Journey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JourneyController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;
        if ($user->isSuperAdmin() && $request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
        }

        $journeys = Journey::when($orgId, fn ($q) => $q->forOrganization($orgId))
            ->orderBy('title')
            ->get();

        $data = $journeys->map(fn (Journey $journey) => [
            'id' => $journey->id,
            'title' => $journey->title,
            'description' => $journey->description,
            'exam_end_date' => $journey->exam_end_date,
            'created_at' => $journey->created_at,
            'updated_at' => $journey->updated_at,
            'organization_id' => $journey->organization_id,
        ])->values();

        return $this->success($data);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'exam_end_date' => ['nullable', 'date'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
        ]);

        $organizationId = $user->current_organization_id;
        if ($user->isSuperAdmin() && !empty($validated['organization_id'])) {
            $organizationId = $validated['organization_id'];
        }

        $journey = Journey::create([
            'organization_id' => $organizationId,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'exam_end_date' => $validated['exam_end_date'] ?? null,
        ]);

        return $this->success([
            'journey' => [
                'id' => $journey->id,
                'title' => $journey->title,
                'description' => $journey->description,
                'exam_end_date' => $journey->exam_end_date,
                'organization_id' => $journey->organization_id,
            ],
        ], status: 201);
    }

    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;
        if ($user->isSuperAdmin() && $request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
        }

        $journeys = Journey::when($orgId, fn ($q) => $q->forOrganization($orgId))
            ->with([
                'categories' => fn ($q) => $q->with([
                    'lessons:id,title,journey_category_id',
                    'assessments:id,title,journey_category_id',
                ])->orderBy('topic')->orderBy('name'),
            ])->orderBy('title')->get();

        $data = $journeys->map(function (Journey $journey) {
            $byTopic = $journey->categories
                ->groupBy('topic')
                ->map(function ($cats) {
                    return $cats->map(function ($cat) {
                        return [
                            'id' => $cat->id,
                            'name' => $cat->name,
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
}
