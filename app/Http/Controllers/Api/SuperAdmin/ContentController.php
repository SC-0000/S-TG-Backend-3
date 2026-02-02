<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\AssessmentResource;
use App\Models\Article;
use App\Models\Assessment;
use App\Models\ContentLesson;
use App\Models\Course;
use App\Models\Service;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ContentController extends ApiController
{
    public function courses(Request $request): JsonResponse
    {
        $query = Course::query()->withCount(['modules', 'assessments']);

        if ($request->filled('search')) {
            $query->where('title', 'like', "%{$request->search}%");
        }

        ApiQuery::applyFilters($query, $request, [
            'organization_id' => true,
            'status' => true,
            'is_global' => true,
        ]);

        ApiQuery::applySort($query, $request, ['created_at', 'title', 'status'], '-created_at');

        $courses = $query->paginate(ApiPagination::perPage($request));
        $data = $courses->getCollection()->map(function ($course) {
            return [
                'id' => $course->id,
                'uid' => $course->uid,
                'title' => $course->title,
                'status' => $course->status,
                'organization_id' => $course->organization_id,
                'is_global' => (bool) $course->is_global,
                'is_featured' => (bool) $course->is_featured,
                'modules_count' => $course->modules_count,
                'assessments_count' => $course->assessments_count,
                'created_at' => $course->created_at?->toISOString(),
                'updated_at' => $course->updated_at?->toISOString(),
            ];
        })->all();

        return $this->paginated($courses, $data);
    }

    public function lessons(Request $request): JsonResponse
    {
        $query = ContentLesson::query()->withCount(['modules', 'slides', 'assessments']);

        if ($request->filled('search')) {
            $query->where('title', 'like', "%{$request->search}%");
        }

        ApiQuery::applyFilters($query, $request, [
            'organization_id' => true,
            'status' => true,
        ]);

        ApiQuery::applySort($query, $request, ['created_at', 'title', 'status'], '-created_at');

        $lessons = $query->paginate(ApiPagination::perPage($request));
        $data = $lessons->getCollection()->map(function ($lesson) {
            return [
                'id' => $lesson->id,
                'uid' => $lesson->uid,
                'title' => $lesson->title,
                'status' => $lesson->status,
                'organization_id' => $lesson->organization_id,
                'modules_count' => $lesson->modules_count,
                'slides_count' => $lesson->slides_count,
                'assessments_count' => $lesson->assessments_count,
                'created_at' => $lesson->created_at?->toISOString(),
                'updated_at' => $lesson->updated_at?->toISOString(),
            ];
        })->all();

        return $this->paginated($lessons, $data);
    }

    public function assessments(Request $request): JsonResponse
    {
        $query = Assessment::query();

        if ($request->filled('search')) {
            $query->where('title', 'like', "%{$request->search}%");
        }

        ApiQuery::applyFilters($query, $request, [
            'organization_id' => true,
            'status' => true,
            'is_global' => true,
        ]);

        ApiQuery::applySort($query, $request, ['created_at', 'title', 'status'], '-created_at');

        $assessments = $query->paginate(ApiPagination::perPage($request));
        $data = AssessmentResource::collection($assessments->items())->resolve();

        return $this->paginated($assessments, $data);
    }

    public function services(Request $request): JsonResponse
    {
        $query = Service::query();

        if ($request->filled('search')) {
            $query->where('service_name', 'like', "%{$request->search}%");
        }

        ApiQuery::applyFilters($query, $request, [
            'organization_id' => true,
            'is_global' => true,
            'availability' => true,
            '_type' => true,
        ]);

        ApiQuery::applySort($query, $request, ['created_at', 'service_name', 'price'], '-created_at');

        $services = $query->paginate(ApiPagination::perPage($request));
        $data = $services->getCollection()->map(function ($service) {
            return [
                'id' => $service->id,
                'name' => $service->service_name,
                'type' => $service->_type,
                'service_level' => $service->service_level,
                'availability' => (bool) $service->availability,
                'price' => $service->price,
                'organization_id' => $service->organization_id,
                'is_global' => (bool) $service->is_global,
                'start_datetime' => $service->start_datetime?->toISOString(),
                'end_datetime' => $service->end_datetime?->toISOString(),
                'created_at' => $service->created_at?->toISOString(),
                'updated_at' => $service->updated_at?->toISOString(),
            ];
        })->all();

        return $this->paginated($services, $data);
    }

    public function articles(Request $request): JsonResponse
    {
        $query = Article::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        ApiQuery::applyFilters($query, $request, [
            'organization_id' => true,
            'category' => true,
            'tag' => true,
        ]);

        ApiQuery::applySort($query, $request, ['created_at', 'title'], '-created_at');

        $articles = $query->paginate(ApiPagination::perPage($request));
        $data = $articles->getCollection()->map(function ($article) {
            return [
                'id' => $article->id,
                'title' => $article->title,
                'name' => $article->name,
                'category' => $article->category,
                'tag' => $article->tag,
                'organization_id' => $article->organization_id,
                'scheduled_publish_date' => $article->scheduled_publish_date?->toISOString(),
                'created_at' => $article->created_at?->toISOString(),
                'updated_at' => $article->updated_at?->toISOString(),
            ];
        })->all();

        return $this->paginated($articles, $data);
    }

    public function moderation(): JsonResponse
    {
        return $this->success([
            'items' => [],
            'message' => 'Moderation queue is not configured.',
        ]);
    }

    public function feature(Request $request, string $type, int $id): JsonResponse
    {
        $model = $this->resolveModel($type);
        if (!$model) {
            return $this->error('Unsupported content type.', [], 422);
        }

        $record = $model::find($id);
        if (!$record) {
            return $this->error('Content not found.', [], 404);
        }

        if (property_exists($record, 'is_featured') || array_key_exists('is_featured', $record->getAttributes())) {
            $record->update(['is_featured' => true]);
        }

        return $this->success(['message' => Str::title($type) . ' featured successfully.']);
    }

    public function unfeature(Request $request, string $type, int $id): JsonResponse
    {
        $model = $this->resolveModel($type);
        if (!$model) {
            return $this->error('Unsupported content type.', [], 422);
        }

        $record = $model::find($id);
        if (!$record) {
            return $this->error('Content not found.', [], 404);
        }

        if (property_exists($record, 'is_featured') || array_key_exists('is_featured', $record->getAttributes())) {
            $record->update(['is_featured' => false]);
        }

        return $this->success(['message' => Str::title($type) . ' unfeatured successfully.']);
    }

    public function delete(string $type, int $id): JsonResponse
    {
        $model = $this->resolveModel($type);
        if (!$model) {
            return $this->error('Unsupported content type.', [], 422);
        }

        $record = $model::find($id);
        if (!$record) {
            return $this->error('Content not found.', [], 404);
        }

        $record->delete();

        return $this->success(['message' => Str::title($type) . ' deleted successfully.']);
    }

    private function resolveModel(string $type): ?string
    {
        $key = strtolower($type);

        return match ($key) {
            'course', 'courses' => Course::class,
            'lesson', 'lessons', 'content-lesson', 'content-lessons' => ContentLesson::class,
            'assessment', 'assessments' => Assessment::class,
            'service', 'services' => Service::class,
            'article', 'articles' => Article::class,
            default => null,
        };
    }
}
