<?php

namespace App\Http\Resources;

use App\Models\Course;
use App\Models\Module;
use App\Models\ContentLesson;
use App\Models\LiveLessonSession;

class AccessResource extends ApiResource
{
    public function toArray($request): array
    {
        $contentLessonIds = [];
        if (is_array($this->metadata) && !empty($this->metadata['content_lesson_ids'])) {
            $contentLessonIds = array_filter($this->metadata['content_lesson_ids'], fn ($id) => $id);
        }
        if ($this->content_lesson_id) {
            $contentLessonIds[] = $this->content_lesson_id;
        }
        $contentLessonIds = array_values(array_unique($contentLessonIds));

        $liveSessionIds = [];
        if (is_array($this->metadata) && !empty($this->metadata['live_lesson_session_ids'])) {
            $liveSessionIds = array_filter($this->metadata['live_lesson_session_ids'], fn ($id) => $id);
        }

        $courses = !empty($this->course_ids)
            ? Course::whereIn('id', $this->course_ids)->get(['id', 'title'])
            : collect();
        $modules = !empty($this->module_ids)
            ? Module::whereIn('id', $this->module_ids)->get(['id', 'title', 'course_id'])
            : collect();
        $contentLessons = !empty($contentLessonIds)
            ? ContentLesson::whereIn('id', $contentLessonIds)->get(['id', 'title'])
            : collect();
        $liveSessions = !empty($liveSessionIds)
            ? LiveLessonSession::whereIn('id', $liveSessionIds)->get(['id', 'session_code', 'scheduled_start_time', 'status'])
            : collect();

        return [
            'id' => $this->id,
            'child_id' => $this->child_id,
            'lesson_id' => $this->lesson_id,
            'content_lesson_id' => $this->content_lesson_id,
            'content_lesson_ids' => $contentLessonIds,
            'live_lesson_session_ids' => $liveSessionIds,
            'assessment_id' => $this->assessment_id,
            'lesson_ids' => $this->lesson_ids,
            'course_ids' => $this->course_ids,
            'module_ids' => $this->module_ids,
            'assessment_ids' => $this->assessment_ids,
            'transaction_id' => $this->transaction_id,
            'invoice_id' => $this->invoice_id,
            'purchase_date' => $this->purchase_date?->toISOString(),
            'due_date' => $this->due_date?->toDateString(),
            'access' => (bool) $this->access,
            'payment_status' => $this->payment_status,
            'refund_id' => $this->refund_id,
            'metadata' => $this->metadata,
            'courses' => $courses->map(fn ($course) => ['id' => $course->id, 'title' => $course->title])->values(),
            'modules' => $modules->map(fn ($module) => [
                'id' => $module->id,
                'title' => $module->title,
                'course_id' => $module->course_id,
            ])->values(),
            'content_lessons' => $contentLessons->map(fn ($lesson) => [
                'id' => $lesson->id,
                'title' => $lesson->title,
            ])->values(),
            'live_lesson_sessions' => $liveSessions->map(fn ($session) => [
                'id' => $session->id,
                'session_code' => $session->session_code,
                'status' => $session->status,
                'scheduled_start_time' => $session->scheduled_start_time?->toISOString(),
            ])->values(),
            'child' => $this->whenLoaded('child', function () {
                return [
                    'id' => $this->child?->id,
                    'child_name' => $this->child?->child_name,
                    'organization_id' => $this->child?->organization_id,
                    'user' => $this->child?->user
                        ? [
                            'id' => $this->child->user->id,
                            'name' => $this->child->user->name,
                            'email' => $this->child->user->email,
                        ]
                        : null,
                ];
            }),
            'lesson_session' => $this->whenLoaded('lesson', function () {
                return [
                    'id' => $this->lesson?->id,
                    'title' => $this->lesson?->title,
                    'status' => $this->lesson?->status,
                    'start_time' => $this->lesson?->start_time?->toISOString(),
                    'end_time' => $this->lesson?->end_time?->toISOString(),
                ];
            }),
            'content_lesson' => $this->whenLoaded('contentLesson', function () {
                return [
                    'id' => $this->contentLesson?->id,
                    'title' => $this->contentLesson?->title,
                ];
            }),
            'assessment' => $this->whenLoaded('assessment', function () {
                return [
                    'id' => $this->assessment?->id,
                    'title' => $this->assessment?->title,
                ];
            }),
        ];
    }
}
