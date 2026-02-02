<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Access;
use App\Models\Assessment;
use App\Models\AssessmentSubmission;
use App\Models\Child;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssessmentPortalController extends ApiController
{
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id');

        $childQuery = $this->childQueryForUser($user, $orgId);
        $visibleChildIds = $childQuery->pluck('id')->all();

        $accessRecords = Access::whereIn('child_id', $visibleChildIds)
            ->where('access', true)
            ->where('payment_status', 'paid')
            ->get();

        $childLessonMap = [];
        $childAssessmentMap = [];

        foreach ($accessRecords as $access) {
            $cid = (string) $access->child_id;

            if ($access->lesson_id) {
                $childLessonMap[$cid][] = $access->lesson_id;
            }
            if ($access->lesson_ids) {
                foreach ((array) $access->lesson_ids as $lid) {
                    $childLessonMap[$cid][] = $lid;
                }
            }
            if ($access->assessment_id) {
                $childAssessmentMap[$cid][] = $access->assessment_id;
            }
            if ($access->assessment_ids) {
                foreach ((array) $access->assessment_ids as $aid) {
                    $childAssessmentMap[$cid][] = $aid;
                }
            }
        }

        $allLessonIds = collect($childLessonMap)->flatten()->unique()->values();
        $allAssessmentIds = collect($childAssessmentMap)->flatten()->unique()->values();

        $lessons = Lesson::whereIn('id', $allLessonIds)
            ->orderBy('start_time')
            ->get()
            ->map(function ($lesson) use ($childLessonMap) {
                $allowedChildIds = collect($childLessonMap)
                    ->filter(fn ($lessonIds) => in_array($lesson->id, $lessonIds))
                    ->keys()
                    ->values();

                return [
                    'id' => $lesson->id,
                    'title' => $lesson->title ?? null,
                    'start_time' => $lesson->start_time?->toISOString(),
                    'end_time' => $lesson->end_time?->toISOString(),
                    'allowed_child_ids' => $allowedChildIds,
                ];
            });

        $assessments = Assessment::whereIn('id', $allAssessmentIds)
            ->orderBy('deadline')
            ->get()
            ->map(function ($assessment) use ($childAssessmentMap) {
                $childIds = collect($childAssessmentMap)
                    ->filter(fn ($assessmentIds) => in_array($assessment->id, $assessmentIds))
                    ->keys()
                    ->values();

                return [
                    'id' => $assessment->id,
                    'title' => $assessment->title,
                    'availability' => $assessment->availability?->toISOString(),
                    'deadline' => $assessment->deadline?->toISOString(),
                    'lesson' => $assessment->lesson,
                    'child_ids' => $childIds,
                ];
            });

        $submissions = AssessmentSubmission::with([
                'child:id,child_name,year_group',
                'assessment:id,title',
            ])
            ->whereHas('child', fn ($q) => $q->whereIn('id', $visibleChildIds))
            ->latest('finished_at')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'child' => $s->child,
                'assessment' => $s->assessment,
                'marks_obtained' => $s->marks_obtained,
                'total_marks' => $s->total_marks,
                'status' => $s->status,
                'retake_number' => $s->retake_number,
                'created_at' => $s->created_at?->toISOString(),
                'finished_at' => $s->finished_at?->toISOString(),
                'graded_at' => $s->graded_at?->toISOString(),
            ]);

        $courseIds = collect();
        foreach ($accessRecords as $access) {
            if ($access->course_id) {
                $courseIds->push($access->course_id);
            }
            if ($access->course_ids) {
                $courseIds = $courseIds->merge($access->course_ids);
            }
        }

        $courseIds = $courseIds->unique()->values();
        $coursesQuery = Course::whereIn('id', $courseIds)->with(['modules.lessons', 'modules.assessments']);
        if ($orgId) {
            $coursesQuery->where('organization_id', $orgId);
        }
        $courses = $coursesQuery->get();

        $courses = $courses->map(function ($course) use ($accessRecords) {
            $childIds = [];

            foreach ($accessRecords as $access) {
                $courseIds = collect($access->course_ids ?? []);
                if ($access->course_id) {
                    $courseIds->push($access->course_id);
                }

                if ($courseIds->contains($course->id)) {
                    $childIds[] = $access->child_id;
                }
            }

            $totalLessons = $course->modules->sum(fn ($module) => $module->lessons->count());
            $totalAssessments = $course->modules->sum(fn ($module) => $module->assessments->count());

            return [
                'id' => $course->id,
                'title' => $course->title,
                'description' => $course->description,
                'thumbnail' => $course->thumbnail,
                'category' => $course->category,
                'level' => $course->level,
                'estimated_duration_minutes' => $course->estimated_duration_minutes,
                'content_stats' => [
                    'lessons' => $totalLessons,
                    'assessments' => $totalAssessments,
                ],
                'child_ids' => collect($childIds)->unique()->values(),
            ];
        });

        return $this->success([
            'assessments' => $assessments,
            'lessons' => $lessons,
            'submissions' => $submissions,
            'courses' => $courses,
        ]);
    }

    public function browse(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id');

        $assessmentQuery = Assessment::query();
        if ($user?->isSuperAdmin()) {
            $requestedOrgId = $request->filled('organization_id')
                ? $request->integer('organization_id')
                : $orgId;
            if ($requestedOrgId) {
                $assessmentQuery->where('organization_id', $requestedOrgId);
            }
        } else {
            $assessmentQuery->visibleToOrg($orgId);
        }

        $assessments = $assessmentQuery->get()->map(function ($assessment) {
            return [
                'id' => $assessment->id,
                'title' => $assessment->title,
                'description' => $assessment->description,
                'availability' => $assessment->availability?->toISOString(),
                'deadline' => $assessment->deadline?->toISOString(),
                'time_limit' => $assessment->time_limit,
                'retake_allowed' => (bool) $assessment->retake_allowed,
            ];
        });

        $serviceQuery = Service::whereIn('_type', ['bundle', 'assessment'])
            ->with(['assessments', 'children:id']);
        if ($user?->isSuperAdmin()) {
            if ($orgId) {
                $serviceQuery->where('organization_id', $orgId);
            }
        } else {
            $serviceQuery->visibleToOrg($orgId);
        }
        $services = $serviceQuery->get()->map(function ($service) {
            $service->child_ids = $service->children
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->values();
            unset($service->children);

            return [
                'id' => $service->id,
                'service_name' => $service->service_name,
                'description' => $service->description,
                '_type' => $service->_type,
                'availability' => (bool) $service->availability,
                'price' => $service->price,
                'restriction_type' => $service->restriction_type,
                'year_groups_allowed' => $service->year_groups_allowed,
                'child_ids' => $service->child_ids,
                'assessments' => $service->assessments->map(fn ($assessment) => [
                    'id' => $assessment->id,
                    'title' => $assessment->title,
                ])->values(),
            ];
        });

        return $this->success([
            'assessments' => $assessments,
            'services' => $services,
        ]);
    }

    private function childQueryForUser(?User $user, ?int $orgId)
    {
        if (!$user) {
            return Child::query()->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            $query = Child::query();
            if ($orgId) {
                $query->where('organization_id', $orgId);
            }
            return $query;
        }

        if ($user->isAdmin()) {
            $query = Child::query()->where('organization_id', $orgId);
            return $query;
        }

        if ($user->isParent() || $user->role === User::ROLE_GUEST_PARENT) {
            $query = $user->children();
            if ($orgId) {
                $query->where('organization_id', $orgId);
            }
            return $query;
        }

        return Child::query()->whereRaw('1 = 0');
    }
}
