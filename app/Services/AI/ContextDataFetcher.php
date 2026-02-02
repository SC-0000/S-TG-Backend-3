<?php

namespace App\Services\AI;

use App\Models\Child;
use App\Models\Assessment;
use App\Models\AssessmentSubmission;
use App\Models\AssessmentSubmissionItem;
use App\Models\Lesson;
use App\Models\Access;
use App\Models\Question;
use Illuminate\Support\Facades\Log;

/**
 * ContextDataFetcher - Stage 2 of Two-Stage AI System
 * Fetches only the specific database information determined by DataRequirementsAnalyzer
 */
class ContextDataFetcher
{
    /**
     * Fetch data based on requirements
     */
    public function fetch(Child $child, array $requirements): array
    {
        $context = [];
        $filters = $requirements['filters'] ?? [];
        
        foreach ($requirements['required_data'] as $source) {
            if ($source === 'none') {
                continue;
            }
            
            try {
                $context[$source] = $this->fetchDataSource($child, $source, $filters);
            } catch (\Exception $e) {
                Log::error("Failed to fetch data source: {$source}", [
                    'child_id' => $child->id,
                    'error' => $e->getMessage()
                ]);
                $context[$source] = ['error' => 'Failed to retrieve data'];
            }
        }
        
        return $context;
    }
    
    /**
     * Route to appropriate fetcher based on source
     */
    protected function fetchDataSource(Child $child, string $source, array $filters): array
    {
        return match($source) {
            'submission_history' => $this->fetchSubmissionHistory($child, $filters),
            'submission_details' => $this->fetchSubmissionDetails($child, $filters),
            'lesson_schedule' => $this->fetchLessonSchedule($child, $filters),
            'assessment_catalog' => $this->fetchAssessmentCatalog($child, $filters),
            'access_records' => $this->fetchAccessRecords($child, $filters),
            'performance_trends' => $this->fetchPerformanceTrends($child, $filters),
            'question_bank' => $this->fetchQuestionBank($child, $filters),
            default => []
        };
    }
    
    /**
     * Fetch submission history with category filtering
     */
    protected function fetchSubmissionHistory(Child $child, array $filters): array
    {
        $query = AssessmentSubmission::where('child_id', $child->id)
            ->with([
                'assessment:id,title,description,type,journey_category_id',
                'assessment.category:id,name'
            ]);
        
        // Apply category filter via assessment relationship
        if (isset($filters['category'])) {
            $query->whereHas('assessment.category', function($q) use ($filters) {
                $q->where('name', 'LIKE', '%' . $filters['category'] . '%');
            });
        }
        
        // Apply time_range filter
        if (isset($filters['time_range'])) {
            $query = $this->applyTimeFilter($query, $filters['time_range'], 'created_at');
        }
        
        // Apply status filter
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            $query->where('status', '!=', 'pending');
        }
        
        // Apply assessment_id filter
        if (isset($filters['assessment_id'])) {
            $query->where('assessment_id', $filters['assessment_id']);
        }
        
        $limit = $filters['limit'] ?? 10;
        $submissions = $query->orderBy('finished_at', 'desc')
            ->limit($limit)
            ->get();
        
        if ($submissions->isEmpty()) {
            return ['message' => 'No submissions found with the specified criteria'];
        }
        
