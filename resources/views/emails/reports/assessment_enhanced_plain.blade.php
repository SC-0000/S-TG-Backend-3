ASSESSMENT REPORT - {{ $submission->child->child_name }}
================================================================

Detailed Performance Analysis for {{ $submission->child->child_name }}

OVERALL PERFORMANCE
==================
Overall Score: {{ $submission->marks_obtained }}/{{ $submission->total_marks }}
Percentage: {{ $insights['overall_percentage'] }}%
Questions Correct: {{ $insights['correct_answers'] }}/{{ $insights['total_questions'] }}
Performance Level: {{ $insights['performance_level'] }}

ASSESSMENT DETAILS
==================
Assessment: {{ $submission->assessment->title }}
Completed: {{ $submission->finished_at->format('l, F j, Y \a\t g:i A') }}
Student: {{ $submission->child->child_name }}
@if($insights['manually_reviewed'] > 0)
Questions Manually Reviewed: {{ $insights['manually_reviewed'] }} (Admin Reviewed)
@endif

@if($reportText)
AI-GENERATED INSIGHTS & RECOMMENDATIONS
======================================
{!! strip_tags($reportText) !!}

@endif
QUESTION-BY-QUESTION ANALYSIS
=============================

@foreach($formattedQuestions as $question)
Question {{ $question['question_number'] }} ({{ $question['type_label_formatted'] ?? $question['type_label'] ?? 'Unknown' }})
{{ str_repeat('-', 50) }}
{{ $question['question_text'] }}

Your Answer: {{ $question['student_answer'] }}
@if($question['is_correct'])
✓ CORRECT - {{ $question['marks_awarded'] }}/{{ $question['max_marks'] }} marks
@else
✗ INCORRECT - {{ $question['marks_awarded'] }}/{{ $question['max_marks'] }} marks
@if($question['correct_answer'])

Correct Answer: {{ $question['correct_answer'] }}
@endif
@endif

@if($question['feedback'])
Feedback: {{ $question['feedback'] }}
@endif

@if($question['confidence'])
AI Confidence: {{ round($question['confidence'], 1) }}%
@endif

@endforeach

PERFORMANCE INSIGHTS
===================

@if(count($insights['strength_areas']) > 0)
Strengths Identified:
@foreach($insights['strength_areas'] as $strength)
✓ {{ $strength }}
@endforeach

@endif
@if(count($insights['improvement_areas']) > 0)
Areas for Improvement:
@foreach($insights['improvement_areas'] as $area)
• {{ $area }}
@endforeach

@endif
Question Type Performance:
@foreach($insights['question_type_breakdown'] as $type => $stats)
@php
    $percentage = $stats['total'] > 0 ? round(($stats['correct'] / $stats['total']) * 100, 1) : 0;
    $typeLabel = $stats['type_label'] ?? ucfirst(str_replace('_', ' ', $type));
@endphp
• {{ $typeLabel }}: {{ $stats['correct'] }}/{{ $stats['total'] }} ({{ $percentage }}%)
@endforeach

================================================================
Questions about this report?
Contact your tutor at Eleven Plus Tutor for additional support and personalized guidance.
