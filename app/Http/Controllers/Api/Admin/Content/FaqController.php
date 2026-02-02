<?php

namespace App\Http\Controllers\Api\Admin\Content;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Content\FaqRequest;
use App\Http\Resources\FaqResource;
use App\Models\Faq;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FaqController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Faq::query();

        ApiQuery::applyFilters($query, $request, [
            'category' => true,
            'published' => true,
        ]);

        ApiQuery::applySort($query, $request, ['created_at', 'category', 'question'], '-created_at');

        $faqs = $query->paginate(ApiPagination::perPage($request, 20));
        $data = FaqResource::collection($faqs->items())->resolve();

        return $this->paginated($faqs, $data);
    }

    public function show(Request $request, string $faq): JsonResponse
    {
        $model = Faq::findOrFail($faq);

        $data = (new FaqResource($model))->resolve();

        return $this->success($data);
    }

    public function store(FaqRequest $request): JsonResponse
    {
        $data = $request->validated();

        $data['id'] = (string) Str::uuid();
        $data['author_id'] = $request->user()?->id;

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('faqs', 'public');
        }

        $faq = Faq::create($data);

        $payload = (new FaqResource($faq))->resolve();

        return $this->success(['faq' => $payload], [], 201);
    }

    public function update(FaqRequest $request, string $faq): JsonResponse
    {
        $model = Faq::findOrFail($faq);
        $data = $request->validated();

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('faqs', 'public');
        }

        $model->update($data);

        $payload = (new FaqResource($model->fresh()))->resolve();

        return $this->success(['faq' => $payload]);
    }

    public function destroy(Request $request, string $faq): JsonResponse
    {
        $model = Faq::findOrFail($faq);
        $model->delete();

        return $this->success(['message' => 'FAQ deleted successfully.']);
    }
}
