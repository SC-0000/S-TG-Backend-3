<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AI\Agents\AdminOrgAgent;
use App\Services\AI\Agents\TeacherOrgAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class OrgAIAgentController extends Controller
{
    public function adminChat(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:2000',
            'history' => 'nullable|array',
            'child_id' => 'nullable|integer',
            'teacher_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid input',
                'details' => $validator->errors(),
            ], 422);
        }

        $user = Auth::user();
        $agent = new AdminOrgAgent();

        $forcedFilters = [];
        if ($request->filled('child_id')) {
            $forcedFilters['child_id'] = (int) $request->child_id;
        }
        if ($request->filled('teacher_id')) {
            $forcedFilters['teacher_id'] = (int) $request->teacher_id;
        }

        $result = $agent->process($user, [
            'message' => $request->message,
            'history' => $request->history ?? [],
            'forced_filters' => $forcedFilters,
        ]);

        return response()->json($result);
    }

    public function teacherChat(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:2000',
            'history' => 'nullable|array',
            'child_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid input',
                'details' => $validator->errors(),
            ], 422);
        }

        $user = Auth::user();
        $agent = new TeacherOrgAgent();

        $forcedFilters = [];
        if ($request->filled('child_id')) {
            $forcedFilters['child_id'] = (int) $request->child_id;
        }

        $result = $agent->process($user, [
            'message' => $request->message,
            'history' => $request->history ?? [],
            'forced_filters' => $forcedFilters,
        ]);

        return response()->json($result);
    }
}
