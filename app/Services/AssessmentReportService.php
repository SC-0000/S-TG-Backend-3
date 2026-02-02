<?php

namespace App\Services;

use App\Models\AssessmentSubmission;

class AssessmentReportService
{
    /**
     * Format questions for email report display
     */
    public static function formatQuestionForEmail($item, $questionIndex)
    {
        // Handle both old array format and new AssessmentSubmissionItem object
        if (is_array($item)) {
            return self::formatQuestionFromArray($item, $questionIndex);
        }
        
        // New AssessmentSubmissionItem object format
        $questionNumber = $questionIndex + 1;
        $questionType = $item->question_type ?? 'unknown';
        $studentAnswer = $item->answer;
        $questionData = $item->question_data ?? [];
        $gradingMetadata = $item->grading_metadata ?? [];
        
        // Get question text
        $questionText = self::getQuestionTextFromItem($item);
        
        // Get grading info
        $isCorrect = $item->is_correct ?? false;
        $marksAwarded = $item->marks_awarded ?? 0;
        $maxMarks = $questionData['marks'] ?? ($item->bankQuestion->marks ?? 1);
        $confidence = $item->getAIConfidence();
        $manuallyReviewed = $gradingMetadata['manually_reviewed'] ?? false;
        
        // Format based on question type  
        $formattedAnswer = self::formatAnswerByTypeFromItem($questionType, $studentAnswer, $item);
        $correctAnswer = self::getCorrectAnswerFromItem($questionType, $item);
        
        return [
            'question_number' => $questionNumber,
            'question_type' => $questionType,
            'question_text' => $questionText,
            'student_answer' => $formattedAnswer,
            'correct_answer' => $correctAnswer,
            'is_correct' => $isCorrect,
            'marks_awarded' => $marksAwarded,
            'max_marks' => $maxMarks,
            'confidence' => $confidence,
            'manually_reviewed' => $manuallyReviewed,
            'feedback' => $item->detailed_feedback ?? null,
            'icon' => self::getQuestionTypeIcon($questionType),
            'type_label' => self::getQuestionTypeLabel($questionType)
        ];
    }
    
    /**
     * Format questions for email report display (legacy array format)
     */
    private static function formatQuestionFromArray($answer, $questionIndex)
    {
        $questionNumber = $questionIndex + 1;
        $questionType = $answer['type'] ?? 'unknown';
        $gradingResult = $answer['grading_result'] ?? [];
        $studentAnswer = $answer['answer'] ?? null;
        $questionData = $answer['question_data'] ?? [];
        
        // Get question text
        $questionText = self::getQuestionText($answer);
        
        // Get grading info
        $isCorrect = $gradingResult['is_correct'] ?? false;
        $marksAwarded = $gradingResult['marks_awarded'] ?? 0;
        $maxMarks = $questionData['marks'] ?? 1;
        $confidence = $gradingResult['confidence_level'] ?? null;
        $manuallyReviewed = isset($gradingResult['manually_reviewed']) && $gradingResult['manually_reviewed'];
        
        // Format based on question type
        $formattedAnswer = self::formatAnswerByType($questionType, $studentAnswer, $answer);
        $correctAnswer = self::getCorrectAnswer($questionType, $answer);
        
        return [
            'question_number' => $questionNumber,
            'question_type' => $questionType,
            'question_text' => $questionText,
            'student_answer' => $formattedAnswer,
            'correct_answer' => $correctAnswer,
            'is_correct' => $isCorrect,
            'marks_awarded' => $marksAwarded,
            'max_marks' => $maxMarks,
            'confidence' => $confidence,
            'manually_reviewed' => $manuallyReviewed,
            'feedback' => $gradingResult['detailed_feedback'] ?? null,
            'icon' => self::getQuestionTypeIcon($questionType),
            'type_label' => self::getQuestionTypeLabel($questionType)
        ];
    }
    
    /**
     * Extract question text from AssessmentSubmissionItem object
     */
    private static function getQuestionTextFromItem($item)
    {
        // Get question text from different sources
        if ($item->isFromQuestionBank() && $item->bankQuestion) {
            return $item->bankQuestion->question_text ?? $item->bankQuestion->title ?? '';
        } else {
            $questionData = $item->question_data ?? [];
            return $questionData['question_text'] ?? $questionData['title'] ?? '';
        }
        
        return 'Question text not available';
    }
    
