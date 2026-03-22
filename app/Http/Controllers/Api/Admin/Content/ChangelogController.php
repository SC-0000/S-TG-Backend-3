<?php

namespace App\Http\Controllers\Api\Admin\Content;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Content\ChangelogEntryRequest;
use App\Http\Resources\ChangelogEntryResource;
use App\Models\ChangelogEntry;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChangelogController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = ChangelogEntry::query();

        ApiQuery::applyFilters($query, $request, [
            'category' => true,
        ]);

        ApiQuery::applySort($query, $request, ['published_at', 'created_at'], '-created_at');

        $entries = $query->paginate(ApiPagination::perPage($request, 20));
        $data = ChangelogEntryResource::collection($entries->items())->resolve();

        return $this->paginated($entries, $data);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $entry = ChangelogEntry::findOrFail($id);
        $data = (new ChangelogEntryResource($entry))->resolve();

        return $this->success($data);
    }

    public function store(ChangelogEntryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()?->id;

        $entry = ChangelogEntry::create($data);

        $payload = (new ChangelogEntryResource($entry))->resolve();

        return $this->success(['changelog_entry' => $payload], [], 201);
    }

    public function update(ChangelogEntryRequest $request, string $id): JsonResponse
    {
        $entry = ChangelogEntry::findOrFail($id);
        $entry->update($request->validated());

        $payload = (new ChangelogEntryResource($entry->fresh()))->resolve();

        return $this->success(['changelog_entry' => $payload]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $entry = ChangelogEntry::findOrFail($id);
        $entry->delete();

        return $this->success(['message' => 'Changelog entry deleted successfully.']);
    }
}
