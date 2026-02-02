<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment Report</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f8f9fa;
        }
        
        .report-container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .header {
            text-align: center;
            border-bottom: 3px solid #007bff;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        
        .header h1 {
            color: #007bff;
            font-size: 2.2em;
            margin: 0;
            font-weight: 600;
        }
        
        .student-info {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 30px;
            border-left: 4px solid #007bff;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }
        
        .info-item {
            display: flex;
            align-items: center;
        }
        
        .info-label {
            font-weight: 600;
            color: #495057;
            min-width: 100px;
            margin-right: 10px;
        }
        
        .info-value {
            color: #212529;
            font-weight: 500;
        }
        
        .score-highlight {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: 600;
            display: inline-block;
        }
        
        .section {
            margin-bottom: 35px;
        }
        
        .section-title {
            color: #007bff;
            font-size: 1.4em;
            font-weight: 600;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e9ecef;
        }
        
        .feedback-content {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 8px;
            border-left: 4px solid #17a2b8;
            margin-bottom: 20px;
        }
        
        .feedback-content h3,
        .feedback-content h4 {
            color: #007bff;
            margin-top: 20px;
            margin-bottom: 10px;
        }
        
        .feedback-content h3 {
            font-size: 1.2em;
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 5px;
        }
        
        .feedback-content h4 {
            font-size: 1.1em;
        }
        
        .feedback-content p {
            margin-bottom: 12px;
            color: #495057;
        }
        
        .answers-section {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .answer-item {
            padding: 20px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }
        
        .answer-item:last-child {
            border-bottom: none;
        }
        
        .answer-item:nth-child(even) {
            background: #f8f9fa;
        }
        
        .question-number {
            background: #007bff;
            color: white;
            padding: 8px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9em;
            min-width: 40px;
            text-align: center;
            flex-shrink: 0;
        }
        
        .answer-content {
            flex: 1;
            color: #495057;
            font-weight: 500;
        }
        
        .json-answer {
            background: #f1f3f4;
            padding: 10px 15px;
            border-radius: 5px;
            font-family: 'Courier New', monospace;
            font-size: 0.9em;
            color: #d63384;
            border: 1px solid #e9ecef;
        }
        
        hr {
            border: none;
            height: 2px;
            background: linear-gradient(90deg, #007bff, transparent);
            margin: 30px 0;
        }
        
        @media (max-width: 768px) {
            .report-container {
                padding: 20px;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .header h1 {
                font-size: 1.8em;
            }
        }
        
        @media print {
            body {
                background: white;
                margin: 0;
                padding: 0;
            }
            
            .report-container {
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }
    </style>
</head>
<body>
    <div class="report-container">
        <div class="header">
            <h1>Assessment Report</h1>
        </div>
        
        <div class="student-info">
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Student:</span>
                    <span class="info-value">{{ $submission->child->child_name }}</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Assessment:</span>
                    <span class="info-value">{{ $submission->assessment->title }}</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Score:</span>
                    <span class="score-highlight">{{ $submission->marks_obtained }} / {{ $submission->total_marks }}</span>
                </div>
            </div>
        </div>
        
        <div class="section">
            <h2 class="section-title">AI-Generated Feedback</h2>
            <div class="feedback-content">
                @php
                    // Convert markdown-style formatting to HTML
                    $formattedFeedback = $reportText;
                    
                    // Convert **text** to <strong>text</strong>
                    $formattedFeedback = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $formattedFeedback);
                    
                    // Convert ### Heading to <h4>Heading</h4>
                    $formattedFeedback = preg_replace('/^### (.*?)$/m', '<h4>$1</h4>', $formattedFeedback);
                    
                    // Convert ## Heading to <h3>Heading</h3>
                    $formattedFeedback = preg_replace('/^## (.*?)$/m', '<h3>$1</h3>', $formattedFeedback);
                    
                    // Convert line breaks to paragraphs
                    $formattedFeedback = nl2br($formattedFeedback);
                @endphp
                
                {!! $formattedFeedback !!}
            </div>
        </div>
        
        <hr>
        
        {{-- <div class="section">
            <h2 class="section-title">Student Responses</h2>
            
            @php
                $answers = is_string($submission->answers_json)
                    ? json_decode($submission->answers_json, true)
                    : $submission->answers_json;
            @endphp
            
            <div class="answers-section">
                @foreach($answers as $i => $answer)
                    <div class="answer-item">
                        <div class="question-number">Q{{ $i + 1 }}</div>
                        <div class="answer-content">
                            @if(is_array($answer))
                                <div class="json-answer">{{ json_encode($answer, JSON_PRETTY_PRINT) }}</div>
                            @else
                                {{ $answer }}
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div> --}}
    </div>
</body>
</html>