    /**
     * Extract question text from various formats (legacy)
     */
    private static function getQuestionText($answer)
    {
        // Try different possible locations for question text
        if (isset($answer['question_data']['title'])) {
            return $answer['question_data']['title'];
        }
        
        if (isset($answer['question_data']['question_text'])) {
            return $answer['question_data']['question_text'];
        }
        
        if (isset($answer['question'])) {
            return $answer['question'];
        }
        
        return 'Question text not available';
    }
    
    /**
     * Format student answer based on question type for AssessmentSubmissionItem
     */
    private static function formatAnswerByTypeFromItem($questionType, $studentAnswer, $item)
    {
        switch ($questionType) {
            case 'mcq':
            case 'multiple_choice':
                return self::formatMCQAnswerFromItem($studentAnswer, $item);
                
            case 'matching':
                return self::formatMatchingAnswerFromItem($studentAnswer, $item);
                
            case 'ordering':
                return self::formatOrderingAnswerFromItem($studentAnswer, $item);
                
            case 'cloze':
            case 'fill_in_the_blank':
                return self::formatClozeAnswerFromItem($studentAnswer, $item);
                
            case 'comprehension':
                return self::formatComprehensionAnswerFromItem($studentAnswer, $item);
                
            case 'short_answer':
            case 'long_answer':
                return is_string($studentAnswer) ? $studentAnswer : json_encode($studentAnswer);
                
            default:
                return is_array($studentAnswer) ? json_encode($studentAnswer, JSON_PRETTY_PRINT) : (string) $studentAnswer;
        }
    }
    
    /**
     * Format student answer based on question type (legacy)
     */
    private static function formatAnswerByType($questionType, $studentAnswer, $answer)
    {
        switch ($questionType) {
            case 'mcq':
            case 'multiple_choice':
                return self::formatMCQAnswer($studentAnswer, $answer);
                
            case 'matching':
                return self::formatMatchingAnswer($studentAnswer, $answer);
                
            case 'ordering':
                return self::formatOrderingAnswer($studentAnswer, $answer);
                
            case 'cloze':
            case 'fill_in_the_blank':
                return self::formatClozeAnswer($studentAnswer, $answer);
                
            case 'comprehension':
                return self::formatComprehensionAnswer($studentAnswer, $answer);
                
            case 'short_answer':
            case 'long_answer':
                return is_string($studentAnswer) ? $studentAnswer : json_encode($studentAnswer);
                
            default:
                return is_array($studentAnswer) ? json_encode($studentAnswer, JSON_PRETTY_PRINT) : (string) $studentAnswer;
        }
    }
    
    /**
     * Format MCQ answer from AssessmentSubmissionItem
     */
    private static function formatMCQAnswerFromItem($studentAnswer, $item)
    {
        $questionData = $item->question_data ?? [];
        $options = $questionData['options'] ?? [];
        
        // Try to get options from bank question if not in question_data
        if (empty($options) && $item->bankQuestion) {
            $bankData = json_decode($item->bankQuestion->question_data ?? '{}', true);
            $options = $bankData['options'] ?? [];
        }
        
        if (is_array($options) && isset($options[$studentAnswer])) {
            return "Option {$studentAnswer}: " . $options[$studentAnswer];
        }
        
        return "Option {$studentAnswer}";
    }
    
    /**
     * Format MCQ answer (legacy)
     */
    private static function formatMCQAnswer($studentAnswer, $answer)
    {
        $options = $answer['question_data']['options'] ?? [];
        
        if (is_array($options) && isset($options[$studentAnswer])) {
            return "Option {$studentAnswer}: " . $options[$studentAnswer];
        }
        
        return "Option {$studentAnswer}";
    }
    
