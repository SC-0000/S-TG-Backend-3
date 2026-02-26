<?php

namespace App\Http\Resources;

class LiveLessonSessionResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'uid' => $this->uid,
            'lesson_id' => $this->lesson_id,
            'course_id' => $this->course_id,
            'teacher_id' => $this->teacher_id,
            'organization_id' => $this->organization_id,
            'year_group' => $this->year_group,
            'session_code' => $this->session_code,
            'status' => $this->status,
            'scheduled_start_time' => $this->scheduled_start_time?->toISOString(),
            'actual_start_time' => $this->actual_start_time?->toISOString(),
            'end_time' => $this->end_time?->toISOString(),
            'current_slide_id' => $this->current_slide_id,
            'pacing_mode' => $this->pacing_mode,
            'navigation_locked' => (bool) $this->navigation_locked,
            'annotations_locked' => (bool) $this->annotations_locked,
            'audio_enabled' => (bool) $this->audio_enabled,
            'video_enabled' => (bool) $this->video_enabled,
            'allow_student_questions' => (bool) $this->allow_student_questions,
            'whiteboard_enabled' => (bool) $this->whiteboard_enabled,
            'record_session' => (bool) $this->record_session,
            'recording_url' => $this->recording_url,
            'lesson' => $this->whenLoaded('lesson', function () {
                $lesson = [
                    'id' => $this->lesson?->id,
                    'title' => $this->lesson?->title,
                    'description' => $this->lesson?->description,
                ];

                if ($this->lesson && $this->lesson->relationLoaded('slides')) {
                    $lesson['slides'] = $this->lesson->slides
                        ->sortBy('order_position')
                        ->map(function ($slide) {
                            return [
                                'id' => $slide->id,
                                'uid' => $slide->uid,
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
                            ];
                        })
                        ->values();
                }

                return $lesson;
            }),
            'teacher' => $this->whenLoaded('teacher', function () {
                return [
                    'id' => $this->teacher?->id,
                    'name' => $this->teacher?->name,
                    'email' => $this->teacher?->email,
                ];
            }),
            'organization' => $this->whenLoaded('organization', function () {
                return [
                    'id' => $this->organization?->id,
                    'name' => $this->organization?->name,
                ];
            }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
