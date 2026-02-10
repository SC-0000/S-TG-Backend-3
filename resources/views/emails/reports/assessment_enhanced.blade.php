@extends('emails.layout')

@section('title', 'Assessment Report - {{ $submission->child->child_name }}')

@section('content')
<style>
    .report-header {
        background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
        color: white;
        padding: 30px;
        border-radius: 10px;
        margin-bottom: 30px;
        text-align: center;
    }
    
    .report-header h1 {
        margin: 0 0 10px 0;
        font-size: 28px;
        font-weight: 600;
    }
    
    .report-header .subtitle {
        font-size: 16px;
        opacity: 0.9;
        margin: 0;
    }
    
    .summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .summary-card {
        background: #f8f9fa;
        padding: 20px;
        border-radius: 8px;
        border-left: 4px solid #007bff;
        text-align: center;
    }
    
    .summary-card .value {
        font-size: 24px;
        font-weight: 600;
        color: #007bff;
        margin-bottom: 5px;
    }
    
    .summary-card .label {
        font-size: 14px;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .performance-badge {
        display: inline-block;
        padding: 8px 16px;
        border-radius: 20px;
        font-weight: 600;
        font-size: 14px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .performance-excellent { background: #d4edda; color: #155724; }
    .performance-good { background: #cce7ff; color: #004085; }
    .performance-satisfactory { background: #fff3cd; color: #856404; }
    .performance-needs-improvement { background: #ffeaa7; color: #b8860b; }
    .performance-requires-attention { background: #f8d7da; color: #721c24; }
    
    .section {
        margin-bottom: 35px;
    }
    
    .section-title {
        font-size: 20px;
        font-weight: 600;
        color: #007bff;
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 2px solid #e9ecef;
    }
    
    .question-item {
        background: white;
        border: 1px solid #e9ecef;
        border-radius: 8px;
        margin-bottom: 20px;
        overflow: hidden;
    }
    
    .question-header {
        background: #f8f9fa;
        padding: 15px 20px;
        border-bottom: 1px solid #e9ecef;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    
    .question-info {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .question-number {
        background: #007bff;
        color: white;
        padding: 6px 12px;
        border-radius: 15px;
        font-weight: 600;
        font-size: 14px;
    }
    
    .question-type {
        display: flex;
        align-items: center;
        gap: 5px;
        font-size: 14px;
        color: #6c757d;
    }
    
    .marks-display {
        font-weight: 600;
        padding: 6px 12px;
        border-radius: 15px;
        font-size: 14px;
    }
    
    .marks-correct {
        background: #d4edda;
        color: #155724;
    }
    
    .marks-incorrect {
        background: #f8d7da;
        color: #721c24;
    }
    
    .question-content {
        padding: 20px;
    }
    
    .question-text {
        font-weight: 600;
        margin-bottom: 15px;
        color: #212529;
    }
    
    .answer-section {
        margin-bottom: 15px;
    }
    
    .answer-label {
        font-weight: 600;
        color: #495057;
        margin-bottom: 5px;
        font-size: 14px;
    }
    
    .answer-value {
        padding: 10px 15px;
        border-radius: 5px;
        border: 1px solid #e9ecef;
        background: #f8f9fa;
    }
    
    .answer-correct {
        background: #d4edda;
        border-color: #c3e6cb;
        color: #155724;
    }
    
    .answer-incorrect {
        background: #f8d7da;
        border-color: #f5c6cb;
        color: #721c24;
    }
    
    .feedback-section {
        background: #e7f3ff;
        border: 1px solid #bee5eb;
        border-radius: 5px;
        padding: 15px;
        margin-top: 10px;
    }
    
    .feedback-label {
        font-weight: 600;
        color: #0c5460;
        margin-bottom: 5px;
        font-size: 14px;
    }
    
    .feedback-text {
        color: #0c5460;
        margin: 0;
    }
    
    .review-badge {
        background: #ffeaa7;
        color: #856404;
        padding: 4px 8px;
        border-radius: 10px;
        font-size: 12px;
        font-weight: 600;
        margin-left: 10px;
    }
    
    .insights-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .insight-card {
        background: white;
        border: 1px solid #e9ecef;
        border-radius: 8px;
        padding: 20px;
    }
    
    .insight-title {
        font-weight: 600;
        color: #007bff;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .insight-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    
    .insight-list li {
        padding: 8px 0;
        border-bottom: 1px solid #f8f9fa;
        color: #495057;
    }
    
    .insight-list li:last-child {
        border-bottom: none;
    }
    
    .ai-feedback {
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 25px;
        margin-bottom: 30px;
    }
    
    .ai-feedback-title {
        color: #007bff;
        font-weight: 600;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .footer-note {
        background: #f8f9fa;
        border: 1px solid #e9ecef;
        border-radius: 8px;
        padding: 20px;
        text-align: center;
        color: #6c757d;
        margin-top: 30px;
    }
    
    @media (max-width: 600px) {
        .summary-grid {
            grid-template-columns: 1fr;
        }
        
        .question-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }
        
        .insights-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="report-header">
    <h1>📊 Assessment Report</h1>
    <p class="subtitle">Detailed Performance Analysis for {{ $submission->child->child_name }}</p>
</div>

<div class="summary-grid">
    <div class="summary-card">
        <div class="value">{{ $submission->marks_obtained }}/{{ $submission->total_marks }}</div>
        <div class="label">Overall Score</div>
    </div>
    
    <div class="summary-card">
        <div class="value">{{ $insights['overall_percentage'] }}%</div>
        <div class="label">Percentage</div>
    </div>
    
    <div class="summary-card">
        <div class="value">{{ $insights['correct_answers'] }}/{{ $insights['total_questions'] }}</div>
        <div class="label">Questions Correct</div>
    </div>
    
    <div class="summary-card">
        <div class="value">
            <span class="performance-badge performance-{{ strtolower(str_replace(' ', '-', $insights['performance_level'])) }}">
                {{ $insights['performance_level'] }}
            </span>
        </div>
        <div class="label">Performance Level</div>
    </div>
</div>

<div class="section">
    <div class="section-title">📋 Assessment Details</div>
    <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; border-left: 4px solid #007bff;">
        <p style="margin: 0 0 10px 0;"><strong>Assessment:</strong> {{ $submission->assessment->title }}</p>
        <p style="margin: 0 0 10px 0;"><strong>Completed:</strong> {{ $submission->finished_at->format('l, F j, Y \\a\\t g:i A') }}</p>
        <p style="margin: 0 0 10px 0;"><strong>Student:</strong> {{ $submission->child->child_name }}</p>
        @if($insights['manually_reviewed'] > 0)
        <p style="margin: 0;"><strong>Questions Manually Reviewed:</strong> {{ $insights['manually_reviewed'] }} 
            <span class="review-badge">⚡ Admin Reviewed</span>
        </p>
        @endif
    </div>
</div>

@if($reportText)
<div class="section">
    <div class="ai-feedback">
        <div class="ai-feedback-title">
            🤖 AI-Generated Insights & Recommendations
        </div>
        <div style="line-height: 1.6; color: #495057;">
            @php
                // Convert markdown-style formatting to HTML
                $formattedFeedback = $reportText;
                
                // Convert **text** to <strong>text</strong>
                $formattedFeedback = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $formattedFeedback);
                
                // Convert ### Heading to <h4>Heading</h4>
                $formattedFeedback = preg_replace('/^### (.*?)$/m', '<h4 style="color: #007bff; margin: 20px 0 10px 0;">$1</h4>', $formattedFeedback);
                
                // Convert ## Heading to <h3>Heading</h3>
                $formattedFeedback = preg_replace('/^## (.*?)$/m', '<h3 style="color: #007bff; margin: 25px 0 15px 0;">$1</h3>', $formattedFeedback);
                
                // Convert line breaks to paragraphs
                $formattedFeedback = nl2br($formattedFeedback);
            @endphp
            
            {!! $formattedFeedback !!}
        </div>
    </div>
</div>
@endif

<div class="section">
    <div class="section-title">📝 Question-by-Question Analysis</div>
    
    @foreach($formattedQuestions as $question)
    <div class="question-item">
        <div class="question-header">
            <div class="question-info">
                <span class="question-number">Q{{ $question['question_number'] }}</span>
                <span class="question-type">
                    {{ $question['icon'] }} {{ $question['type_label_formatted'] ?? $question['type_label'] ?? 'Unknown' }}
                </span>
                @if($question['manually_reviewed'])
                <span class="review-badge">⚡ Reviewed</span>
                @endif
            </div>
            <span class="marks-display {{ $question['is_correct'] ? 'marks-correct' : 'marks-incorrect' }}">
                {{ $question['marks_awarded'] }}/{{ $question['max_marks'] }} marks
            </span>
        </div>
        
        <div class="question-content">
            <div class="question-text">{{ $question['question_text'] }}</div>
            
            <div class="answer-section">
                <div class="answer-label">🎯 Your Answer:</div>
                <div class="answer-value {{ $question['is_correct'] ? 'answer-correct' : 'answer-incorrect' }}">
                    {{ $question['student_answer'] }}
                    @if($question['is_correct'])
                        ✅ Correct
                    @else
                        ❌ Incorrect
                    @endif
                </div>
            </div>
            
            @if(!$question['is_correct'] && $question['correct_answer'])
            <div class="answer-section">
                <div class="answer-label">📚 Correct Answer:</div>
                <div class="answer-value answer-correct">
                    {{ $question['correct_answer'] }}
                </div>
            </div>
            @endif
            
            @if($question['feedback'])
            <div class="feedback-section">
                <div class="feedback-label">💡 Feedback:</div>
                <p class="feedback-text">{{ $question['feedback'] }}</p>
            </div>
            @endif
            
            @if($question['confidence'])
            <div style="margin-top: 10px; font-size: 12px; color: #6c757d;">
                AI Confidence: {{ round($question['confidence'], 1) }}%
            </div>
            @endif
        </div>
    </div>
    @endforeach
</div>

<div class="section">
    <div class="section-title">📊 Performance Insights</div>
    
    <div class="insights-grid">
        @if(count($insights['strength_areas']) > 0)
        <div class="insight-card">
            <div class="insight-title">
                🌟 Strengths Identified
            </div>
            <ul class="insight-list">
                @foreach($insights['strength_areas'] as $strength)
                <li>✅ {{ $strength }}</li>
                @endforeach
            </ul>
        </div>
        @endif
        
        @if(count($insights['improvement_areas']) > 0)
        <div class="insight-card">
            <div class="insight-title">
                📈 Areas for Improvement
            </div>
            <ul class="insight-list">
                @foreach($insights['improvement_areas'] as $area)
                <li>📋 {{ $area }}</li>
                @endforeach
            </ul>
        </div>
        @endif
        
        <div class="insight-card">
            <div class="insight-title">
                🎯 Question Type Performance
            </div>
            <ul class="insight-list">
                @foreach($insights['question_type_breakdown'] as $type => $stats)
                @php
                    $percentage = $stats['total'] > 0 ? round(($stats['correct'] / $stats['total']) * 100, 1) : 0;
                    $typeLabel = $stats['type_label'] ?? ucfirst(str_replace('_', ' ', $type));
                @endphp
                <li>{{ $typeLabel }}: {{ $stats['correct'] }}/{{ $stats['total'] }} ({{ $percentage }}%)</li>
                @endforeach
            </ul>
        </div>
    </div>
</div>

<div class="footer-note">
    <p style="margin: 0 0 10px 0;"><strong>Questions about this report?</strong></p>
    <p style="margin: 0;">Contact your tutor at {{ $brandName }} for additional support and personalized guidance.</p>
</div>

@endsection