    /**
     * Format matching answer from AssessmentSubmissionItem
     */
    private static function formatMatchingAnswerFromItem($studentAnswer, $item)
    {
        if (!is_array($studentAnswer)) {
            return (string) $studentAnswer;
        }
        
        $questionData = $item->question_data ?? [];
        $leftItems = $questionData['left_items'] ?? [];
        $rightItems = $questionData['right_items'] ?? [];
        
        // Try to get items from bank question if not in question_data
        if (empty($leftItems) && $item->bankQuestion) {
            $bankData = json_decode($item->bankQuestion->question_data ?? '{}', true);
            $leftItems = $bankData['left_items'] ?? [];
            $rightItems = $bankData['right_items'] ?? [];
        }
        
        $formatted = [];
        foreach ($studentAnswer as $leftId => $rightId) {
            $leftText = $leftItems[$leftId] ?? "Item {$leftId}";
            $rightText = $rightItems[$rightId] ?? "Item {$rightId}";
            $formatted[] = "{$leftText} → {$rightText}";
        }
        
        return implode(', ', $formatted);
    }
    
    /**
     * Format matching answer (legacy)
     */
    private static function formatMatchingAnswer($studentAnswer, $answer)
    {
        if (!is_array($studentAnswer)) {
            return (string) $studentAnswer;
        }
        
        $leftItems = $answer['question_data']['left_items'] ?? [];
        $rightItems = $answer['question_data']['right_items'] ?? [];
        
        $formatted = [];
        foreach ($studentAnswer as $leftId => $rightId) {
            $leftText = $leftItems[$leftId] ?? "Item {$leftId}";
            $rightText = $rightItems[$rightId] ?? "Item {$rightId}";
            $formatted[] = "{$leftText} → {$rightText}";
        }
        
        return implode(', ', $formatted);
    }
    
    /**
     * Format ordering answer from AssessmentSubmissionItem
     */
    private static function formatOrderingAnswerFromItem($studentAnswer, $item)
    {
        if (!is_array($studentAnswer)) {
            return (string) $studentAnswer;
        }
        
        $questionData = $item->question_data ?? [];
        $items = $questionData['items'] ?? [];
        
        // Try to get items from bank question if not in question_data
        if (empty($items) && $item->bankQuestion) {
            $bankData = json_decode($item->bankQuestion->question_data ?? '{}', true);
            $items = $bankData['items'] ?? [];
        }
        
        $formatted = [];
        foreach ($studentAnswer as $index => $itemId) {
            $itemText = $items[$itemId] ?? "Item {$itemId}";
            $formatted[] = ($index + 1) . ". {$itemText}";
        }
        
        return implode(', ', $formatted);
    }
    
    /**
     * Format cloze answer from AssessmentSubmissionItem
     */
    private static function formatClozeAnswerFromItem($studentAnswer, $item)
    {
        if (!is_array($studentAnswer)) {
            return (string) $studentAnswer;
        }
        
        $formatted = [];
        foreach ($studentAnswer as $blankId => $response) {
            $formatted[] = "Blank {$blankId}: {$response}";
        }
        
        return implode(', ', $formatted);
    }
    
    /**
     * Format comprehension answer from AssessmentSubmissionItem
     */
    private static function formatComprehensionAnswerFromItem($studentAnswer, $item)
    {
        if (!is_array($studentAnswer)) {
            return (string) $studentAnswer;
        }
        
        $questionData = $item->question_data ?? [];
        $subQuestions = $questionData['sub_questions'] ?? [];
        
        // Try to get sub-questions from bank question if not in question_data
        if (empty($subQuestions) && $item->bankQuestion) {
            $bankData = json_decode($item->bankQuestion->question_data ?? '{}', true);
            $subQuestions = $bankData['sub_questions'] ?? [];
        }
        
        $formatted = [];
        foreach ($studentAnswer as $subId => $response) {
            $subQuestion = $subQuestions[$subId]['text'] ?? "Question {$subId}";
            $formatted[] = "{$subQuestion}: {$response}";
        }
        
        return implode(' | ', $formatted);
    }
    
    /**
     * Format ordering answer (legacy)
     */
    private static function formatOrderingAnswer($studentAnswer, $answer)
    {
        if (!is_array($studentAnswer)) {
            return (string) $studentAnswer;
        }
        
        $items = $answer['question_data']['items'] ?? [];
        
        $formatted = [];
        foreach ($studentAnswer as $index => $itemId) {
            $itemText = $items[$itemId] ?? "Item {$itemId}";
            $formatted[] = ($index + 1) . ". {$itemText}";
        }
        
        return implode(', ', $formatted);
    }
    
