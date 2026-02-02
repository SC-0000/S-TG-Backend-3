<?php

namespace App\Http\Controllers\Api\Admin\Content;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Content\TestimonialRequest;
use App\Http\Resources\TestimonialResource;
use App\Models\Testimonial;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TestimonialController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Testimonial::query();
        $orgId = $request->attributes->get('organization_id');
        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        ApiQuery::applyFilters($query, $request, [
            'status' => 'Status',
        ]);

        ApiQuery::applySort($query, $request, ['DisplayOrder', 'SubmissionDate', 'TestimonialID'], '-SubmissionDate');

        $testimonials = $query->paginate(ApiPagination::perPage($request, 20));
        $data = TestimonialResource::collection($testimonials->items())->resolve();

        return $this->paginated($testimonials, $data);
    }

    public function show(Request $request, int $testimonial): JsonResponse
    {
        $query = Testimonial::query();
        $orgId = $request->attributes->get('organization_id');
        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        $model = $query->where('TestimonialID', $testimonial)->firstOrFail();

        $data = (new TestimonialResource($model))->resolve();

        return $this->success($data);
    }

    public function store(TestimonialRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        $orgId = $request->attributes->get('organization_id') ?? $user?->current_organization_id;
        if ($user?->isSuperAdmin() && isset($data['organization_id'])) {
            $orgId = (int) $data['organization_id'];
        }
        $payload = [
            'organization_id' => $orgId,
            'UserName' => $data['user_name'],
            'UserEmail' => $data['user_email'],
            'Message' => $data['message'],
            'Rating' => $data['rating'] ?? null,
            'Status' => $data['status'],
            'AdminComment' => $data['admin_comment'] ?? null,
            'SubmissionDate' => $data['submission_date'] ?? now(),
            'UserIP' => $data['user_ip'] ?? $request->ip(),
            'DisplayOrder' => $data['display_order'] ?? null,
        ];

        if ($request->hasFile('attachments')) {
            $payload['Attachments'] = $request->file('attachments')->store('testimonials', 'public');
        }

        $testimonial = Testimonial::create($payload);

        $payload = (new TestimonialResource($testimonial))->resolve();

        return $this->success(['testimonial' => $payload], [], 201);
    }

    public function update(TestimonialRequest $request, int $testimonial): JsonResponse
    {
        $query = Testimonial::query();
        $orgId = $request->attributes->get('organization_id');
        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        $model = $query->where('TestimonialID', $testimonial)->firstOrFail();
        $data = $request->validated();

        $payload = [
            'UserName' => $data['user_name'],
            'UserEmail' => $data['user_email'],
            'Message' => $data['message'],
            'Rating' => $data['rating'] ?? null,
            'Status' => $data['status'],
            'AdminComment' => $data['admin_comment'] ?? null,
            'SubmissionDate' => $data['submission_date'] ?? $model->SubmissionDate,
            'UserIP' => $data['user_ip'] ?? $model->UserIP,
            'DisplayOrder' => $data['display_order'] ?? null,
        ];

        if ($request->user()?->isSuperAdmin() && isset($data['organization_id'])) {
            $payload['organization_id'] = (int) $data['organization_id'];
        }

        if ($request->hasFile('attachments')) {
            $payload['Attachments'] = $request->file('attachments')->store('testimonials', 'public');
        }

        $model->update($payload);

        $payload = (new TestimonialResource($model->fresh()))->resolve();

        return $this->success(['testimonial' => $payload]);
    }

    public function destroy(Request $request, int $testimonial): JsonResponse
    {
        $query = Testimonial::query();
        $orgId = $request->attributes->get('organization_id');
        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        $model = $query->where('TestimonialID', $testimonial)->firstOrFail();
        $model->delete();

        return $this->success(['message' => 'Testimonial deleted successfully.']);
    }
}
