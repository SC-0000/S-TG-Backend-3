<?php

namespace App\Http\Resources;

class TeacherStudentResource extends ApiResource
{
    public function toArray($request): array
    {
        $performanceMatrix = [
            'Attendance' => [
                'score' => $this->calculateAttendanceScore(),
                'trend' => $this->calculateAttendanceTrend(),
            ],
            'Assignments' => [
                'score' => $this->calculateAssignmentScore(),
                'trend' => $this->calculateAssignmentTrend(),
            ],
            'Assessments' => [
                'score' => $this->calculateAssessmentScore(),
                'trend' => $this->calculateAssessmentTrend(),
            ],
            'Participation' => [
                'score' => $this->calculateParticipationScore(),
                'trend' => $this->calculateParticipationTrend(),
            ],
        ];

        return [
            'id' => $this->id,
            'child_name' => $this->child_name,
            'name' => $this->full_name,
            'date_of_birth' => $this->date_of_birth,
            'year_group' => $this->year_group,
            'pivot' => $this->pivot ? [
                'assigned_at' => $this->pivot->assigned_at ?? null,
                'assigned_by' => $this->pivot->assigned_by ?? null,
                'notes' => $this->pivot->notes ?? null,
                'organization_id' => $this->pivot->organization_id ?? null,
            ] : null,
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user?->id,
                    'name' => $this->user?->name,
                    'email' => $this->user?->email,
                ];
            }),
            'assigned_teachers' => $this->whenLoaded('assignedTeachers', function () {
                return $this->assignedTeachers->map(function ($teacher) {
                    return [
                        'id' => $teacher->id,
                        'name' => $teacher->name,
                        'pivot' => $teacher->pivot,
                    ];
                })->values();
            }),
            'performance_matrix' => $performanceMatrix,
            'performance_score' => $this->calculateOverallPerformance(),
            'risk_factors' => $this->identifyRiskFactors(),
            'achievements' => $this->getRecentAchievements(),
            'improvement' => $this->calculateImprovement(),
            'contact_email' => $this->user?->email,
            'lesson_progress' => $this->whenLoaded('lessonProgress'),
            'assessment_submissions' => $this->whenLoaded('assessmentSubmissions'),
            'academic_info' => $this->academic_info ?? null,
            'medical_info' => $this->medical_info ?? null,
            'additional_info' => $this->additional_info ?? null,
        ];
    }
}