    /**
     * Format cloze answer (legacy)
     */
    private static function formatClozeAnswer($studentAnswer, $answer)
    {
        if (!is_array($studentAnswer)) {
            return (string) $studentAnswer;
        }
        
        $formatted = [];
        foreach ($studentAnswer as $blankId => $response) {
            $formatted[] = "Blank {$blankId}: {$response}";
        }
        
        return implode(', ', $formatted);
    }
    
    /**
     * Format comprehension answer (legacy)
     */
    private static function formatComprehensionAnswer($studentAnswer, $answer)
    {
        if (!is_array($studentAnswer)) {
            return (string) $studentAnswer;
        }
        
        $subQuestions = $answer['question_data']['sub_questions'] ?? [];
        
        $formatted = [];
        foreach ($studentAnswer as $subId => $response) {
            $subQuestion = $subQuestions[$subId]['text'] ?? "Question {$subId}";
            $formatted[] = "{$subQuestion}: {$response}";
        }
        
        return implode(' | ', $formatted);
    }
    
    /**
     * Get correct answer for display when student was wrong (from AssessmentSubmissionItem)
     */
    private static function getCorrectAnswerFromItem($questionType, $item)
    {
        $isCorrect = $item->is_correct ?? false;
        
        // Only show correct answer if student was wrong
        if ($isCorrect) {
            return null;
        }
        
        $questionData = $item->question_data ?? [];
        $answerSchema = $questionData['answer_schema'] ?? null;
        
        // Try to get answer schema from bank question if not in question_data
        if (!$answerSchema && $item->bankQuestion) {
            $bankData = json_decode($item->bankQuestion->question_data ?? '{}', true);
            $answerSchema = $bankData['answer_schema'] ?? null;
        }
        
        if (!$answerSchema) {
            return null;
        }
        
        switch ($questionType) {
            case 'mcq':
            case 'multiple_choice':
                $correctOption = $answerSchema['correct_answer'] ?? null;
                $options = $questionData['options'] ?? [];
                
                // Try to get options from bank question if not in question_data
                if (empty($options) && $item->bankQuestion) {
                    $bankData = json_decode($item->bankQuestion->question_data ?? '{}', true);
                    $options = $bankData['options'] ?? [];
                }
                
                if ($correctOption && isset($options[$correctOption])) {
                    return "Option {$correctOption}: " . $options[$correctOption];
                }
                return "Option {$correctOption}";
                
            case 'matching':
            case 'ordering':
            case 'cloze':
            case 'comprehension':
                // For these types, we can use the legacy formatters as they work with arrays
                $fakeAnswer = ['question_data' => $questionData];
                return self::getCorrectAnswer($questionType, $fakeAnswer);
                
            default:
                return is_array($answerSchema) ? json_encode($answerSchema, JSON_PRETTY_PRINT) : (string) $answerSchema;
        }
    }
    
    /**
     * Get correct answer for display when student was wrong (legacy)
     */
    private static function getCorrectAnswer($questionType, $answer)
    {
        $gradingResult = $answer['grading_result'] ?? [];
        $isCorrect = $gradingResult['is_correct'] ?? false;
        
        // Only show correct answer if student was wrong
        if ($isCorrect) {
            return null;
        }
        
        $answerSchema = $answer['question_data']['answer_schema'] ?? null;
        if (!$answerSchema) {
            return null;
        }
        
        switch ($questionType) {
            case 'mcq':
            case 'multiple_choice':
                $correctOption = $answerSchema['correct_answer'] ?? null;
                $options = $answer['question_data']['options'] ?? [];
                if ($correctOption && isset($options[$correctOption])) {
                    return "Option {$correctOption}: " . $options[$correctOption];
                }
                return "Option {$correctOption}";
                
            case 'matching':
                return self::formatMatchingAnswer($answerSchema['correct_pairs'] ?? [], $answer);
                
            case 'ordering':
                return self::formatOrderingAnswer($answerSchema['correct_order'] ?? [], $answer);
                
            case 'cloze':
                return self::formatClozeAnswer($answerSchema['correct_answers'] ?? [], $answer);
                
            case 'comprehension':
                return self::formatComprehensionAnswer($answerSchema['correct_answers'] ?? [], $answer);
                
            default:
                return is_array($answerSchema) ? json_encode($answerSchema, JSON_PRETTY_PRINT) : (string) $answerSchema;
        }
    }
    