        return $submissions->map(function($sub) {
            $percentage = $sub->total_marks > 0 
                ? round(($sub->marks_obtained / $sub->total_marks) * 100, 1)
                : 0;
                
            return [
                'assessment_title' => $sub->assessment->title,
                'category' => $sub->assessment->category?->name ?? 'General',
                'type' => $sub->assessment->type,
                'score' => "{$sub->marks_obtained}/{$sub->total_marks}",
                'percentage' => "{$percentage}%",
                'date' => $sub->finished_at?->diffForHumans(),
                'retake' => $sub->retake_number
            ];
        })->toArray();
    }
    
    /**
     * Fetch submission details with question category filtering
     */
    protected function fetchSubmissionDetails(Child $child, array $filters): array
    {
        $query = AssessmentSubmissionItem::whereHas('submission', function($q) use ($child) {
            $q->where('child_id', $child->id);
        })->with([
            'submission.assessment:id,title',
            'question:id,title,category,subcategory,grade,question_type,difficulty_level'
        ]);
        
        // Filter by question category
        if (isset($filters['category'])) {
            $query->whereHas('question', function($q) use ($filters) {
                $q->where('category', 'LIKE', '%' . $filters['category'] . '%');
            });
        }
        
        // Filter by question subcategory
        if (isset($filters['subcategory'])) {
            $query->whereHas('question', function($q) use ($filters) {
                $q->where('subcategory', 'LIKE', '%' . $filters['subcategory'] . '%');
            });
        }
        
        // Filter by grade
        if (isset($filters['grade'])) {
            $query->whereHas('question', function($q) use ($filters) {
                $q->where('grade', $filters['grade']);
            });
        }
        
        // Filter by question_type
        if (isset($filters['question_type'])) {
            $query->whereHas('question', function($q) use ($filters) {
                $q->where('question_type', $filters['question_type']);
            });
        }
        
        // Filter by difficulty_level
        if (isset($filters['difficulty_level'])) {
            $query->whereHas('question', function($q) use ($filters) {
                $q->where('difficulty_level', $filters['difficulty_level']);
            });
        }
        
        // Filter by submission_id
        if (isset($filters['submission_id'])) {
            $query->where('submission_id', $filters['submission_id']);
        }
        
        // Filter by correctness
        if (isset($filters['is_correct'])) {
            $query->where('is_correct', $filters['is_correct']);
        }
        
        // Filter by question_id
        if (isset($filters['question_id'])) {
            $query->where('question_id', $filters['question_id']);
        }
        
        $limit = $filters['limit'] ?? 20;
        $items = $query->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
        
        if ($items->isEmpty()) {
            return ['message' => 'No question details found with the specified criteria'];
        }
        
        return $items->map(function($item) {
            return [
                'question' => $item->question?->title ?? 'Question',
                'category' => $item->question?->category ?? 'General',
                'subcategory' => $item->question?->subcategory,
                'grade' => $item->question?->grade,
                'question_type' => $item->question?->question_type,
                'difficulty' => $item->question?->difficulty_level,
                'student_answer' => $item->answer,
                'is_correct' => $item->is_correct,
                'marks' => "{$item->marks_awarded}",
                'feedback' => $item->detailed_feedback,
                'time_spent' => $item->time_spent ? "{$item->time_spent}s" : null,
                'assessment' => $item->submission->assessment?->title
            ];
        })->toArray();
    }
    
    /**
     * Fetch lesson schedule with category filtering
     */
    protected function fetchLessonSchedule(Child $child, array $filters): array
    {
        // Get lessons child has access to
        $accessRecords = Access::where('child_id', $child->id)
            ->where('access', true)
            ->where('payment_status', 'paid')
            ->get();
        
        $lessonIds = collect();
        foreach ($accessRecords as $access) {
            if ($access->lesson_id) $lessonIds->push($access->lesson_id);
            if ($access->lesson_ids) {
                foreach ((array) $access->lesson_ids as $lid) {
                    $lessonIds->push($lid);
                }
            }
        }
        
        if ($lessonIds->isEmpty()) {
            return ['message' => 'No lessons found with access'];
        }
        
        $query = Lesson::whereIn('id', $lessonIds->unique())
            ->with('category:id,name');
        
        // Apply category filter
        if (isset($filters['category'])) {
            $query->whereHas('category', function($q) use ($filters) {
                $q->where('name', 'LIKE', '%' . $filters['category'] . '%');
            });
        }
        
        // Special handling for "next lesson" queries - don't apply time filter
        // Just get upcoming lessons (future only)
        if (isset($filters['next_only']) && $filters['next_only'] === true) {
            $query->where('start_time', '>=', now());
            $limit = 1; // Only return the next lesson
        } else {
            // Apply time filter for other queries
            if (isset($filters['time_range'])) {
                $query = $this->applyTimeFilter($query, $filters['time_range'], 'start_time');
            }
            $limit = $filters['limit'] ?? 10;
        }
        
        // Apply status filter
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        
        $lessons = $query->orderBy('start_time', 'asc')
            ->limit($limit)
            ->get();
        
        if ($lessons->isEmpty()) {
            return ['message' => 'No lessons found with the specified criteria'];
        }
        
        return $lessons->map(function($lesson) {
            return [
                'title' => $lesson->title,
                'description' => $lesson->description,
                'category' => $lesson->category?->name ?? 'General',
                'type' => "{$lesson->lesson_type} ({$lesson->lesson_mode})",
                'start_time' => $lesson->start_time?->format('Y-m-d H:i'),
                'status' => $lesson->status
            ];
        })->toArray();
    }
    
    /**
     * Fetch assessment catalog with category filtering
     */
    protected function fetchAssessmentCatalog(Child $child, array $filters): array
    {
        // Get assessments child has access to
        $accessRecords = Access::where('child_id', $child->id)
            ->where('access', true)
            ->where('payment_status', 'paid')
            ->get();
        
        $assessmentIds = collect();
        foreach ($accessRecords as $access) {
            if ($access->assessment_id) $assessmentIds->push($access->assessment_id);
            if ($access->assessment_ids) {
                foreach ((array) $access->assessment_ids as $aid) {
                    $assessmentIds->push($aid);
                }
            }
        }
        
        if ($assessmentIds->isEmpty()) {
            return ['message' => 'No assessments found with access'];
        }
        
        $query = Assessment::whereIn('id', $assessmentIds->unique())
            ->with('category:id,name');
        
        // Apply category filter
        if (isset($filters['category'])) {
            $query->whereHas('category', function($q) use ($filters) {
                $q->where('name', 'LIKE', '%' . $filters['category'] . '%');
            });
        }
        
        // Apply status filter
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        
        // Apply type filter
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        
        // Apply time filter
        if (isset($filters['time_range'])) {
            $query = $this->applyTimeFilter($query, $filters['time_range'], 'availability');
        }
        
        $limit = $filters['limit'] ?? 10;
        $assessments = $query->orderBy('availability', 'asc')
            ->limit($limit)
            ->get();
        
        if ($assessments->isEmpty()) {
            return ['message' => 'No assessments found with the specified criteria'];
        }
        
        return $assessments->map(function($assessment) {
            return [
                'title' => $assessment->title,
                'description' => $assessment->description,
                'category' => $assessment->category?->name ?? 'General',
                'type' => $assessment->type,
                'availability' => $assessment->availability?->format('Y-m-d H:i'),
                'deadline' => $assessment->deadline?->format('Y-m-d H:i'),
                'time_limit' => $assessment->time_limit ? "{$assessment->time_limit} minutes" : 'No limit',
                'retake_allowed' => $assessment->retake_allowed ? 'Yes' : 'No'
            ];
        })->toArray();
    }
    
    /**
     * Fetch access records
     */
    protected function fetchAccessRecords(Child $child, array $filters): array
    {
        $query = Access::where('child_id', $child->id);
        
        if (isset($filters['payment_status'])) {
            $query->where('payment_status', $filters['payment_status']);
        } else {
            $query->where('payment_status', 'paid')->where('access', true);
        }
        
        $limit = $filters['limit'] ?? 20;
        $records = $query->orderBy('purchase_date', 'desc')
            ->limit($limit)
            ->get();
        
        if ($records->isEmpty()) {
            return ['message' => 'No access records found'];
        }
        
        return $records->map(function($access) {
            return [
                'type' => $access->lesson_id || $access->lesson_ids ? 'lesson' : 'assessment',
                'purchase_date' => $access->purchase_date?->format('Y-m-d'),
                'status' => $access->payment_status,
                'has_access' => $access->access ? 'Yes' : 'No'
            ];
        })->toArray();
    }
    
    /**
     * Fetch performance trends with category breakdown
     */
    protected function fetchPerformanceTrends(Child $child, array $filters): array
    {
        $days = match($filters['time_range'] ?? 'last_30_days') {
            'last_7_days' => 7,
            'last_30_days' => 30,
            'last_90_days' => 90,
            default => 30
        };
        
        $query = AssessmentSubmission::where('child_id', $child->id)
            ->where('status', 'graded')
            ->where('created_at', '>=', now()->subDays($days))
            ->with([
                'assessment:id,title,type,journey_category_id',
                'assessment.category:id,name'
            ])
            ->orderBy('finished_at', 'asc');
        
        // Apply category filter
        if (isset($filters['category'])) {
            $query->whereHas('assessment.category', function($q) use ($filters) {
                $q->where('name', 'LIKE', '%' . $filters['category'] . '%');
            });
        }
        
        $submissions = $query->get();
        
        if ($submissions->isEmpty()) {
            return ['message' => 'No performance data available for the specified criteria'];
        }
        
        // Calculate metrics
        $totalScore = 0;
        $totalMarks = 0;
        $scores = [];
        $categoryBreakdown = [];
        
        foreach ($submissions as $sub) {
            if ($sub->total_marks > 0) {
                $percentage = ($sub->marks_obtained / $sub->total_marks) * 100;
                $totalScore += $sub->marks_obtained;
                $totalMarks += $sub->total_marks;
                $scores[] = $percentage;
                
                // Category breakdown
                $category = $sub->assessment->category?->name ?? 'General';
                if (!isset($categoryBreakdown[$category])) {
                    $categoryBreakdown[$category] = [
                        'total' => 0,
                        'count' => 0,
                        'scores' => []
                    ];
                }
                $categoryBreakdown[$category]['total'] += $percentage;
                $categoryBreakdown[$category]['count']++;
                $categoryBreakdown[$category]['scores'][] = $percentage;
            }
        }
        
        $averagePerformance = $totalMarks > 0 ? round(($totalScore / $totalMarks) * 100, 1) : 0;
        
        // Calculate trend
        $trend = 'stable';
        if (count($scores) >= 2) {
            $firstHalf = array_slice($scores, 0, ceil(count($scores) / 2));
            $secondHalf = array_slice($scores, ceil(count($scores) / 2));
            $firstAvg = array_sum($firstHalf) / count($firstHalf);
            $secondAvg = array_sum($secondHalf) / count($secondHalf);
            
            if ($secondAvg - $firstAvg > 5) $trend = 'improving';
            elseif ($firstAvg - $secondAvg > 5) $trend = 'declining';
        }
        
        // Format category breakdown
        $categoryStats = [];
        foreach ($categoryBreakdown as $category => $data) {
            $categoryStats[$category] = [
                'average' => round($data['total'] / $data['count'], 1) . '%',
                'assessments' => $data['count'],
                'trend' => $this->calculateCategoryTrend($data['scores'])
            ];
        }
        
        return [
            'average_score' => "{$averagePerformance}%",
            'total_assessments' => $submissions->count(),
            'overall_trend' => $trend,
            'recent_scores' => array_map(fn($s) => round($s, 1), array_slice($scores, -5)),
            'time_period' => "{$days} days",
            'category_breakdown' => $categoryStats
        ];
    }
    
    /**
     * Fetch question bank
     */
    protected function fetchQuestionBank(Child $child, array $filters): array
    {
        // Only return questions the child has attempted or has access to
        $query = Question::whereHas('submissionItems.submission', function($q) use ($child) {
            $q->where('child_id', $child->id);
        })
        ->distinct();
        
        // Apply category filter
        if (isset($filters['category'])) {
            $query->where('category', 'LIKE', '%' . $filters['category'] . '%');
        }
        
        // Apply subcategory filter
        if (isset($filters['subcategory'])) {
            $query->where('subcategory', 'LIKE', '%' . $filters['subcategory'] . '%');
        }
        
        // Apply grade filter
        if (isset($filters['grade'])) {
            $query->where('grade', $filters['grade']);
        }
        
        // Apply question_type filter
        if (isset($filters['question_type'])) {
            $query->where('question_type', $filters['question_type']);
        }
        
        // Apply difficulty_level filter
        if (isset($filters['difficulty_level'])) {
            $query->where('difficulty_level', $filters['difficulty_level']);
        }
        
        // Apply status filter (only show active questions by default)
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            $query->where('status', 'active');
        }
        
        $limit = $filters['limit'] ?? 20;
        $questions = $query->orderBy('difficulty_level', 'desc')
            ->limit($limit)
            ->get();
        
        if ($questions->isEmpty()) {
            return ['message' => 'No questions found with the specified criteria'];
        }
        
        return $questions->map(function($question) {
            return [
                'title' => $question->title,
                'category' => $question->category,
                'subcategory' => $question->subcategory,
                'grade' => $question->grade,
                'question_type' => $question->question_type,
                'difficulty_level' => $question->difficulty_level,
                'marks' => $question->marks,
                'estimated_time' => $question->estimated_time_minutes ? "{$question->estimated_time_minutes} min" : null,
                'tags' => $question->tags ? json_decode($question->tags) : []
            ];
        })->toArray();
    }
    
    /**
     * Apply time filter to query
     */
    protected function applyTimeFilter($query, string $timeRange, string $dateColumn)
    {
        $now = now();
        
        return match($timeRange) {
            'last_7_days' => $query->where($dateColumn, '>=', $now->copy()->subDays(7)),
            'last_30_days' => $query->where($dateColumn, '>=', $now->copy()->subDays(30)),
            'last_90_days' => $query->where($dateColumn, '>=', $now->copy()->subDays(90)),
            'upcoming_7_days' => $query->whereBetween($dateColumn, [$now, $now->copy()->addDays(7)]),
            'upcoming_30_days' => $query->whereBetween($dateColumn, [$now, $now->copy()->addDays(30)]),
            default => $query
        };
    }
    
    /**
     * Calculate category trend
     */
    protected function calculateCategoryTrend(array $scores): string
    {
        if (count($scores) < 2) return 'stable';
        
        $firstHalf = array_slice($scores, 0, ceil(count($scores) / 2));
        $secondHalf = array_slice($scores, ceil(count($scores) / 2));
        $firstAvg = array_sum($firstHalf) / count($firstHalf);
        $secondAvg = array_sum($secondHalf) / count($secondHalf);
        
        if ($secondAvg - $firstAvg > 5) return 'improving';
        if ($firstAvg - $secondAvg > 5) return 'declining';
        return 'stable';
    }
}
