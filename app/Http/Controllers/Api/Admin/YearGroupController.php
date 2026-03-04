<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\YearGroups\YearGroupBulkUpdateRequest;
use App\Models\Child;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class YearGroupController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?: $user?->current_organization_id;
        if ($user?->isSuperAdmin() && $request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
        }

        return $this->success($this->yearGroups($orgId));
    }

    public function bulkUpdate(YearGroupBulkUpdateRequest $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $validated = $request->validated();
        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;

        $updated = Child::whereIn('id', $validated['child_ids'])
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->update(['year_group' => $validated['year_group']]);

        return $this->success([
            'updated_count' => $updated,
            'message' => "Successfully updated {$updated} student(s) to {$validated['year_group']}",
        ]);
    }

    private function yearGroups(?int $orgId): array
    {
        $defaults = [
            'Kindergarten',
            'Grade 1',
            'Grade 2',
            'Grade 3',
            'Grade 4',
            'Grade 5',
            'Grade 6',
            'Grade 7',
            'Grade 8',
            'Grade 9',
            'Grade 10',
            'Grade 11',
            'Grade 12',
        ];

        if (!$orgId) {
            return $defaults;
        }

        $organization = Organization::find($orgId);
        if (!$organization) {
            return $defaults;
        }

        $list = $organization->getSetting('branding.year_groups', []);
        if (!is_array($list) || empty($list)) {
            return $defaults;
        }

        $normalized = array_values(array_filter(array_map(function ($item) {
            return is_string($item) ? trim($item) : '';
        }, $list)));

        return $normalized ?: $defaults;
    }
}
