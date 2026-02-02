<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\ApiController;
use App\Models\ContentLesson;
use App\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentLessonController extends ApiController
{
    private function resolveOrgId(Request $request): ?int
    {
        $orgId = $request->header('X-Organization-Id') ?? $request->query('organization_id');
        return $orgId ? (int) $orgId : null;
    }

    public function index(Request $request): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);

        $query = ContentLesson::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('status', 'published');

        if ($request->filled('year_group')) {
            $query->where('year_group', $request->year_group);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $lessons = $query->orderBy('order_position')
            ->paginate(ApiPagination::perPage($request));

        $data = $lessons->getCollection()->map(fn ($lesson) => $this->mapLesson($lesson))->all();

        return $this->paginated($lessons, $data);
    }

    public function show(Request $request, ContentLesson $lesson): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $lesson->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        if ($lesson->status !== 'published') {
            return $this->error('Not found.', [], 404);
        }

        $lesson->load(['modules:id,title', 'assessments:id,title,description,status']);

        $payload = $this->mapLesson($lesson);
        $payload['modules'] = $lesson->modules->map(fn ($module) => [
            'id' => $module->id,
            'title' => $module->title,
        ]);
        $payload['assessments'] = $lesson->assessments->map(fn ($assessment) => [
            'id' => $assessment->id,
            'title' => $assessment->title,
            'description' => $assessment->description,
            'status' => $assessment->status,
        ]);

        return $this->success($payload);
    }

    public function slides(Request $request, ContentLesson $lesson): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId && (int) $lesson->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $slides = $lesson->slides()->orderBy('order_position')->paginate(ApiPagination::perPage($request));

        $data = $slides->getCollection()->map(fn ($slide) => [
            'id' => $slide->id,
            'uid' => $slide->uid,
            'title' => $slide->title,
            'order_position' => $slide->order_position,
            'estimated_seconds' => $slide->estimated_seconds,
            'auto_advance' => $slide->auto_advance,
            'min_time_seconds' => $slide->min_time_seconds,
        ])->all();

        return $this->paginated($slides, $data);
    }

    private function mapLesson(ContentLesson $lesson): array
    {
        return [
            'id' => $lesson->id,
            'uid' => $lesson->uid,
            'organization_id' => $lesson->organization_id,
            'title' => $lesson->title,
            'description' => $lesson->description,
            'year_group' => $lesson->year_group,
            'lesson_type' => $lesson->lesson_type,
            'delivery_mode' => $lesson->delivery_mode,
            'status' => $lesson->status,
            'estimated_minutes' => $lesson->estimated_minutes,
            'completion_rules' => $lesson->completion_rules,
            'enable_ai_help' => (bool) $lesson->enable_ai_help,
            'enable_tts' => (bool) $lesson->enable_tts,
        ];
    }
}
