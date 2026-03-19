<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\AuditLog;
use App\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAuditLogController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $query = AuditLog::query();

        // Scope to org, super-admins may optionally filter to one org
        $orgId = $request->attributes->get('organization_id');
        if ($user->isSuperAdmin()) {
            if ($request->filled('organization_id')) {
                $query->where('organization_id', $request->integer('organization_id'));
            }
        } elseif ($orgId) {
            $query->where('organization_id', $orgId);
        }

        // ── Filters ──────────────────────────────────────────────────────
        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }

        if ($request->filled('resource_type')) {
            $query->where('resource_type', $request->input('resource_type'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        if ($request->filled('search')) {
            $term = $request->input('search');
            $query->where(function ($q) use ($term) {
                $q->where('resource_name', 'like', "%{$term}%")
                  ->orWhere('user_name', 'like', "%{$term}%");
            });
        }

        // ── Always newest first ───────────────────────────────────────────
        $query->orderBy('created_at', 'desc');

        $paginated = $query->paginate(ApiPagination::perPage($request, 25));

        return $this->paginated($paginated, $paginated->items(), [
            'retention_days' => 90,
        ]);
    }
}
