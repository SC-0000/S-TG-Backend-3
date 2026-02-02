<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\ApiController;
use App\Models\Module;
use App\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ModuleController extends ApiController
{
    public function show(Request $request, Module $module): JsonResponse
    {
        $orgId = $request->header('X-Organization-Id') ?? $request->query('organization_id');
        if ($orgId && (int) $module->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $module->load([
            'lessons:id,uid,title,description,estimated_minutes,status',
            'assessments:id,title,description,status',
        ]);

        return $this->success([
            'id' => $module->id,
            'uid' => $module->uid,
            'course_id' => $module->course_id,
            'organization_id' => $module->organization_id,
            'title' => $module->title,
            'description' => $module->description,
            'order_position' => $module->order_position,
            'status' => $module->status,
            'estimated_duration_minutes' => $module->estimated_duration_minutes,
            'lessons' => $module->lessons->map(fn ($lesson) => [
                'id' => $lesson->id,
                'uid' => $lesson->uid,
                'title' => $lesson->title,
                'description' => $lesson->description,
                'estimated_minutes' => $lesson->estimated_minutes,
                'status' => $lesson->status,
            ]),
            'assessments' => $module->assessments->map(fn ($assessment) => [
                'id' => $assessment->id,
                'title' => $assessment->title,
                'description' => $assessment->description,
                'status' => $assessment->status,
            ]),
        ]);
    }

    public function lessons(Request $request, Module $module): JsonResponse
    {
        $orgId = $request->header('X-Organization-Id') ?? $request->query('organization_id');
        if ($orgId && (int) $module->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $lessons = $module->lessons()->paginate(ApiPagination::perPage($request));
        $data = $lessons->getCollection()->map(fn ($lesson) => [
            'id' => $lesson->id,
            'uid' => $lesson->uid,
            'title' => $lesson->title,
            'description' => $lesson->description,
            'estimated_minutes' => $lesson->estimated_minutes,
            'status' => $lesson->status,
        ])->all();

        return $this->paginated($lessons, $data);
    }

    public function assessments(Request $request, Module $module): JsonResponse
    {
        $orgId = $request->header('X-Organization-Id') ?? $request->query('organization_id');
        if ($orgId && (int) $module->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $assessments = $module->assessments()->paginate(ApiPagination::perPage($request));
        $data = $assessments->getCollection()->map(fn ($assessment) => [
            'id' => $assessment->id,
            'title' => $assessment->title,
            'description' => $assessment->description,
            'status' => $assessment->status,
        ])->all();

        return $this->paginated($assessments, $data);
    }
}
