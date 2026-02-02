<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Child;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB; 

class YearGroupManagementController extends Controller
{
    /**
     * Get available year group options
     */
    public function getYearGroups()
    {
        $yearGroups = [
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

        return response()->json($yearGroups);
    }

    /**
     * Bulk update year groups for children (Admin access)
     */
    public function bulkUpdate(Request $request)
    {
        Log::info('Year Group Bulk Update Started', [
            'request_data' => $request->all(),
            'user_id' => auth()->id(),
            'user_org_id' => auth()->user()->current_organization_id ?? 'null',
        ]);

        $validator = Validator::make($request->all(), [
            'child_ids' => 'required|array|min:1',
            'child_ids.*' => 'required|integer|exists:children,id',
            'year_group' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            Log::warning('Validation failed', ['errors' => $validator->errors()]);
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $childIds = $request->child_ids;
        $yearGroup = $request->year_group;

        // Get user's organization for multi-tenant isolation
        $organizationId = auth()->user()->current_organization_id;

        Log::info('Looking for children', [
            'child_ids' => $childIds,
            'organization_id' => $organizationId,
        ]);

        // Check what children exist before filtering
        $allChildren = Child::whereIn('id', $childIds)->get(['id', 'organization_id', 'year_group', 'child_name']);
        Log::info('All children found (before org filter)', [
            'count' => $allChildren->count(),
            'children' => $allChildren->toArray(),
        ]);

        // Update only children belonging to the user's organization
        $updated = Child::whereIn('id', $childIds)
            ->where('organization_id', $organizationId)
            ->update(['year_group' => $yearGroup]);

        Log::info('Update complete', [
            'updated_count' => $updated,
            'year_group' => $yearGroup,
        ]);

        return response()->json([
            'success' => true,
            'updated_count' => $updated,
            'message' => "Successfully updated {$updated} student(s) to {$yearGroup}",
        ]);
    }

    /**
     * Bulk update year groups for teacher's assigned students
     */
    public function teacherBulkUpdate(Request $request)
    {
        Log::info('Teacher Year Group Bulk Update Started', [
            'request_data' => $request->all(),
            'teacher_id' => auth()->id(),
            'teacher_org_id' => auth()->user()->current_organization_id ?? 'null',
        ]);

        $validator = Validator::make($request->all(), [
            'child_ids' => 'required|array|min:1',
            'child_ids.*' => 'required|integer|exists:children,id',
            'year_group' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            Log::warning('Teacher validation failed', ['errors' => $validator->errors()]);
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $childIds = $request->child_ids;
        $yearGroup = $request->year_group;
        $teacherId = auth()->id();
        $organizationId = auth()->user()->current_organization_id;

        // Check all children before filtering
        $allChildren = Child::whereIn('id', $childIds)->get(['id', 'organization_id', 'year_group', 'child_name']);
        Log::info('All children found (before filters)', [
            'count' => $allChildren->count(),
            'children' => $allChildren->toArray(),
        ]);

        // Check teacher relationships
        $teacherRelations = DB::table('child_teacher')
            ->where('teacher_id', $teacherId)
            ->whereIn('child_id', $childIds)
            ->get();
        Log::info('Teacher relationships found', [
            'count' => $teacherRelations->count(),
            'relations' => $teacherRelations->toArray(),
        ]);

        // Update only children assigned to this teacher and in their organization
        $updated = Child::whereIn('id', $childIds)
            ->where('organization_id', $organizationId)
            ->whereHas('assignedTeachers', function ($query) use ($teacherId) {
                $query->where('users.id', $teacherId);
            })
            ->update(['year_group' => $yearGroup]);

        Log::info('Teacher update complete', [
            'updated_count' => $updated,
            'year_group' => $yearGroup,
        ]);

        return response()->json([
            'success' => true,
            'updated_count' => $updated,
            'message' => "Successfully updated {$updated} student(s) to {$yearGroup}",
        ]);
    }
}
