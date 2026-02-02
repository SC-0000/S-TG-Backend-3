<?php

namespace App\Services\AI\Agents;

use App\Models\Child;
use App\Models\AssessmentSubmission;
use App\Models\AssessmentSubmissionItem;
use App\Models\Assessment;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * ProgressAnalysisAgent - Learning insights and recommendations
 * Integrates with existing assessment reports and MySubmissions.jsx
 */
class ProgressAnalysisAgent extends AbstractAgent
{
    protected string $agentType = 'progress';
    
    protected array $tools = [
        'performance_tracking',
        'learning_pattern_analysis', 
        'strength_identification',
        'weakness_detection',
        'recommendation_generation',
        'goal_setting'
    ];

    /**
     * Main processing method - required by AbstractAgent
     */
    public function process(Child $child, array $context = []): array
    {
        try {
            $analysisType = $context['analysis_type'] ?? 'overall';
            $timeframe = $context['timeframe'] ?? '4weeks';
            $subject = $context['subject'] ?? null;
            $message = $context['message'] ?? '';
            
            // Get comprehensive progress data
            $progressData = $this->gatherProgressData($child, $timeframe, $subject);
            
            if (empty($progressData['submissions'])) {
                return $this->createInsightResponse($child, 'no_data', 
                    "I don't have enough assessment data to analyze your progress yet. Keep taking assessments and I'll provide insights!");
            }

            // Build analysis context
            $analysisContext = $this->buildAnalysisContext($child, $progressData, $analysisType, $message);
            
            // Generate AI insights using the base class method
            $aiResponse = $this->generateAIResponse($child, $message, $analysisContext);
            
            // Extract actionable insights from the response
            $insights = $this->extractInsights($progressData, $analysisType);
            
            // Log the analysis activity
            $this->logActivity($child, 'progress_analysis', [
                'analysis_type' => $analysisType,
                'timeframe' => $timeframe,
                'subject' => $subject,
                'submissions_analyzed' => count($progressData['submissions']),
                'overall_trend' => $insights['trend'],
            ]);

            return [
                'success' => true,
                'response' => $aiResponse,
                'agent_type' => $this->agentType,
                'child_id' => $child->id,
                'metadata' => [
                    'analysis_type' => $analysisType,
                    'timeframe' => $timeframe,
                    'insights' => $insights,
                    'confidence' => 0.88,
                    'actionable_recommendations' => $this->generateQuickRecommendations($insights),
                ]
            ];

        } catch (\Exception $e) {
            Log::error('ProgressAnalysisAgent error', [
                'child_id' => $child->id,
                'analysis_type' => $context['analysis_type'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->createErrorResponse('Something went wrong while analyzing your progress.');
        }
    }

    /**
     * Generate system prompt for the progress analysis agent - required by AbstractAgent
     */
    protected function getSystemPrompt(): string
    {
        return "You are an expert educational data analyst specializing in student progress tracking and learning insights. Your role is to:

ANALYSIS APPROACH:
- Analyze assessment performance data comprehensively
- Identify learning patterns and trends over time
- Recognize strengths and areas for improvement
- Provide actionable, personalized recommendations
- Track progress toward learning goals

INSIGHT CATEGORIES:
1. Performance Trends: Overall progress trajectory and patterns
2. Subject Strengths: Areas where the student excels
3. Growth Opportunities: Areas that need focused attention
4. Learning Patterns: How the student learns best
5. Motivational Insights: What drives engagement and success

RECOMMENDATION GUIDELINES:
- Provide specific, actionable next steps
- Suggest focused practice areas
- Recommend learning strategies that match the student's patterns
- Set realistic, achievable goals
- Celebrate progress and improvements
- Address challenges with encouraging solutions

COMMUNICATION STYLE:
- Encouraging and positive
- Data-driven but accessible to parents and students
- Focus on growth and learning journey
- Highlight progress and achievements
- Provide clear, actionable advice
- Use age-appropriate language for primary school level

RESPONSE STRUCTURE:
1. Progress summary and key trends
2. Celebration of strengths and achievements
3. Identification of growth areas
4. Specific recommendations and next steps
5. Motivational encouragement for continued learning

Always frame feedback positively and focus on the learning journey rather than just grades or scores.";
    }

    /**
     * Gather comprehensive progress data for analysis
     */
    private function gatherProgressData(Child $child, string $timeframe, ?string $subject): array
    {
        try {
            $timeframeDays = $this->getTimeframeDays($timeframe);
            $startDate = now()->subDays($timeframeDays);
            
            $query = AssessmentSubmission::where('child_id', $child->id)
                ->where('status', 'graded')
                ->where('created_at', '>=', $startDate)
                ->with(['assessment'])
                ->orderBy('created_at', 'asc');
                
            // Filter by subject if specified
            if ($subject) {
                $query->whereHas('assessment', function ($q) use ($subject) {
                    $q->where('title', 'LIKE', "%{$subject}%")
                      ->orWhere('description', 'LIKE', "%{$subject}%");
                });
            }
            
            $submissions = $query->get();
            
            if ($submissions->isEmpty()) {
                return ['submissions' => []];
            }
            
            // Calculate performance metrics
            $totalScore = 0;
            $totalMarks = 0;
            $subjectBreakdown = [];
            $timelineData = [];
            $difficultyBreakdown = [];
            
            foreach ($submissions as $submission) {
                if ($submission->marks_obtained !== null && $submission->total_marks > 0) {
                    $totalScore += $submission->marks_obtained;
                    $totalMarks += $submission->total_marks;
                    
                    $percentage = ($submission->marks_obtained / $submission->total_marks) * 100;
                    
                    // Subject breakdown
                    $subjectName = $this->extractSubject($submission->assessment->title ?? '');
                    if (!isset($subjectBreakdown[$subjectName])) {
                        $subjectBreakdown[$subjectName] = ['total' => 0, 'scored' => 0, 'count' => 0];
                    }
                    $subjectBreakdown[$subjectName]['total'] += $submission->total_marks;
                    $subjectBreakdown[$subjectName]['scored'] += $submission->marks_obtained;
                    $subjectBreakdown[$subjectName]['count']++;
                    
                    // Timeline data
                    $timelineData[] = [
                        'date' => $submission->created_at->format('Y-m-d'),
                        'percentage' => round($percentage, 1),
                        'assessment' => $submission->assessment->title ?? 'Assessment'
                    ];
                }
            }
            
            return [
                'submissions' => $submissions,
                'overall_percentage' => $totalMarks > 0 ? round(($totalScore / $totalMarks) * 100, 1) : 0,
                'total_assessments' => $submissions->count(),
                'subject_breakdown' => $subjectBreakdown,
                'timeline_data' => $timelineData,
                'timeframe' => $timeframe,
                'period_start' => $startDate->format('Y-m-d'),
                'period_end' => now()->format('Y-m-d'),
            ];
            
        } catch (\Exception $e) {
            Log::warning('Failed to gather progress data', [
                'child_id' => $child->id,
                'timeframe' => $timeframe,
                'subject' => $subject,
                'error' => $e->getMessage()
            ]);
            return ['submissions' => []];
        }
    }

    /**
     * Build comprehensive context for AI analysis
     */
    private function buildAnalysisContext(Child $child, array $progressData, string $analysisType, string $message): array
    {
        $timeline = $progressData['timeline_data'] ?? [];
        $subjects = $progressData['subject_breakdown'] ?? [];
        
        // Calculate trends
        $trend = $this->calculateTrend($timeline);
        $strongSubjects = $this->identifyStrongSubjects($subjects);
        $growthAreas = $this->identifyGrowthAreas($subjects);
        
        return [
            'recent_performance' => [
                'overall_average: ' . ($progressData['overall_percentage'] ?? 0) . '%',
                'total_assessments: ' . ($progressData['total_assessments'] ?? 0),
                'performance_trend: ' . $trend,
                'timeframe: ' . ($progressData['timeframe'] ?? 'unknown'),
                'date_range: ' . ($progressData['period_start'] ?? '') . ' to ' . ($progressData['period_end'] ?? ''),
            ],
            'learning_patterns' => [
                'strongest_subjects: ' . implode(', ', $strongSubjects),
                'growth_opportunities: ' . implode(', ', $growthAreas),
                'consistency_level: ' . $this->assessConsistency($timeline),
                'engagement_pattern: ' . $this->assessEngagement($progressData),
            ],
            'current_focus' => [
                'analysis_request: ' . (!empty($message) ? $message : 'Comprehensive progress analysis requested'),
                'analysis_type: ' . $analysisType,
                'focus_areas: Help identify strengths, growth areas, and actionable next steps',
                'goal: Provide personalized learning insights and recommendations',
            ]
        ];
    }

    /**
     * Convert timeframe to days
     */
    private function getTimeframeDays(string $timeframe): int
    {
        return match($timeframe) {
            '1week' => 7,
            '2weeks' => 14,
            '4weeks' => 28,
            '8weeks' => 56,
            '3months' => 90,
            '6months' => 180,
            default => 28
        };
    }

    /**
     * Extract subject from assessment title
     */
    private function extractSubject(string $title): string
    {
        $title = strtolower($title);
        
        if (strpos($title, 'math') !== false || strpos($title, 'arithmetic') !== false) {
            return 'Mathematics';
        }
        if (strpos($title, 'english') !== false || strpos($title, 'reading') !== false || strpos($title, 'writing') !== false) {
            return 'English';
        }
        if (strpos($title, 'science') !== false) {
            return 'Science';
        }
        if (strpos($title, 'verbal') !== false || strpos($title, 'reasoning') !== false) {
            return 'Verbal Reasoning';
        }
        if (strpos($title, 'non-verbal') !== false || strpos($title, 'spatial') !== false) {
            return 'Non-Verbal Reasoning';
        }
        
        return 'General';
    }

    /**
     * Calculate performance trend
     */
    private function calculateTrend(array $timeline): string
    {
        if (count($timeline) < 2) {
            return 'insufficient_data';
        }
        
        $recent = array_slice($timeline, -3); // Last 3 assessments
        $earlier = array_slice($timeline, 0, count($timeline) - 3);
        
        if (empty($earlier)) {
            return 'stable';
        }
        
        $recentAvg = array_sum(array_column($recent, 'percentage')) / count($recent);
        $earlierAvg = array_sum(array_column($earlier, 'percentage')) / count($earlier);
        
        $difference = $recentAvg - $earlierAvg;
        
        if ($difference > 5) {
            return 'improving';
        } elseif ($difference < -5) {
            return 'declining';
        }
        
        return 'stable';
    }

    /**
     * Identify strong subjects
     */
    private function identifyStrongSubjects(array $subjects): array
    {
        $strong = [];
        
        foreach ($subjects as $subject => $data) {
            if ($data['count'] >= 2) { // At least 2 assessments
                $average = ($data['scored'] / $data['total']) * 100;
                if ($average >= 80) {
                    $strong[] = $subject . ' (' . round($average, 1) . '%)';
                }
            }
        }
        
        return $strong;
    }

    /**
     * Identify growth areas
     */
    private function identifyGrowthAreas(array $subjects): array
    {
        $growth = [];
        
        foreach ($subjects as $subject => $data) {
            if ($data['count'] >= 2) { // At least 2 assessments
                $average = ($data['scored'] / $data['total']) * 100;
                if ($average < 70) {
                    $growth[] = $subject . ' (' . round($average, 1) . '%)';
                }
            }
        }
        
        return $growth;
    }

    /**
     * Assess consistency level
     */
    private function assessConsistency(array $timeline): string
    {
        if (count($timeline) < 3) {
            return 'insufficient_data';
        }
        
        $percentages = array_column($timeline, 'percentage');
        $standardDeviation = $this->calculateStandardDeviation($percentages);
        
        if ($standardDeviation < 10) {
            return 'very_consistent';
        } elseif ($standardDeviation < 20) {
            return 'consistent';
        } elseif ($standardDeviation < 30) {
            return 'somewhat_variable';
        }
        
        return 'highly_variable';
    }

    /**
     * Assess engagement pattern
     */
    private function assessEngagement(array $progressData): string
    {
        $assessmentCount = $progressData['total_assessments'] ?? 0;
        $timeframeDays = $this->getTimeframeDays($progressData['timeframe'] ?? '4weeks');
        
        $assessmentRate = $assessmentCount / max($timeframeDays / 7, 1); // per week
        
        if ($assessmentRate >= 3) {
            return 'highly_engaged';
        } elseif ($assessmentRate >= 1.5) {
            return 'regularly_engaged';
        } elseif ($assessmentRate >= 0.5) {
            return 'moderately_engaged';
        }
        
        return 'low_engagement';
    }

    /**
     * Calculate standard deviation
     */
    private function calculateStandardDeviation(array $values): float
    {
        if (count($values) < 2) {
            return 0;
        }
        
        $mean = array_sum($values) / count($values);
        $squaredDiffs = array_map(function($value) use ($mean) {
            return pow($value - $mean, 2);
        }, $values);
        
        $variance = array_sum($squaredDiffs) / count($values);
        return sqrt($variance);
    }

    /**
     * Extract insights from progress data
     */
    private function extractInsights(array $progressData, string $analysisType): array
    {
        $timeline = $progressData['timeline_data'] ?? [];
        $trend = $this->calculateTrend($timeline);
        
        return [
            'trend' => $trend,
            'overall_performance' => $progressData['overall_percentage'] ?? 0,
            'assessment_count' => $progressData['total_assessments'] ?? 0,
            'strongest_area' => $this->getTopSubject($progressData['subject_breakdown'] ?? []),
            'focus_area' => $this->getWeakestSubject($progressData['subject_breakdown'] ?? []),
            'consistency' => $this->assessConsistency($timeline),
            'engagement' => $this->assessEngagement($progressData),
        ];
    }

    /**
     * Get top performing subject
     */
    private function getTopSubject(array $subjects): string
    {
        $best = null;
        $bestScore = 0;
        
        foreach ($subjects as $subject => $data) {
            if ($data['count'] >= 1) {
                $average = ($data['scored'] / $data['total']) * 100;
                if ($average > $bestScore) {
                    $bestScore = $average;
                    $best = $subject;
                }
            }
        }
        
        return $best ?? 'General';
    }

    /**
     * Get weakest performing subject
     */
    private function getWeakestSubject(array $subjects): string
    {
        $worst = null;
        $worstScore = 100;
        
        foreach ($subjects as $subject => $data) {
            if ($data['count'] >= 1) {
                $average = ($data['scored'] / $data['total']) * 100;
                if ($average < $worstScore) {
                    $worstScore = $average;
                    $worst = $subject;
                }
            }
        }
        
        return $worst ?? 'General';
    }

    /**
     * Generate quick actionable recommendations
     */
    private function generateQuickRecommendations(array $insights): array
    {
        $recommendations = [];
        
        if ($insights['trend'] === 'improving') {
            $recommendations[] = 'Keep up the great work! Your progress is excellent.';
        } elseif ($insights['trend'] === 'declining') {
            $recommendations[] = 'Focus on understanding concepts rather than rushing through assessments.';
        }
        
        if ($insights['overall_performance'] >= 85) {
            $recommendations[] = 'Challenge yourself with more advanced topics in your strong areas.';
        } elseif ($insights['overall_performance'] < 70) {
            $recommendations[] = 'Consider reviewing fundamental concepts before attempting new assessments.';
        }
        
        if ($insights['focus_area'] !== 'General') {
            $recommendations[] = "Spend extra time practicing {$insights['focus_area']} to boost confidence.";
        }
        
        return $recommendations;
    }

    /**
     * Create insight response for edge cases
     */
    private function createInsightResponse(Child $child, string $type, string $message): array
    {
        return [
            'success' => true,
            'response' => $message,
            'agent_type' => $this->agentType,
            'child_id' => $child->id,
            'metadata' => [
                'insight_type' => $type,
                'confidence' => 0.9,
                'actionable_recommendations' => ['Keep taking assessments to build your progress data!'],
            ]
        ];
    }

    /**
     * Create error response
     */
    private function createErrorResponse(string $message): array
    {
        return [
            'success' => false,
            'error' => $message,
            'response' => "I'm having trouble analyzing your progress right now. Please try again in a moment.",
            'agent_type' => $this->agentType
        ];
    }
}
