<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\TermsCondition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TermsController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $orgId = $request->user()->current_organization_id;

        if (!$orgId) {
            return $this->error('No organization selected.', [], 422);
        }

        $terms = TermsCondition::forOrganization($orgId)
            ->orderByDesc('version')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'version' => $t->version,
                'applies_to' => $t->applies_to,
                'is_active' => $t->is_active,
                'published_at' => $t->published_at,
                'created_at' => $t->created_at,
                'acceptances_count' => $t->acceptances()->count(),
            ]);

        return $this->success($terms);
    }

    public function show(Request $request, TermsCondition $term): JsonResponse
    {
        $orgId = $request->user()->current_organization_id;

        if ($term->owner_type !== 'organization' || $term->organization_id !== $orgId) {
            return $this->error('Term not found.', [], 404);
        }

        return $this->success([
            'id' => $term->id,
            'title' => $term->title,
            'content' => $term->content,
            'version' => $term->version,
            'applies_to' => $term->applies_to,
            'is_active' => $term->is_active,
            'published_at' => $term->published_at,
            'created_at' => $term->created_at,
            'acceptances_count' => $term->acceptances()->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $orgId = $request->user()->current_organization_id;

        if (!$orgId) {
            return $this->error('No organization selected.', [], 422);
        }

        $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'applies_to' => 'required|array|min:1',
            'applies_to.*' => 'string|in:parent,teacher',
        ]);

        $term = TermsCondition::create([
            'owner_type' => 'organization',
            'organization_id' => $orgId,
            'title' => $request->input('title'),
            'content' => $request->input('content'),
            'version' => TermsCondition::nextVersion('organization', $orgId),
            'applies_to' => $request->input('applies_to'),
            'is_active' => false,
            'created_by' => $request->user()->id,
        ]);

        return $this->success([
            'id' => $term->id,
            'title' => $term->title,
            'version' => $term->version,
        ], [], 201);
    }

    public function update(Request $request, TermsCondition $term): JsonResponse
    {
        $orgId = $request->user()->current_organization_id;

        if ($term->owner_type !== 'organization' || $term->organization_id !== $orgId) {
            return $this->error('Term not found.', [], 404);
        }

        $request->validate([
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
            'applies_to' => 'sometimes|array|min:1',
            'applies_to.*' => 'string|in:parent,teacher',
        ]);

        $term->update($request->only(['title', 'content', 'applies_to']));

        return $this->success([
            'id' => $term->id,
            'title' => $term->title,
            'version' => $term->version,
            'is_active' => $term->is_active,
        ]);
    }

    public function publish(Request $request, TermsCondition $term): JsonResponse
    {
        $orgId = $request->user()->current_organization_id;

        if ($term->owner_type !== 'organization' || $term->organization_id !== $orgId) {
            return $this->error('Term not found.', [], 404);
        }

        // Deactivate other active org terms with overlapping applies_to
        TermsCondition::forOrganization($orgId)
            ->active()
            ->where('id', '!=', $term->id)
            ->where(function ($q) use ($term) {
                foreach ($term->applies_to as $role) {
                    $q->orWhereJsonContains('applies_to', $role);
                }
            })
            ->update(['is_active' => false]);

        $term->update([
            'is_active' => true,
            'published_at' => now(),
        ]);

        return $this->success(['message' => 'Terms published. All matching users will be prompted.']);
    }

    public function unpublish(Request $request, TermsCondition $term): JsonResponse
    {
        $orgId = $request->user()->current_organization_id;

        if ($term->owner_type !== 'organization' || $term->organization_id !== $orgId) {
            return $this->error('Term not found.', [], 404);
        }

        $term->update(['is_active' => false]);

        return $this->success(['message' => 'Terms unpublished.']);
    }
}
