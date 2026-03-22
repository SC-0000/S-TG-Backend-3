<?php

namespace App\Http\Controllers\Api;

use App\Models\TermsAcceptance;
use App\Models\TermsCondition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TermsAcceptanceController extends ApiController
{
    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $user->current_organization_id;

        // Collect all roles that apply to this user so we match broadly.
        // Global role (e.g. "admin", "super_admin", "teacher", "parent")
        // + org pivot role (e.g. "org_admin", "super_admin", "teacher", "parent")
        // + alias mapping: global "admin" should also match "org_admin" terms
        $roles = collect([$user->role])->filter();

        if ($orgId) {
            $pivot = $user->organizations()->where('organizations.id', $orgId)->first();
            $pivotRole = $pivot?->pivot?->role;
            if ($pivotRole) {
                $roles->push($pivotRole);
            }
        }

        // Alias: users with global role "admin" should match "org_admin" terms
        if ($roles->contains('admin')) {
            $roles->push('org_admin');
        }
        // Alias: users with org pivot "org_admin" should match "admin" terms
        if ($roles->contains('org_admin')) {
            $roles->push('admin');
        }

        $roles = $roles->unique()->values()->all();

        // Get IDs of terms the user has already accepted
        $acceptedIds = TermsAcceptance::where('user_id', $user->id)->pluck('terms_condition_id');

        // Build query: active terms that apply to any of this user's roles and haven't been accepted
        $pending = TermsCondition::active()
            ->whereNotIn('id', $acceptedIds)
            ->where(function ($q) use ($roles, $orgId) {
                // Platform-level terms matching any of the user's roles
                $q->where(function ($sub) use ($roles) {
                    $sub->where('owner_type', 'platform');
                    $sub->where(function ($roleQ) use ($roles) {
                        foreach ($roles as $role) {
                            $roleQ->orWhereJsonContains('applies_to', $role);
                        }
                    });
                });

                // Org-level terms (only if user is in an org)
                if ($orgId) {
                    $q->orWhere(function ($sub) use ($roles, $orgId) {
                        $sub->where('owner_type', 'organization')
                            ->where('organization_id', $orgId);
                        $sub->where(function ($roleQ) use ($roles) {
                            foreach ($roles as $role) {
                                $roleQ->orWhereJsonContains('applies_to', $role);
                            }
                        });
                    });
                }
            })
            ->select(['id', 'title', 'content', 'version', 'owner_type', 'organization_id', 'published_at'])
            ->orderBy('owner_type') // platform first
            ->orderByDesc('version')
            ->get();

        return response()->json([
            'data' => $pending,
            'meta' => ['status' => 200],
            'errors' => [],
        ]);
    }

    public function accept(Request $request, TermsCondition $term): JsonResponse
    {
        $user = $request->user();

        // Verify the term is active
        if (!$term->is_active) {
            return response()->json([
                'data' => null,
                'meta' => ['status' => 422],
                'errors' => [['message' => 'These terms are no longer active.']],
            ], 422);
        }

        // Check not already accepted
        $existing = TermsAcceptance::where('terms_condition_id', $term->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($existing) {
            return response()->json([
                'data' => ['message' => 'Already accepted.'],
                'meta' => ['status' => 200],
                'errors' => [],
            ]);
        }

        TermsAcceptance::create([
            'terms_condition_id' => $term->id,
            'user_id' => $user->id,
            'accepted_at' => now(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'data' => ['message' => 'Terms accepted.'],
            'meta' => ['status' => 200],
            'errors' => [],
        ]);
    }
}
