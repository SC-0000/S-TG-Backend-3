<?php

namespace App\Http\Controllers\Api\Admin\Content;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Content\SlideRequest;
use App\Http\Resources\SlideResource;
use App\Models\Slide;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SlideController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Slide::query();
        $orgId = $request->attributes->get('organization_id');
        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        ApiQuery::applyFilters($query, $request, [
            'status' => true,
        ]);

        ApiQuery::applySort($query, $request, ['order', 'created_at', 'last_modified'], 'order');

        $slides = $query->paginate(ApiPagination::perPage($request, 20));
        $data = SlideResource::collection($slides->items())->resolve();

        return $this->paginated($slides, $data);
    }

    public function show(Request $request, string $slideId): JsonResponse
    {
        $query = Slide::query();
        $orgId = $request->attributes->get('organization_id');
        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        $slide = $query->where('slide_id', $slideId)->firstOrFail();

        $data = (new SlideResource($slide))->resolve();

        return $this->success($data);
    }

    public function store(SlideRequest $request): JsonResponse
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

        $data['slide_id'] = (string) Str::uuid();
        $data['last_modified'] = Carbon::now();

        if ($request->hasFile('images')) {
            $paths = [];
            foreach ($request->file('images') as $image) {
                $paths[] = $image->store('slides', 'public');
            }
            $data['images'] = $paths;
        }

        $slide = Slide::create($data);

        $payload = (new SlideResource($slide))->resolve();

        return $this->success(['slide' => $payload], [], 201);
    }

    public function update(SlideRequest $request, string $slideId): JsonResponse
    {
        $query = Slide::query();
        $orgId = $request->attributes->get('organization_id');
        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        $slide = $query->where('slide_id', $slideId)->firstOrFail();
        $data = $request->validated();

        if (!($request->user()?->isSuperAdmin())) {
            unset($data['organization_id']);
        }

        if ($request->hasFile('images')) {
            $paths = [];
            foreach ($request->file('images') as $image) {
                $paths[] = $image->store('slides', 'public');
            }
            $data['images'] = $paths;
        } elseif (!array_key_exists('images', $data)) {
            unset($data['images']);
        }

        $data['last_modified'] = Carbon::now();

        $slide->update($data);

        $payload = (new SlideResource($slide->fresh()))->resolve();

        return $this->success(['slide' => $payload]);
    }

    public function destroy(Request $request, string $slideId): JsonResponse
    {
        $query = Slide::query();
        $orgId = $request->attributes->get('organization_id');
        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        $slide = $query->where('slide_id', $slideId)->firstOrFail();
        $slide->delete();

        return $this->success(['message' => 'Slide deleted successfully.']);
    }
}
