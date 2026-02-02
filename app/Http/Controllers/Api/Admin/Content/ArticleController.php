<?php

namespace App\Http\Controllers\Api\Admin\Content;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Content\ArticleStoreRequest;
use App\Http\Requests\Api\Content\ArticleUpdateRequest;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArticleController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Article::query();
        $orgId = $request->attributes->get('organization_id');
        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        ApiQuery::applyFilters($query, $request, [
            'category' => true,
            'tag' => true,
            'title' => true,
            'name' => true,
        ]);

        ApiQuery::applySort($query, $request, ['scheduled_publish_date', 'created_at', 'title', 'name'], '-scheduled_publish_date');

        $articles = $query->paginate(ApiPagination::perPage($request, 20));
        $data = ArticleResource::collection($articles->items())->resolve();

        return $this->paginated($articles, $data);
    }

    public function show(Request $request, Article $article): JsonResponse
    {
        if (!$this->canAccess($request, $article)) {
            return $this->error('Not found.', [], 404);
        }

        $data = (new ArticleResource($article))->resolve();

        return $this->success($data);
    }

    public function store(ArticleStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        $orgId = $request->attributes->get('organization_id') ?? $user?->current_organization_id;
        if ($user?->isSuperAdmin() && isset($data['organization_id'])) {
            $orgId = (int) $data['organization_id'];
        }
        if ($orgId) {
            $data['organization_id'] = $orgId;
        }

        $data = $this->handleUploads($request, $data);

        $article = Article::create($data);

        $payload = (new ArticleResource($article))->resolve();

        return $this->success(['article' => $payload], [], 201);
    }

    public function update(ArticleUpdateRequest $request, Article $article): JsonResponse
    {
        if (!$this->canAccess($request, $article)) {
            return $this->error('Not found.', [], 404);
        }

        $data = $request->validated();
        $user = $request->user();

        if (!$user?->isSuperAdmin()) {
            unset($data['organization_id']);
        }

        $data = $this->handleUploads($request, $data, $article);

        $article->update($data);

        $payload = (new ArticleResource($article->fresh()))->resolve();

        return $this->success(['article' => $payload]);
    }

    public function destroy(Request $request, Article $article): JsonResponse
    {
        if (!$this->canAccess($request, $article)) {
            return $this->error('Not found.', [], 404);
        }

        $article->delete();

        return $this->success(['message' => 'Article deleted successfully.']);
    }

    private function canAccess(Request $request, Article $article): bool
    {
        $orgId = $request->attributes->get('organization_id');
        if (!$orgId) {
            return true;
        }

        return (int) $article->organization_id === (int) $orgId;
    }

    private function handleUploads(Request $request, array $data, ?Article $article = null): array
    {
        if ($request->hasFile('thumbnail')) {
            $data['thumbnail'] = $request->file('thumbnail')->store('articles/thumbnails', 'public');
        } elseif ($article && !array_key_exists('thumbnail', $data)) {
            unset($data['thumbnail']);
        }

        if ($request->hasFile('author_photo')) {
            $data['author_photo'] = $request->file('author_photo')->store('articles/authors', 'public');
        } elseif ($article && !array_key_exists('author_photo', $data)) {
            unset($data['author_photo']);
        }

        if ($request->hasFile('pdf')) {
            $data['pdf'] = $request->file('pdf')->store('articles/pdfs', 'public');
        } elseif ($article && !array_key_exists('pdf', $data)) {
            unset($data['pdf']);
        }

        if ($request->hasFile('images')) {
            $imagePaths = [];
            foreach ($request->file('images') as $image) {
                $imagePaths[] = $image->store('articles/images', 'public');
            }
            $data['images'] = $imagePaths;
        } elseif ($article && !array_key_exists('images', $data)) {
            unset($data['images']);
        }

        return $data;
    }
}