    /**
     * Get icon for question type
     */
    private static function getQuestionTypeIcon($questionType)
    {
        $icons = [
            'mcq' => '🔘',
            'multiple_choice' => '🔘',
            'matching' => '🔗',
            'ordering' => '📋',
            'cloze' => '📝',
            'fill_in_the_blank' => '📝',
            'comprehension' => '📖',
            'short_answer' => '✏️',
            'long_answer' => '📄',
            'image_grid_mcq' => '🖼️'
        ];
        
        return $icons[$questionType] ?? '❓';
    }
    
    /**
     * Get label for question type
     */
    public static function getQuestionTypeLabel($questionType)
    {
        $labels = [
            'mcq' => 'Multiple Choice',
            'multiple_choice' => 'Multiple Choice',
            'matching' => 'Matching',
            'ordering' => 'Ordering',
            'cloze' => 'Fill in the Blanks',
            'fill_in_the_blank' => 'Fill in the Blanks',
            'comprehension' => 'Reading Comprehension',
            'short_answer' => 'Short Answer',
            'long_answer' => 'Long Answer',
            'image_grid_mcq' => 'Image Grid MCQ'
        ];
        
        return $labels[$questionType] ?? ucfirst(str_replace('_', ' ', $questionType));
    }
    
    /**
     * Generate performance insights
     */
    public static function generatePerformanceInsights(AssessmentSubmission $submission)
    {
        $answers = is_string($submission->answers_json)
            ? json_decode($submission->answers_json, true)
            : ($submission->answers_json ?? []);
            
        $totalQuestions = count($answers);
        $correctAnswers = 0;
        $questionTypes = [];
        $strengthAreas = [];
        $improvementAreas = [];
        $manuallyReviewed = 0;
        
        foreach ($answers as $answer) {
            $questionType = $answer['type'] ?? 'unknown';
            $gradingResult = $answer['grading_result'] ?? [];
            $isCorrect = $gradingResult['is_correct'] ?? false;
            
            if (!isset($questionTypes[$questionType])) {
                $questionTypes[$questionType] = ['total' => 0, 'correct' => 0];
            }
            
            $questionTypes[$questionType]['total']++;
            
            if ($isCorrect) {
                $correctAnswers++;
                $questionTypes[$questionType]['correct']++;
            }
            
            if (isset($gradingResult['manually_reviewed']) && $gradingResult['manually_reviewed']) {
                $manuallyReviewed++;
            }
        }
        
        // Identify strengths (>80% correct in question type)
        // Identify improvement areas (<60% correct in question type)
        foreach ($questionTypes as $type => $stats) {
            $percentage = $stats['total'] > 0 ? ($stats['correct'] / $stats['total']) * 100 : 0;
            $typeLabel = self::getQuestionTypeLabel($type);
            
            if ($percentage >= 80) {
                $strengthAreas[] = $typeLabel . " ({$stats['correct']}/{$stats['total']})";
            } elseif ($percentage < 60) {
                $improvementAreas[] = $typeLabel . " ({$stats['correct']}/{$stats['total']})";
            }
        }
        
        $overallPercentage = $totalQuestions > 0 ? round(($correctAnswers / $totalQuestions) * 100, 1) : 0;
        
        return [
            'total_questions' => $totalQuestions,
            'correct_answers' => $correctAnswers,
            'overall_percentage' => $overallPercentage,
            'manually_reviewed' => $manuallyReviewed,
            'question_type_breakdown' => $questionTypes,
            'strength_areas' => $strengthAreas,
            'improvement_areas' => $improvementAreas,
            'performance_level' => self::getPerformanceLevel($overallPercentage)
        ];
    }
    
    /**
     * Get performance level based on percentage
     */
    private static function getPerformanceLevel($percentage)
    {
        if ($percentage >= 90) return 'Excellent';
        if ($percentage >= 80) return 'Good';
        if ($percentage >= 70) return 'Satisfactory';
        if ($percentage >= 60) return 'Needs Improvement';
        return 'Requires Attention';
    }
}
