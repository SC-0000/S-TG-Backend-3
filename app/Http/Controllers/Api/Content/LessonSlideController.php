<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\ApiController;
use App\Models\LessonSlide;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LessonSlideController extends ApiController
{
    public function show(Request $request, LessonSlide $slide): JsonResponse
    {
        $orgId = $request->header('X-Organization-Id') ?? $request->query('organization_id');
        if ($orgId && $slide->lesson && (int) $slide->lesson->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $slide->load('lesson:id,organization_id');

        return $this->success([
            'id' => $slide->id,
            'uid' => $slide->uid,
            'lesson_id' => $slide->lesson_id,
            'title' => $slide->title,
            'order_position' => $slide->order_position,
            'blocks' => $slide->blocks,
            'template_id' => $slide->template_id,
            'layout_settings' => $slide->layout_settings,
            'teacher_notes' => $slide->teacher_notes,
            'estimated_seconds' => $slide->estimated_seconds,
            'auto_advance' => (bool) $slide->auto_advance,
            'min_time_seconds' => $slide->min_time_seconds,
            'settings' => $slide->settings,
        ]);
    }
}
