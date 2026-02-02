<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\ContentLesson;
use App\Models\Journey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JourneyController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');

        $journeys = Journey::when($orgId, fn ($q) => $q->forOrganization($orgId))
            ->with([
                'categories' => fn ($q) => $q->with([
                    'lessons:id,title,journey_category_id',
                    'assessments:id,title,journey_category_id',
                ])->orderBy('topic')->orderBy('name'),
            ])
            ->orderBy('title')
            ->get();

        $journeys = $journeys->map(function ($journey) {
            $topics = $journey->categories
                ->groupBy('topic')
                ->map(function ($categories) {
                    return $categories->map(function ($category) {
                        $contentLessons = ContentLesson::where('journey_category_id', $category->id)
                            ->get(['id', 'title']);

                        return [
                            'id' => $category->id,
                            'name' => $category->name,
                            'lessons' => $category->lessons->map->only(['id', 'title']),
                            'content_lessons' => $contentLessons->map->only(['id', 'title']),
                            'assessments' => $category->assessments->map->only(['id', 'title']),
                        ];
                    })->values();
                });

            return [
                'id' => $journey->id,
                'title' => $journey->title,
                'description' => $journey->description,
                'topics' => $topics,
            ];
        });

        return $this->success([
            'journeys' => $journeys,
        ]);
    }
}
