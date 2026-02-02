<?php

namespace App\Services\AI\Agents;

use App\Models\AIUploadSession;
use App\Models\AIUploadProposal;
use App\Models\AIUploadLog;
use App\Services\QuestionTypeRegistry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;

class ContentUploadAgent
{
    protected string $model = 'gpt-5-mini';
    protected int $maxTokens = 4000;
    protected float $temperature = 0.7;

    /**
     * Process a session and generate content proposals
     */
    public function process(AIUploadSession $session): array
    {
        $startTime = microtime(true);
        
        try {
            $session->markAsProcessing();
            
            AIUploadLog::info($session->id, AIUploadLog::ACTION_SESSION_START, 'Starting content generation', [
                'content_type' => $session->content_type,
                'item_count' => $session->getItemCount(),
            ]);

            // Generate content based on type
            $proposals = match($session->content_type) {
                AIUploadSession::TYPE_QUESTION => $this->generateQuestions($session),
                AIUploadSession::TYPE_ASSESSMENT => $this->generateAssessments($session),
                AIUploadSession::TYPE_COURSE => $this->generateCourses($session),
                AIUploadSession::TYPE_MODULE => $this->generateModules($session),
                AIUploadSession::TYPE_LESSON => $this->generateLessons($session),
                AIUploadSession::TYPE_SLIDE => $this->generateSlides($session),
                AIUploadSession::TYPE_ARTICLE => $this->generateArticles($session),
                default => throw new \InvalidArgumentException("Unknown content type: {$session->content_type}"),
            };

            // Validate all proposals
            $validCount = 0;
            foreach ($proposals as $proposal) {
                if ($proposal->validate()) {
                    $validCount++;
                }
            }

            $session->update([
                'items_generated' => count($proposals),
                'current_quality_score' => $validCount / max(count($proposals), 1),
            ]);

            $session->markAsCompleted();

            AIUploadLog::info($session->id, AIUploadLog::ACTION_COMPLETE, 'Content generation completed', [
                'total_generated' => count($proposals),
                'valid_count' => $validCount,
                'duration_seconds' => round(microtime(true) - $startTime, 2),
            ]);

            return [
                'success' => true,
                'proposals' => $proposals,
                'stats' => [
                    'total' => count($proposals),
                    'valid' => $validCount,
                    'invalid' => count($proposals) - $validCount,
                ],
            ];

        } catch (\Exception $e) {
            Log::error('ContentUploadAgent Error', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            AIUploadLog::error($session->id, AIUploadLog::ACTION_ERROR, $e->getMessage());
            $session->markAsFailed($e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate questions for the question bank
     */
    protected function generateQuestions(AIUploadSession $session): array
    {
        $prompt = $this->buildQuestionPrompt($session);
        $response = $this->callOpenAI($session, $prompt, 'generate_questions');
        
        $questionsData = $this->parseJsonResponse($response);
        $proposals = [];

        foreach ($questionsData as $index => $questionData) {
            $proposals[] = $this->createProposal($session, AIUploadProposal::TYPE_QUESTION, $questionData, $index);
        }

        return $proposals;
    }

    /**
     * Generate assessments with questions
     */
    protected function generateAssessments(AIUploadSession $session): array
    {
        $prompt = $this->buildAssessmentPrompt($session);
        $response = $this->callOpenAI($session, $prompt, 'generate_assessments');
        
        $assessmentsData = $this->parseJsonResponse($response);
        $proposals = [];

        foreach ($assessmentsData as $index => $assessmentData) {
            // Create assessment proposal
            $assessmentProposal = $this->createProposal($session, AIUploadProposal::TYPE_ASSESSMENT, $assessmentData, $index);
            $proposals[] = $assessmentProposal;

            // Create question proposals as children
            if (!empty($assessmentData['questions'])) {
                foreach ($assessmentData['questions'] as $qIndex => $questionData) {
                    $proposals[] = $this->createProposal(
                        $session, 
                        AIUploadProposal::TYPE_QUESTION, 
                        $questionData, 
                        $qIndex,
                        $assessmentProposal->id,
                        'assessment'
                    );
                }
            }
        }

        return $proposals;
    }

    /**
     * Generate full courses with modules and lessons
     */
    protected function generateCourses(AIUploadSession $session): array
    {
        $prompt = $this->buildCoursePrompt($session);
        $response = $this->callOpenAI($session, $prompt, 'generate_courses');
        
        $coursesData = $this->parseJsonResponse($response);
        $proposals = [];

        foreach ($coursesData as $cIndex => $courseData) {
            $courseData = $this->normalizeCourseData($courseData);
            // Create course proposal
            $courseProposal = $this->createProposal($session, AIUploadProposal::TYPE_COURSE, $courseData, $cIndex);
            $proposals[] = $courseProposal;

            // Create module proposals as children
            if (!empty($courseData['modules'])) {
                foreach ($courseData['modules'] as $mIndex => $moduleData) {
                    $moduleProposal = $this->createProposal(
                        $session,
                        AIUploadProposal::TYPE_MODULE,
                        $moduleData,
                        $mIndex,
                        $courseProposal->id,
                        'course'
                    );
                    $proposals[] = $moduleProposal;

                    // Create lesson proposals as children of module
                    if (!empty($moduleData['lessons'])) {
                        foreach ($moduleData['lessons'] as $lIndex => $lessonData) {
                            $lessonProposal = $this->createProposal(
                                $session,
                                AIUploadProposal::TYPE_LESSON,
                                $lessonData,
                                $lIndex,
                                $moduleProposal->id,
                                'module'
                            );
                            $proposals[] = $lessonProposal;

                            // Create slide proposals as children of lesson
                            if (!empty($lessonData['slides'])) {
                                foreach ($lessonData['slides'] as $sIndex => $slideData) {
                                    $proposals[] = $this->createProposal(
                                        $session,
                                        AIUploadProposal::TYPE_SLIDE,
                                        $slideData,
                                        $sIndex,
                                        $lessonProposal->id,
                                        'lesson'
                                    );
                                }
                            }
                        }
                    }
                }
            }
        }

        return $proposals;
    }

    /**
     * Generate modules for existing course
     */
    protected function generateModules(AIUploadSession $session): array
    {
        $prompt = $this->buildModulePrompt($session);
        $response = $this->callOpenAI($session, $prompt, 'generate_modules');
        
        $modulesData = $this->parseJsonResponse($response);
        $proposals = [];

        foreach ($modulesData as $index => $moduleData) {
            $moduleData = $this->normalizeModuleData($moduleData);
            $moduleProposal = $this->createProposal($session, AIUploadProposal::TYPE_MODULE, $moduleData, $index);
            $proposals[] = $moduleProposal;

            // Create lesson proposals
            if (!empty($moduleData['lessons'])) {
                foreach ($moduleData['lessons'] as $lIndex => $lessonData) {
                    $lessonProposal = $this->createProposal(
                        $session,
                        AIUploadProposal::TYPE_LESSON,
                        $lessonData,
                        $lIndex,
                        $moduleProposal->id,
                        'module'
                    );
                    $proposals[] = $lessonProposal;

                    if (!empty($lessonData['slides'])) {
                        foreach ($lessonData['slides'] as $sIndex => $slideData) {
                            $proposals[] = $this->createProposal(
                                $session,
                                AIUploadProposal::TYPE_SLIDE,
                                $slideData,
                                $sIndex,
                                $lessonProposal->id,
                                'lesson'
                            );
                        }
                    }
                }
            }
        }

        return $proposals;
    }

    /**
     * Generate lessons with slides
     */
    protected function generateLessons(AIUploadSession $session): array
    {
        $prompt = $this->buildLessonPrompt($session);
        $response = $this->callOpenAI($session, $prompt, 'generate_lessons');
        
        $lessonsData = $this->parseJsonResponse($response);
        $proposals = [];

        foreach ($lessonsData as $index => $lessonData) {
            $lessonData = $this->normalizeLessonData($lessonData);
            $lessonProposal = $this->createProposal($session, AIUploadProposal::TYPE_LESSON, $lessonData, $index);
            $proposals[] = $lessonProposal;

            // Create slide proposals
            if (!empty($lessonData['slides'])) {
                foreach ($lessonData['slides'] as $sIndex => $slideData) {
                    $proposals[] = $this->createProposal(
                        $session,
                        AIUploadProposal::TYPE_SLIDE,
                        $slideData,
                        $sIndex,
                        $lessonProposal->id,
                        'lesson'
                    );
                }
            }
        }

        return $proposals;
    }

    /**
     * Generate individual slides
     */
    protected function generateSlides(AIUploadSession $session): array
    {
        $prompt = $this->buildSlidePrompt($session);
        $response = $this->callOpenAI($session, $prompt, 'generate_slides');
        
        $slidesData = $this->parseJsonResponse($response);
        $proposals = [];

        foreach ($slidesData as $index => $slideData) {
            $slideData = $this->normalizeSlideData($slideData);
            $proposals[] = $this->createProposal($session, AIUploadProposal::TYPE_SLIDE, $slideData, $index);
        }

        return $proposals;
    }

    /**
     * Generate articles
     */
    protected function generateArticles(AIUploadSession $session): array
    {
        $prompt = $this->buildArticlePrompt($session);
        $response = $this->callOpenAI($session, $prompt, 'generate_articles');
        
        $articlesData = $this->parseJsonResponse($response);
        $proposals = [];

        foreach ($articlesData as $index => $articleData) {
            $proposals[] = $this->createProposal($session, AIUploadProposal::TYPE_ARTICLE, $articleData, $index);
        }

        return $proposals;
    }

    /**
     * Create a proposal record
     */
    protected function createProposal(
        AIUploadSession $session,
        string $contentType,
        array $data,
        int $orderPosition,
        ?int $parentProposalId = null,
        ?string $parentType = null
    ): AIUploadProposal {
        $data = $this->normalizeYearGroupData($data);

        if ($contentType === AIUploadProposal::TYPE_QUESTION) {
            $data = $this->normalizeQuestionProposalData($data);
        }

        if ($contentType === AIUploadProposal::TYPE_ASSESSMENT) {
            $data = $this->normalizeAssessmentProposalData($data);
        }
        if ($contentType === AIUploadProposal::TYPE_ARTICLE) {
            $data = $this->normalizeArticleProposalData($data);
            if (empty($data['organization_id']) && $session->organization_id) {
                $data['organization_id'] = $session->organization_id;
            }
        }

        return AIUploadProposal::create([
            'session_id' => $session->id,
            'content_type' => $contentType,
            'status' => AIUploadProposal::STATUS_PENDING,
            'proposed_data' => $data,
            'original_data' => $data,
            'order_position' => $orderPosition,
            'parent_proposal_id' => $parentProposalId,
            'parent_type' => $parentType,
        ]);
    }

    protected function normalizeQuestionProposalData(array $data): array
    {
        $originalType = $data['question_type'] ?? null;
        $typeAliases = [
            'essay' => 'long_answer',
            'fill_blank' => 'cloze',
            'true_false' => 'mcq',
        ];
        if ($originalType && isset($typeAliases[$originalType])) {
            $data['question_type'] = $typeAliases[$originalType];
        }

        $questionData = $data['question_data'] ?? null;
        if (!is_array($questionData)) {
            $questionData = [];
        }
        $hasFlatQuestionText = isset($data['question_text']);
        $hasFlatOptions = isset($data['options']) && is_array($data['options']);
        $hasFlatExplanation = array_key_exists('explanation', $data);
        $hasFlatCorrectAnswer = array_key_exists('correct_answer', $data);

        if ($hasFlatQuestionText && !isset($questionData['question_text'])) {
            $questionData['question_text'] = $data['question_text'];
        }
        if ($hasFlatOptions && !isset($questionData['options'])) {
            $questionData['options'] = $data['options'];
        }
        if ($hasFlatCorrectAnswer && !isset($questionData['correct_answer'])) {
            $questionData['correct_answer'] = $data['correct_answer'];
        }
        if ($hasFlatExplanation && !isset($questionData['explanation'])) {
            $questionData['explanation'] = $data['explanation'];
        }

        if (!isset($data['question_type']) && is_array($questionData) && isset($questionData['options'])) {
            $data['question_type'] = 'mcq';
        }

        if (empty($data['question_type'])) {
            $data['question_type'] = 'short_answer';
        }

        if (!isset($data['marks']) || $data['marks'] === null) {
            $data['marks'] = 1;
        }

        if (empty($data['title'])) {
            $data['title'] = $questionData['question_text'] ?? null;
        }

        if (empty($questionData['question_text'])) {
            $questionData['question_text'] = $data['title'] ?? 'Untitled question';
            $data['title'] = $data['title'] ?? $questionData['question_text'];
        }

        $data['question_data'] = $questionData;

        if (($data['question_type'] ?? null) === 'matching' && is_array($questionData)) {
            $data = $this->normalizeMatchingQuestionData($data);
            $questionData = $data['question_data'] ?? $questionData;
        }

        if (in_array(($data['question_type'] ?? null), ['fill_blank', 'cloze'], true) && is_array($questionData)) {
            $data = $this->normalizeClozeQuestionData($data);
            $questionData = $data['question_data'] ?? $questionData;
        }

        if (($data['question_type'] ?? null) === 'ordering' && is_array($questionData)) {
            $data = $this->normalizeOrderingQuestionData($data);
            $questionData = $data['question_data'] ?? $questionData;
        }

        if (($data['question_type'] ?? null) === 'comprehension' && is_array($questionData)) {
            $data = $this->normalizeComprehensionQuestionData($data);
            $questionData = $data['question_data'] ?? $questionData;
        }

        if (($data['question_type'] ?? null) === 'image_grid_mcq' && is_array($questionData)) {
            $data = $this->normalizeImageGridQuestionData($data);
            $questionData = $data['question_data'] ?? $questionData;
        }

        if (($data['question_type'] ?? null) === 'short_answer' && is_array($questionData)) {
            $data = $this->normalizeShortAnswerQuestionData($data);
            $questionData = $data['question_data'] ?? $questionData;
        }

        if (($data['question_type'] ?? null) === 'long_answer' && is_array($questionData)) {
            $data = $this->normalizeLongAnswerQuestionData($data);
            $questionData = $data['question_data'] ?? $questionData;
        }

        if ($originalType === 'true_false') {
            $data = $this->normalizeTrueFalseQuestionData($data);
            $questionData = $data['question_data'] ?? $questionData;
        }

        if (empty($data['answer_schema']) || !is_array($data['answer_schema'])) {
            $data['answer_schema'] = QuestionTypeRegistry::getDefaultAnswerSchema($data['question_type'] ?? 'mcq');
        }

        if (($data['question_type'] ?? null) === 'mcq') {
            $options = $questionData['options'] ?? null;
            if (!is_array($options) || count($options) < 2) {
                $data['question_type'] = 'short_answer';
            }
        }

        if (($data['question_type'] ?? null) !== 'mcq') {
            $data['question_data'] = $questionData;
            return $data;
        }

        if (!is_array($questionData)) {
            return $data;
        }

        $options = $questionData['options'] ?? null;
        if (!is_array($options) || $options === []) {
            return $data;
        }

        $firstOption = $options[0] ?? null;
        if (is_array($firstOption) && array_key_exists('text', $firstOption)) {
            return $data;
        }

        $correctRaw = $questionData['correct_answer'] ?? data_get($data, 'answer_schema.correct_answer');
        $correctIndex = null;

        if (is_int($correctRaw)) {
            if ($correctRaw >= 1 && $correctRaw <= count($options)) {
                $correctIndex = $correctRaw - 1;
            } elseif ($correctRaw >= 0 && $correctRaw < count($options)) {
                $correctIndex = $correctRaw;
            }
        } elseif (is_string($correctRaw)) {
            $trimmed = trim($correctRaw);
            $upper = strtoupper($trimmed);
            if (strlen($upper) === 1 && ctype_alpha($upper)) {
                $correctIndex = ord($upper) - ord('A');
            } else {
                foreach ($options as $index => $option) {
                    if (strcasecmp((string) $option, $trimmed) === 0) {
                        $correctIndex = $index;
                        break;
                    }
                }
            }
        }

        $normalizedOptions = [];
        foreach ($options as $index => $option) {
            $normalizedOptions[] = [
                'id' => chr(97 + $index),
                'text' => is_scalar($option) ? (string) $option : (string) ($option['text'] ?? ''),
                'image' => is_array($option) ? ($option['image'] ?? null) : null,
                'is_correct' => $correctIndex === $index,
            ];
        }

        $questionData['options'] = $normalizedOptions;
        $questionData['allow_multiple'] = $questionData['allow_multiple'] ?? false;
        $questionData['shuffle_options'] = $questionData['shuffle_options'] ?? false;
        $data['question_data'] = $questionData;

        return $data;
    }

    protected function normalizeTrueFalseQuestionData(array $data): array
    {
        $questionData = $data['question_data'] ?? [];
        if (!is_array($questionData)) {
            $questionData = [];
        }

        $questionData['options'] = $questionData['options'] ?? ['True', 'False'];

        if (!isset($questionData['correct_answer'])) {
            $questionData['correct_answer'] = data_get($data, 'answer_schema.correct_answer');
        }

        $correct = $questionData['correct_answer'] ?? null;
        if (is_bool($correct)) {
            $questionData['correct_answer'] = $correct ? 'True' : 'False';
        } elseif (is_string($correct)) {
            $trimmed = strtolower(trim($correct));
            if (in_array($trimmed, ['true', 't', 'yes'], true)) {
                $questionData['correct_answer'] = 'True';
            } elseif (in_array($trimmed, ['false', 'f', 'no'], true)) {
                $questionData['correct_answer'] = 'False';
            }
        }

        $data['question_type'] = 'mcq';
        $data['question_data'] = $questionData;

        return $data;
    }

    protected function normalizeMatchingQuestionData(array $data): array
    {
        $questionData = $data['question_data'] ?? null;
        if (!is_array($questionData)) {
            return $data;
        }

        if (!empty($questionData['matching_pairs']) && is_array($questionData['matching_pairs'])) {
            return $data;
        }

        $pairs = [];
        $correctAnswer = $questionData['correct_answer'] ?? null;

        if (is_array($correctAnswer)) {
            foreach ($correctAnswer as $left => $rightData) {
                if (is_array($rightData)) {
                    $fraction = $rightData['fraction'] ?? null;
                    $decimal = $rightData['decimal'] ?? null;
                    $right = $fraction ?? $decimal ?? null;
                    if ($fraction && $decimal) {
                        $right = "{$fraction} ({$decimal})";
                    }
                } else {
                    $right = $rightData;
                }

                if ($left !== null && $right !== null) {
                    $pairs[] = ['left' => (string) $left, 'right' => (string) $right];
                }
            }
        } elseif (!empty($questionData['options']) && is_array($questionData['options'])) {
            foreach ($questionData['options'] as $option) {
                if (!is_array($option)) {
                    continue;
                }

                $left = $option['percentage'] ?? null;
                $fraction = $option['fraction'] ?? null;
                $decimal = $option['decimal'] ?? null;
                $right = $fraction ?? $decimal ?? null;
                if ($fraction && $decimal) {
                    $right = "{$fraction} ({$decimal})";
                }

                if ($left !== null && $right !== null) {
                    $pairs[] = ['left' => (string) $left, 'right' => (string) $right];
                }
            }
        }

        if ($pairs !== []) {
            $questionData['matching_pairs'] = $pairs;
            $questionData['shuffle_right'] = $questionData['shuffle_right'] ?? true;
            $questionData['instructions'] = $questionData['instructions']
                ?? 'Match each item on the left with the correct item on the right.';
            $data['question_data'] = $questionData;

            if (empty($data['answer_schema']) || !is_array($data['answer_schema'])) {
                $data['answer_schema'] = [
                    'partial_credit' => true,
                    'all_or_nothing' => false,
                ];
            }
        }

        return $data;
    }

    protected function normalizeOrderingQuestionData(array $data): array
    {
        $questionData = $data['question_data'] ?? null;
        if (!is_array($questionData)) {
            return $data;
        }

        $items = $questionData['items']
            ?? $questionData['order_items']
            ?? $questionData['correct_order']
            ?? $questionData['options']
            ?? null;
        if (is_array($items) && $items !== []) {
            $normalizedItems = array_values(array_map(static function ($item) {
                if (is_array($item)) {
                    return $item['text'] ?? $item['label'] ?? '';
                }
                return $item;
            }, $items));
            $normalizedItems = array_values(array_filter($normalizedItems, static function ($item) {
                return is_string($item) ? trim($item) !== '' : !empty($item);
            }));

            $fallbackCorrect = [];
            if (isset($questionData['correct_order']) && is_array($questionData['correct_order'])) {
                $fallbackCorrect = array_values(array_filter($questionData['correct_order'], static function ($item) {
                    return is_string($item) ? trim($item) !== '' : !empty($item);
                }));
            }

            $finalItems = $normalizedItems !== [] ? $normalizedItems : $fallbackCorrect;
            if ($finalItems !== []) {
                $questionData['items'] = $finalItems;
                $questionData['order_items'] = $questionData['order_items'] ?? $finalItems;
                $questionData['correct_order'] = $questionData['correct_order'] ?? $finalItems;
            }
            $questionData['shuffle'] = $questionData['shuffle'] ?? true;
            $questionData['instructions'] = $questionData['instructions']
                ?? 'Arrange the items in the correct order.';
        }

        $data['question_data'] = $questionData;

        if (empty($data['answer_schema']) || !is_array($data['answer_schema'])) {
            $data['answer_schema'] = [
                'partial_credit' => false,
                'strict_order' => true,
            ];
        }

        return $data;
    }

    protected function normalizeComprehensionQuestionData(array $data): array
    {
        $questionData = $data['question_data'] ?? null;
        if (!is_array($questionData)) {
            return $data;
        }

        $passage = $questionData['passage'] ?? null;
        if (is_string($passage)) {
            $questionData['passage'] = [
                'title' => '',
                'content' => $passage,
                'source' => '',
            ];
        } elseif (is_array($passage)) {
            $questionData['passage'] = [
                'title' => $passage['title'] ?? '',
                'content' => $passage['content'] ?? ($passage['text'] ?? ''),
                'source' => $passage['source'] ?? '',
            ];
        }

        $subQuestions = $questionData['sub_questions'] ?? $questionData['questions'] ?? [];
        if (is_array($subQuestions)) {
            $normalizedSubs = [];
            foreach ($subQuestions as $index => $subQuestion) {
                if (!is_array($subQuestion)) {
                    continue;
                }

                $subType = $subQuestion['type'] ?? $subQuestion['question_type'] ?? 'short_answer';
                if ($subType === 'essay') {
                    $subType = 'long_answer';
                }
                if ($subType === 'true_false') {
                    $subType = 'mcq';
                }

                $normalized = [
                    'type' => $subType,
                    'question_text' => $subQuestion['question_text'] ?? $subQuestion['text'] ?? '',
                    'marks' => $subQuestion['marks'] ?? 1,
                ];

                if ($subType === 'mcq') {
                    $options = $subQuestion['options'] ?? [];
                    $correctRaw = $subQuestion['correct_answer'] ?? null;
                    $normalizedOptions = [];
                    $correctIndex = null;

                    if (is_array($options) && $options !== []) {
                        if (is_string($correctRaw)) {
                            $upper = strtoupper(trim($correctRaw));
                            if (strlen($upper) === 1 && ctype_alpha($upper)) {
                                $correctIndex = ord($upper) - ord('A');
                            } else {
                                foreach ($options as $optIndex => $optValue) {
                                    if (strcasecmp((string) $optValue, $correctRaw) === 0) {
                                        $correctIndex = $optIndex;
                                        break;
                                    }
                                }
                            }
                        } elseif (is_int($correctRaw)) {
                            $correctIndex = $correctRaw;
                        }

                        foreach ($options as $optIndex => $optValue) {
                            $normalizedOptions[] = [
                                'id' => chr(97 + $optIndex),
                                'text' => is_scalar($optValue) ? (string) $optValue : (string) ($optValue['text'] ?? ''),
                                'is_correct' => $correctIndex === $optIndex,
                            ];
                        }
                    }

                    $normalized['options'] = $normalizedOptions;
                }

                $normalizedSubs[] = $normalized;
            }

            if ($normalizedSubs !== []) {
                $questionData['sub_questions'] = $normalizedSubs;
            }
        }

        if (empty($questionData['sub_questions']) && isset($questionData['question_text'])) {
            $subType = !empty($questionData['options']) ? 'mcq' : 'short_answer';
            $subQuestion = [
                'type' => $subType,
                'question_text' => (string) $questionData['question_text'],
                'marks' => $data['marks'] ?? 1,
            ];

            if ($subType === 'mcq') {
                $options = $questionData['options'] ?? [];
                $correctRaw = $questionData['correct_answer'] ?? null;
                $normalizedOptions = [];
                $correctIndex = null;

                if (is_string($correctRaw)) {
                    $upper = strtoupper(trim($correctRaw));
                    if (strlen($upper) === 1 && ctype_alpha($upper)) {
                        $correctIndex = ord($upper) - ord('A');
                    } else {
                        foreach ($options as $optIndex => $optValue) {
                            if (strcasecmp((string) $optValue, $correctRaw) === 0) {
                                $correctIndex = $optIndex;
                                break;
                            }
                        }
                    }
                } elseif (is_int($correctRaw)) {
                    $correctIndex = $correctRaw;
                }

                foreach ($options as $optIndex => $optValue) {
                    $normalizedOptions[] = [
                        'id' => chr(97 + $optIndex),
                        'text' => is_scalar($optValue) ? (string) $optValue : (string) ($optValue['text'] ?? ''),
                        'is_correct' => $correctIndex === $optIndex,
                    ];
                }

                $subQuestion['options'] = $normalizedOptions;
            } else {
                $subQuestion['answer_schema'] = [
                    'model_answer' => $questionData['correct_answer'] ?? '',
                    'key_points' => $questionData['keywords'] ?? $questionData['key_points'] ?? [],
                    'max_marks' => $data['marks'] ?? 1,
                    'case_sensitive' => $questionData['case_sensitive'] ?? false,
                    'exact_match' => false,
                    'requires_manual_review' => true,
                ];
            }

            $questionData['sub_questions'] = [$subQuestion];
        }

        if (empty($questionData['passage']['content']) && isset($questionData['question_text'])) {
            $questionData['passage'] = [
                'title' => '',
                'content' => (string) $questionData['question_text'],
                'source' => '',
            ];
        }

        $questionData['instructions'] = $questionData['instructions']
            ?? 'Read the passage carefully and answer the questions that follow.';

        $data['question_data'] = $questionData;

        return $data;
    }

    protected function normalizeImageGridQuestionData(array $data): array
    {
        $questionData = $data['question_data'] ?? null;
        if (!is_array($questionData)) {
            return $data;
        }

        if (!empty($questionData['images']) && is_array($questionData['images'])) {
            return $data;
        }

        $options = $questionData['image_options'] ?? $questionData['options'] ?? [];
        if (!is_array($options) || $options === []) {
            return $data;
        }

        $correctRaw = $questionData['correct_answer'] ?? data_get($data, 'answer_schema.correct_answer');
        $correctIndex = null;

        if (is_string($correctRaw)) {
            $upper = strtoupper(trim($correctRaw));
            if (strlen($upper) === 1 && ctype_alpha($upper)) {
                $correctIndex = ord($upper) - ord('A');
            } else {
                foreach ($options as $optIndex => $optValue) {
                    $label = is_array($optValue) ? ($optValue['label'] ?? $optValue['text'] ?? '') : (string) $optValue;
                    if (strcasecmp($label, $correctRaw) === 0) {
                        $correctIndex = $optIndex;
                        break;
                    }
                }
            }
        } elseif (is_int($correctRaw)) {
            $correctIndex = $correctRaw;
        }

        $images = [];
        foreach ($options as $index => $option) {
            $label = is_array($option) ? ($option['label'] ?? $option['text'] ?? '') : (string) $option;
            $images[] = [
                'id' => 'img_' . ($index + 1),
                'url' => 'pending_upload_' . ($index + 1),
                'pending_upload' => true,
                'is_correct' => $correctIndex === $index,
                'alt' => $label,
                'description' => $label,
            ];
        }

        $questionData['images'] = $images;
        $questionData['allow_multiple'] = $questionData['allow_multiple'] ?? false;
        $questionData['shuffle_images'] = $questionData['shuffle_images'] ?? false;
        $questionData['grid_columns'] = $questionData['grid_columns'] ?? 3;
        $questionData['instructions'] = $questionData['instructions'] ?? 'Select the correct image(s).';
        unset($questionData['image_options']);

        $data['question_data'] = $questionData;

        return $data;
    }

    protected function normalizeLongAnswerQuestionData(array $data): array
    {
        $questionData = $data['question_data'] ?? null;
        if (!is_array($questionData)) {
            return $data;
        }

        if (empty($questionData['question_text']) && isset($data['question_text'])) {
            $questionData['question_text'] = $data['question_text'];
        }

        if (empty($questionData['sample_answer']) && isset($questionData['correct_answer'])) {
            $questionData['sample_answer'] = $questionData['correct_answer'];
        }

        $questionData['rubric_criteria'] = $questionData['rubric_criteria'] ?? [
            ['criterion' => 'Content Knowledge', 'points' => 40, 'description' => 'Demonstrates understanding of key concepts'],
            ['criterion' => 'Organization', 'points' => 20, 'description' => 'Clear structure and logical flow'],
            ['criterion' => 'Evidence/Examples', 'points' => 20, 'description' => 'Uses relevant examples and evidence'],
            ['criterion' => 'Writing Quality', 'points' => 20, 'description' => 'Grammar, vocabulary, and clarity'],
        ];

        $questionData['expected_structure'] = $questionData['expected_structure'] ?? ['Introduction', 'Body Paragraphs', 'Conclusion'];

        $questionData['instructions'] = $questionData['instructions']
            ?? 'Provide a detailed response in essay format.';

        $data['question_data'] = $questionData;

        if (empty($data['answer_schema']) || !is_array($data['answer_schema'])) {
            $data['answer_schema'] = [
                'type' => 'long_text',
                'grading_type' => 'manual',
                'max_marks' => $data['marks'] ?? 10,
                'rubric_based' => true,
                'allows_rich_text' => true,
            ];
        }

        return $data;
    }

    protected function normalizeShortAnswerQuestionData(array $data): array
    {
        $questionData = $data['question_data'] ?? null;
        if (!is_array($questionData)) {
            return $data;
        }

        if (empty($questionData['question_text']) && isset($data['question_text'])) {
            $questionData['question_text'] = $data['question_text'];
        }

        $modelAnswer = $questionData['correct_answer']
            ?? data_get($data, 'answer_schema.correct_answer')
            ?? $questionData['expected_answer']
            ?? '';
        if (empty($questionData['expected_answer']) && $modelAnswer !== '') {
            $questionData['expected_answer'] = $modelAnswer;
        }

        $data['question_data'] = $questionData;

        $keywords = $questionData['keywords'] ?? $questionData['key_points'] ?? [];
        if (!is_array($keywords)) {
            $keywords = [];
        }

        $data['answer_schema'] = [
            'model_answer' => $modelAnswer,
            'key_points' => $keywords,
            'max_marks' => $data['marks'] ?? 1,
            'case_sensitive' => $questionData['case_sensitive'] ?? false,
            'exact_match' => false,
            'requires_manual_review' => true,
            'grading_rubric' => [
                'excellent' => ['min_points' => 3, 'marks' => 1.0],
                'good' => ['min_points' => 2, 'marks' => 0.7],
                'fair' => ['min_points' => 1, 'marks' => 0.4],
                'poor' => ['min_points' => 0, 'marks' => 0.0],
            ],
        ];

        return $data;
    }

    protected function normalizeClozeQuestionData(array $data): array
    {
        $questionData = $data['question_data'] ?? null;
        if (!is_array($questionData)) {
            return $data;
        }

        $data['question_type'] = 'cloze';

        if (isset($questionData['blanks']) && is_array($questionData['blanks']) && $questionData['blanks'] !== []) {
            $questionData['instructions'] = $questionData['instructions']
                ?? 'Fill in the blanks with appropriate words.';
            if (!isset($questionData['passage']) && isset($questionData['question_text'])) {
                $questionData['passage'] = $questionData['question_text'];
            }
            $data['question_data'] = $questionData;
            return $data;
        }

        $passage = $questionData['passage'] ?? $questionData['question_text'] ?? null;
        $correctAnswer = $questionData['correct_answer'] ?? null;

        if ($passage !== null && $correctAnswer !== null) {
            $correctAnswers = is_array($correctAnswer) ? $correctAnswer : [(string) $correctAnswer];
            $questionData['passage'] = $passage;
            $questionData['blanks'] = [[
                'id' => 'blank1',
                'correct_answers' => $correctAnswers,
                'case_sensitive' => false,
                'accept_partial' => false,
                'placeholder' => '',
                'max_length' => 50,
            ]];
            $questionData['instructions'] = $questionData['instructions']
                ?? 'Fill in the blanks with appropriate words.';
            $data['question_data'] = $questionData;

            if (empty($data['answer_schema']) || !is_array($data['answer_schema'])) {
                $data['answer_schema'] = [
                    'partial_credit_enabled' => true,
                    'partial_credit_threshold' => 50,
                    'case_sensitive_default' => false,
                    'allow_synonyms' => false,
                    'synonym_list' => [],
                ];
            }
        }

        return $data;
    }

    protected function normalizeAssessmentProposalData(array $data): array
    {
        $typeMap = [
            'quiz' => 'mcq',
            'test' => 'mcq',
            'exam' => 'mcq',
            'practice' => 'mcq',
        ];

        $rawType = strtolower(trim((string) ($data['type'] ?? '')));
        $normalizedType = $typeMap[$rawType] ?? $rawType;
        $validTypes = ['mcq', 'short_answer', 'essay', 'mixed'];

        if ($normalizedType === '') {
            $normalizedType = $this->inferAssessmentTypeFromQuestions($data['questions'] ?? []);
        }

        if (!in_array($normalizedType, $validTypes, true)) {
            $normalizedType = $this->inferAssessmentTypeFromQuestions($data['questions'] ?? []);
        }

        $data['type'] = $normalizedType;

        $statusMap = [
            'draft' => 'inactive',
            'published' => 'active',
        ];
        $rawStatus = strtolower(trim((string) ($data['status'] ?? '')));
        $normalizedStatus = $statusMap[$rawStatus] ?? $rawStatus;
        $validStatuses = ['active', 'inactive', 'archived'];

        if (!in_array($normalizedStatus, $validStatuses, true)) {
            $normalizedStatus = 'inactive';
        }

        $data['status'] = $normalizedStatus;

        return $data;
    }

    protected function inferAssessmentTypeFromQuestions(array $questions): string
    {
        if ($questions === []) {
            return 'mcq';
        }

        $types = [];
        foreach ($questions as $question) {
            if (!is_array($question)) {
                continue;
            }

            $qType = $question['question_type'] ?? $question['type'] ?? null;
            if (!$qType && isset($question['options'])) {
                $qType = 'mcq';
            }

            if ($qType) {
                $types[] = strtolower((string) $qType);
            }
        }

        $types = array_values(array_unique($types));
        if ($types === []) {
            return 'mcq';
        }

        if (count($types) === 1 && in_array($types[0], ['mcq', 'short_answer', 'essay'], true)) {
            return $types[0];
        }

        return 'mixed';
    }

    protected function normalizeYearGroupData(array $data): array
    {
        foreach (['year_group', 'grade'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field])) {
                continue;
            }

            $value = trim($data[$field]);
            if ($value === '') {
                continue;
            }

            if (preg_match('/^pre[-\s]?k$/i', $value)) {
                $data[$field] = 'Pre-K';
                continue;
            }

            if (preg_match('/^(k|kg|kindergarten)$/i', $value)) {
                $data[$field] = 'Kindergarten';
                continue;
            }

            if (preg_match('/^year\s*(\d{1,2})$/i', $value, $matches)) {
                $data[$field] = 'Grade ' . $matches[1];
                continue;
            }

            if (preg_match('/^grade\s*(\d{1,2})$/i', $value, $matches)) {
                $data[$field] = 'Grade ' . $matches[1];
            }
        }

        return $data;
    }

    /**
     * Call OpenAI API
     */
    protected function callOpenAI(AIUploadSession $session, string $prompt, string $action): string
    {
        $startTime = microtime(true);
        
        $messages = [
            ['role' => 'system', 'content' => $this->getSystemPrompt($session->content_type)],
            ['role' => 'user', 'content' => $prompt],
        ];

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'max_completion_tokens' => $this->maxTokens,
            'response_format' => ['type' => 'json_object'],
        ];

        // GPT-5 models don't support temperature parameter
        if (!str_starts_with($this->model, 'gpt-5')) {
            $payload['temperature'] = $this->temperature;
        }

        $response = OpenAI::chat()->create($payload);

        $responseData = $response->toArray();
        $content = $responseData['choices'][0]['message']['content'] ?? '';
        
        $durationMs = (int) ((microtime(true) - $startTime) * 1000);
        $tokensInput = $responseData['usage']['prompt_tokens'] ?? 0;
        $tokensOutput = $responseData['usage']['completion_tokens'] ?? 0;

        AIUploadLog::aiInteraction(
            $session->id,
            $action,
            "Generated content for {$session->content_type}",
            $this->model,
            $tokensInput,
            $tokensOutput,
            $durationMs
        );

        $session->incrementIteration();

        return $content;
    }

    /**
     * Parse JSON response from OpenAI
     */
    protected function parseJsonResponse(string $response): array
    {
        $clean = $this->extractJsonString($response);
        $clean = $this->sanitizeJsonString($clean);
        $clean = $this->escapeControlCharsInStrings($clean);
        $decoded = json_decode($clean, true, 512, JSON_INVALID_UTF8_IGNORE);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $repaired = $this->repairJsonString($clean);
            $repaired = $this->escapeControlCharsInStrings($repaired);
            $decoded = json_decode($repaired, true, 512, JSON_INVALID_UTF8_IGNORE);
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            $snippet = mb_substr($clean, 0, 1000);
            throw new \RuntimeException('Failed to parse AI response as JSON: ' . json_last_error_msg() . ' | snippet: ' . $snippet);
        }

        // Handle different response structures
        if (isset($decoded['items'])) {
            return $decoded['items'];
        }
        if (isset($decoded['questions'])) {
            return $decoded['questions'];
        }
        if (isset($decoded['assessments'])) {
            return $decoded['assessments'];
        }
        if (isset($decoded['courses'])) {
            return $decoded['courses'];
        }
        if (isset($decoded['modules'])) {
            return $decoded['modules'];
        }
        if (isset($decoded['lessons'])) {
            return $decoded['lessons'];
        }
        if (isset($decoded['slides'])) {
            return $decoded['slides'];
        }
        if (isset($decoded['articles'])) {
            return $decoded['articles'];
        }

        // If it's already an array of items
        if (is_array($decoded) && isset($decoded[0])) {
            return $decoded;
        }

        // Single item response
        return [$decoded];
    }

    /**
     * Get system prompt based on content type
     */
    protected function getSystemPrompt(string $contentType): string
    {
        $basePrompt = "You are an expert educational content creator. Generate high-quality, pedagogically sound content. Always respond with valid JSON only. No extra text, no markdown, no code fences.";

        return match($contentType) {
            AIUploadSession::TYPE_QUESTION => $basePrompt . "\n\n" . $this->getQuestionSystemPrompt(),
            AIUploadSession::TYPE_ASSESSMENT => $basePrompt . "\n\n" . $this->getAssessmentSystemPrompt(),
            AIUploadSession::TYPE_COURSE => $basePrompt . "\n\n" . $this->getCourseSystemPrompt(),
            AIUploadSession::TYPE_MODULE => $basePrompt . "\n\n" . $this->getModuleSystemPrompt(),
            AIUploadSession::TYPE_LESSON => $basePrompt . "\n\n" . $this->getLessonSystemPrompt(),
            AIUploadSession::TYPE_SLIDE => $basePrompt . "\n\n" . $this->getSlideSystemPrompt(),
            AIUploadSession::TYPE_ARTICLE => $basePrompt . "\n\n" . $this->getArticleSystemPrompt(),
            default => $basePrompt,
        };
    }

    protected function extractJsonString(string $response): string
    {
        $trimmed = trim($response);
        $trimmed = preg_replace('/^```(?:json)?/i', '', $trimmed);
        $trimmed = preg_replace('/```$/', '', $trimmed);
        $trimmed = trim($trimmed);

        $firstBrace = strpos($trimmed, '{');
        $firstBracket = strpos($trimmed, '[');
        if ($firstBrace === false && $firstBracket === false) {
            return $trimmed;
        }

        $start = $firstBrace;
        if ($firstBracket !== false && ($firstBrace === false || $firstBracket < $firstBrace)) {
            $start = $firstBracket;
        }

        $jsonCandidate = substr($trimmed, $start);
        $length = strlen($jsonCandidate);
        $stack = [];
        $inString = false;
        $escape = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $jsonCandidate[$i];

            if ($inString) {
                if ($escape) {
                    $escape = false;
                    continue;
                }
                if ($char === '\\') {
                    $escape = true;
                    continue;
                }
                if ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }

            if ($char === '{' || $char === '[') {
                $stack[] = $char;
            } elseif ($char === '}' || $char === ']') {
                array_pop($stack);
                if (empty($stack)) {
                    return substr($jsonCandidate, 0, $i + 1);
                }
            }
        }

        return $jsonCandidate;
    }

    protected function sanitizeJsonString(string $json): string
    {
        if (!mb_check_encoding($json, 'UTF-8')) {
            $json = @iconv('UTF-8', 'UTF-8//IGNORE', $json) ?: $json;
        }
        $json = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $json);
        $json = preg_replace('/[\x{2028}\x{2029}]/u', '', $json);
        return $json;
    }

    protected function repairJsonString(string $json): string
    {
        $repaired = $json;
        $repaired = preg_replace('/,\s*([}\]])/', '$1', $repaired);
        $repaired = preg_replace('/\r\n/', '\n', $repaired);
        $repaired = preg_replace('/\r/', '\n', $repaired);
        return $repaired;
    }

    protected function escapeControlCharsInStrings(string $json): string
    {
        $length = strlen($json);
        $result = '';
        $inString = false;
        $escape = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];

            if ($inString) {
                if ($escape) {
                    $escape = false;
                    $result .= $char;
                    continue;
                }

                if ($char === '\\') {
                    $escape = true;
                    $result .= $char;
                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                    $result .= $char;
                    continue;
                }

                if ($char === "\n") {
                    $result .= '\\n';
                    continue;
                }
                if ($char === "\r") {
                    $result .= '\\r';
                    continue;
                }
                if ($char === "\t") {
                    $result .= '\\t';
                    continue;
                }
            } else {
                if ($char === '"') {
                    $inString = true;
                }
            }

            $result .= $char;
        }

        return $result;
    }

    protected function getQuestionSystemPrompt(): string
    {
        return <<<PROMPT
Generate questions for an educational question bank.

Each question MUST have this exact structure:
{
  "title": "Brief descriptive title for the question",
  "category": "Subject area (e.g., Mathematics, English, Science)",
  "subcategory": "Specific topic (e.g., Algebra, Grammar, Biology)",
  "grade": "Year group (e.g., Pre-K, Kindergarten, Grade 7)",
  "question_type": "mcq|short_answer|long_answer|matching|cloze|ordering|comprehension|image_grid_mcq|true_false",
  "question_data": {
    "question_text": "The actual question text",
    "options": ["Option A", "Option B", "Option C", "Option D"],  // for MCQ
    "correct_answer": "The correct answer or index",
    "explanation": "Why this is the correct answer"
  },
  "answer_schema": {
    "correct_answer": "A|B|C|D or the answer text",
    "keywords": ["keyword1", "keyword2"],  // for short_answer/essay
    "case_sensitive": false
  },
  "difficulty_level": 5,  // 1-10 scale
  "estimated_time_minutes": 2,
  "marks": 1,
  "hints": ["Hint 1", "Hint 2"],
  "solutions": ["Step 1: ...", "Step 2: ..."],
  "tags": ["algebra", "equations"]
}

Respond with: {"questions": [...]}
PROMPT;
    }

    protected function getAssessmentSystemPrompt(): string
    {
        return <<<PROMPT
Generate assessments with embedded questions.

Each assessment MUST have this structure:
{
  "title": "Assessment title",
  "year_group": "Grade 8",
  "description": "Assessment description",
  "type": "mcq|short_answer|essay|mixed",
  "status": "active|inactive|archived",
  "time_limit": 30,  // minutes
  "retake_allowed": true,
  "questions": [
    // Array of question objects (same structure as question bank)
  ]
}

Each question MUST include:
- "title"
- "question_type": one of "mcq|short_answer|matching|cloze|ordering|comprehension|long_answer"
- "question_data" with the same fields as the Question Bank creator uses.

Respond with: {"assessments": [...]}
PROMPT;
    }

    protected function getCourseSystemPrompt(): string
    {
        return <<<PROMPT
Generate complete course structures with modules, lessons, and slides.

Each course MUST have this structure:
{
  "title": "Course title",
  "year_group": "Grade 8",
  "description": "Course description",
  "status": "draft",
  "metadata": {
    "category": "Mathematics",
    "difficulty_level": "beginner",
    "learning_objectives": "...",
    "estimated_duration_minutes": 600
  },
  "modules": [
    {
      "title": "Module title",
      "description": "Module description",
      "order_position": 0,
      "metadata": {
        "estimated_duration_minutes": 120
      },
      "lessons": [
        {
          "title": "Lesson title",
          "description": "Lesson description",
          "lesson_type": "interactive|video|reading|practice|assessment",
          "delivery_mode": "self_paced|live_interactive|hybrid",
          "estimated_minutes": 20,
          "enable_ai_help": true,
          "enable_tts": true,
          "slides": [
            {
              "title": "Slide title",
              "order_position": 0,
              "estimated_seconds": 60,
              "blocks": [
                {"type": "text", "content": {"text": "Slide Title", "fontSize": "h1"}},
                {"type": "text", "content": {"text": "Slide content..."}}
              ]
            }
          ]
        }
      ]
    }
  ]
}

Use ONLY these block types:
text, image, video, callout, embed, timer, reflection, whiteboard, code, table, divider, question, upload

Respond with: {"courses": [...]}
PROMPT;
    }

    protected function getModuleSystemPrompt(): string
    {
        return <<<PROMPT
Generate modules with lessons and slides.

Each module MUST have this structure:
{
  "title": "Module title",
  "description": "Module description",
  "order_position": 0,
  "status": "draft",
  "metadata": {
    "estimated_duration_minutes": 120
  },
  "lessons": [
    {
      "title": "Lesson title",
      "description": "Lesson description",
      "lesson_type": "interactive|video|reading|practice|assessment",
      "delivery_mode": "self_paced|live_interactive|hybrid",
      "estimated_minutes": 20,
      "enable_ai_help": true,
      "enable_tts": true,
      "slides": [...]
    }
  ]
}

Respond with: {"modules": [...]}
PROMPT;
    }

    protected function getLessonSystemPrompt(): string
    {
        return <<<PROMPT
Generate lessons with slides.

Each lesson MUST have this structure:
{
  "title": "Lesson title",
  "description": "Lesson description",
  "year_group": "Grade 8",
  "lesson_type": "interactive|video|reading|practice|assessment",
  "delivery_mode": "self_paced|live_interactive|hybrid",
  "status": "draft",
  "estimated_minutes": 20,
  "enable_ai_help": true,
  "enable_tts": true,
  "slides": [
    {
      "title": "Slide title",
      "order_position": 0,
      "estimated_seconds": 60,
      "blocks": [
        {"type": "text", "content": {"text": "Title", "fontSize": "h1"}},
        {"type": "text", "content": {"text": "Content"}},
        {"type": "image", "content": {"alt": "Description"}},
        {"type": "question", "content": {"question_text": "...", "question_type": "mcq", "options": [...]}}
      ]
    }
  ]
}

Available block types: text, image, video, callout, embed, timer, reflection, whiteboard, code, table, divider, question, upload

Respond with: {"lessons": [...]}
PROMPT;
    }

    protected function getSlideSystemPrompt(): string
    {
        return <<<PROMPT
Generate individual slides with blocks.

Each slide MUST have this structure:
{
  "title": "Slide title",
  "order_position": 0,
  "estimated_seconds": 60,
  "auto_advance": false,
  "min_time_seconds": 30,
  "blocks": [
    {"type": "text", "content": {"text": "Slide Title", "fontSize": "h1"}},
    {"type": "text", "content": {"text": "Paragraph content..."}},
    {"type": "image", "content": {"alt": "Image description"}},
    {"type": "question", "content": {
      "question_text": "What is...?",
      "question_type": "mcq",
      "options": ["A", "B", "C", "D"]
    }}
  ],
  "teacher_notes": "Notes for the teacher..."
}

Available block types:
- text: {text, fontSize?}
- image: {url?, alt?, caption?}
- video: {url?, caption?}
- callout: {type?, text, title?}
- embed: {url}
- timer: {duration_seconds}
- reflection: {prompt, placeholder?}
- whiteboard: {instructions}
- code: {code, language?}
- table: {headers, rows}
- divider: {style?}
- question: {question_text, question_type, options?}
- upload: {instructions, allowed_types?, max_size_mb?}

Respond with: {"slides": [...]}
PROMPT;
    }

    protected function normalizeCourseData(array $courseData): array
    {
        if (isset($courseData['level'])) {
            unset($courseData['level']);
        }
        if (isset($courseData['category'])) {
            if (!isset($courseData['metadata']) || !is_array($courseData['metadata'])) {
                $courseData['metadata'] = [];
            }
            $courseData['metadata']['category'] = $courseData['category'];
            unset($courseData['category']);
        }
        if (isset($courseData['estimated_duration_minutes'])) {
            if (!isset($courseData['metadata']) || !is_array($courseData['metadata'])) {
                $courseData['metadata'] = [];
            }
            $courseData['metadata']['estimated_duration_minutes'] = $courseData['estimated_duration_minutes'];
            unset($courseData['estimated_duration_minutes']);
        }

        if (!isset($courseData['modules']) || !is_array($courseData['modules'])) {
            return $courseData;
        }

        foreach ($courseData['modules'] as $index => $moduleData) {
            $courseData['modules'][$index] = $this->normalizeModuleData($moduleData);
        }

        return $courseData;
    }

    protected function normalizeModuleData(array $moduleData): array
    {
        if (isset($moduleData['estimated_duration_minutes'])) {
            if (!isset($moduleData['metadata']) || !is_array($moduleData['metadata'])) {
                $moduleData['metadata'] = [];
            }
            $moduleData['metadata']['estimated_duration_minutes'] = $moduleData['estimated_duration_minutes'];
            unset($moduleData['estimated_duration_minutes']);
        }

        if (!isset($moduleData['lessons']) || !is_array($moduleData['lessons'])) {
            return $moduleData;
        }

        foreach ($moduleData['lessons'] as $index => $lessonData) {
            $moduleData['lessons'][$index] = $this->normalizeLessonData($lessonData);
        }

        return $moduleData;
    }

    protected function normalizeLessonData(array $lessonData): array
    {
        if (!empty($lessonData['lesson_type'])) {
            $lessonType = strtolower((string) $lessonData['lesson_type']);
            $lessonData['lesson_type'] = match ($lessonType) {
                'content' => 'interactive',
                default => $lessonType,
            };
        }

        if (!empty($lessonData['delivery_mode'])) {
            $deliveryMode = strtolower((string) $lessonData['delivery_mode']);
            $lessonData['delivery_mode'] = match ($deliveryMode) {
                'live' => 'live_interactive',
                default => $deliveryMode,
            };
        }

        if (!isset($lessonData['enable_ai_help'])) {
            $lessonData['enable_ai_help'] = true;
        }
        if (!isset($lessonData['enable_tts'])) {
            $lessonData['enable_tts'] = true;
        }

        if (!isset($lessonData['slides']) || !is_array($lessonData['slides'])) {
            return $lessonData;
        }

        foreach ($lessonData['slides'] as $index => $slideData) {
            $lessonData['slides'][$index] = $this->normalizeSlideData($slideData);
        }

        return $lessonData;
    }

    protected function normalizeSlideData(array $slideData): array
    {
        $blocks = $slideData['blocks'] ?? [];
        if (!is_array($blocks)) {
            $blocks = [];
        }

        if ($blocks === []) {
            $blocks[] = [
                'id' => (string) Str::uuid(),
                'type' => 'text',
                'order' => 0,
                'content' => ['text' => $slideData['title'] ?? 'Slide content'],
                'settings' => ['visible' => true, 'locked' => false],
            ];
        }

        $normalized = [];
        foreach ($blocks as $index => $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = strtolower((string) ($block['type'] ?? 'text'));
            $content = $block['content'] ?? [];
            if (!is_array($content)) {
                $content = [];
            }

            if ($type === 'title') {
                $type = 'text';
                $content = [
                    'text' => $content['text'] ?? $block['text'] ?? '',
                    'fontSize' => $content['fontSize'] ?? 'h1',
                ];
            }

            if ($type === 'task') {
                $type = 'callout';
                $content = [
                    'type' => 'info',
                    'text' => $content['instruction'] ?? $content['text'] ?? $block['instruction'] ?? 'Task',
                ];
            }

            if ($type === 'question') {
                if (isset($content['type']) && !isset($content['question_type'])) {
                    $content['question_type'] = $content['type'];
                    unset($content['type']);
                }
            }

            if ($type === 'timer' && isset($content['seconds']) && !isset($content['duration_seconds'])) {
                $content['duration_seconds'] = $content['seconds'];
                unset($content['seconds']);
            }

            if ($type === 'upload' && isset($content['instruction']) && !isset($content['instructions'])) {
                $content['instructions'] = $content['instruction'];
                unset($content['instruction']);
            }

            $normalized[] = [
                'id' => $block['id'] ?? (string) Str::uuid(),
                'type' => $type,
                'order' => $block['order'] ?? $index,
                'content' => $content,
                'settings' => $block['settings'] ?? [
                    'visible' => true,
                    'locked' => false,
                ],
                'metadata' => $block['metadata'] ?? [
                    'created_at' => now()->toISOString(),
                    'ai_generated' => true,
                    'version' => 1,
                ],
            ];
        }

        $slideData['blocks'] = $normalized;
        $slideData['order_position'] = $slideData['order_position'] ?? 0;
        $slideData['estimated_seconds'] = $slideData['estimated_seconds'] ?? 60;
        $slideData['auto_advance'] = $slideData['auto_advance'] ?? false;

        return $slideData;
    }

    protected function getArticleSystemPrompt(): string
    {
        return <<<PROMPT
Generate educational articles.

Each article MUST have this structure:
{
  "title": "Article title",
  "name": "article-slug",
  "category": "Category name",
  "tag": "tag1,tag2,tag3",
  "description": "Brief description/excerpt",
  "body_type": "pdf|template",
  "article_template": "Template body if body_type is template",
  "author": "Author Name",
  "sections": [
    {
      "header": "Section title",
      "body": "Section content..."
    }
  ],
  "key_attributes": ["attribute1", "attribute2"],
  "scheduled_publish_date": "2025-01-31"
}

Respond with: {"articles": [...]}
PROMPT;
    }

    protected function normalizeArticleProposalData(array $data): array
    {
        $title = $data['title'] ?? null;
        if (empty($data['name']) && $title) {
            $data['name'] = Str::slug($title);
        }

        if (empty($data['body_type']) || !in_array($data['body_type'], ['pdf', 'template'], true)) {
            $data['body_type'] = 'template';
        }

        if (empty($data['scheduled_publish_date'])) {
            $data['scheduled_publish_date'] = now()->addDays(7)->toDateString();
        }

        if (isset($data['key_attributes']) && !is_array($data['key_attributes'])) {
            $data['key_attributes'] = array_filter(array_map('trim', explode(',', (string) $data['key_attributes'])));
        }

        if (!isset($data['sections']) || !is_array($data['sections']) || $data['sections'] === []) {
            $data['sections'] = [[
                'header' => 'Overview',
                'body' => $data['description'] ?? 'Article content pending.',
            ]];
        } else {
            $normalizedSections = [];
            foreach ($data['sections'] as $section) {
                if (!is_array($section)) {
                    continue;
                }
                $normalizedSections[] = [
                    'header' => $section['header'] ?? $section['title'] ?? '',
                    'body' => $section['body'] ?? '',
                ];
            }
            $data['sections'] = $normalizedSections;
        }

        return $data;
    }

    /**
     * Build prompt for question generation
     */
    protected function buildQuestionPrompt(AIUploadSession $session): string
    {
        $settings = $session->input_settings ?? [];
        $count = $session->getItemCount();
        $category = $session->getCategory() ?? 'General';
        $yearGroup = $session->getYearGroup() ?? 'Grade 8';
        $difficulty = $session->getDifficultyRange();
        $questionTypes = $settings['question_types'] ?? ['mcq'];
        
        $prompt = "Generate {$count} high-quality educational questions.\n\n";
        $prompt .= "Requirements:\n";
        $prompt .= "- Category: {$category}\n";
        $prompt .= "- Year Group: {$yearGroup}\n";
        $prompt .= "- Difficulty range: {$difficulty['min']} to {$difficulty['max']} (on 1-10 scale)\n";
        $prompt .= "- Question types: " . implode(', ', $questionTypes) . "\n";
        
        if (!empty($session->user_prompt)) {
            $prompt .= "\nAdditional instructions:\n{$session->user_prompt}\n";
        }
        
        if (!empty($session->source_data)) {
            $prompt .= "\nSource content to base questions on:\n" . json_encode($session->source_data) . "\n";
        }

        return $prompt;
    }

    /**
     * Build prompt for assessment generation
     */
    protected function buildAssessmentPrompt(AIUploadSession $session): string
    {
        $settings = $session->input_settings ?? [];
        $count = $session->getItemCount();
        $yearGroup = $session->getYearGroup() ?? 'Grade 8';
        $questionsPerAssessment = $settings['questions_per_assessment'] ?? 10;
        
        $prompt = "Generate {$count} educational assessment(s).\n\n";
        $prompt .= "Requirements:\n";
        $prompt .= "- Year Group: {$yearGroup}\n";
        $prompt .= "- Questions per assessment: {$questionsPerAssessment}\n";
        
        if (!empty($session->user_prompt)) {
            $prompt .= "\nAdditional instructions:\n{$session->user_prompt}\n";
        }

        return $prompt;
    }

    /**
     * Build prompt for course generation
     */
    protected function buildCoursePrompt(AIUploadSession $session): string
    {
        $settings = $session->input_settings ?? [];
        $yearGroup = $session->getYearGroup() ?? 'Grade 8';
        $category = $session->getCategory() ?? 'General';
        $modulesCount = $settings['modules_count'] ?? 5;
        $lessonsPerModule = $settings['lessons_per_module'] ?? 4;
        $slidesPerLesson = $settings['slides_per_lesson'] ?? 6;
        
        $prompt = "Generate a complete course structure.\n\n";
        $prompt .= "Requirements:\n";
        $prompt .= "- Year Group: {$yearGroup}\n";
        $prompt .= "- Category: {$category}\n";
        $prompt .= "- Number of modules: {$modulesCount}\n";
        $prompt .= "- Lessons per module: {$lessonsPerModule}\n";
        $prompt .= "- Slides per lesson: {$slidesPerLesson}\n";
        
        if (!empty($session->user_prompt)) {
            $prompt .= "\nCourse topic and requirements:\n{$session->user_prompt}\n";
        }

        return $prompt;
    }

    /**
     * Build prompt for module generation
     */
    protected function buildModulePrompt(AIUploadSession $session): string
    {
        $settings = $session->input_settings ?? [];
        $count = $session->getItemCount();
        $lessonsPerModule = $settings['lessons_per_module'] ?? 4;
        $slidesPerLesson = $settings['slides_per_lesson'] ?? 6;
        
        $prompt = "Generate {$count} module(s) with lessons and slides.\n\n";
        $prompt .= "Requirements:\n";
        $prompt .= "- Lessons per module: {$lessonsPerModule}\n";
        $prompt .= "- Slides per lesson: {$slidesPerLesson}\n";
        
        if (!empty($session->user_prompt)) {
            $prompt .= "\nModule topic and requirements:\n{$session->user_prompt}\n";
        }

        return $prompt;
    }

    /**
     * Build prompt for lesson generation
     */
    protected function buildLessonPrompt(AIUploadSession $session): string
    {
        $settings = $session->input_settings ?? [];
        $count = $session->getItemCount();
        $yearGroup = $session->getYearGroup() ?? 'Grade 8';
        $slidesPerLesson = $settings['slides_per_lesson'] ?? 6;
        
        $prompt = "Generate {$count} lesson(s) with slides.\n\n";
        $prompt .= "Requirements:\n";
        $prompt .= "- Year Group: {$yearGroup}\n";
        $prompt .= "- Slides per lesson: {$slidesPerLesson}\n";
        
        if (!empty($session->user_prompt)) {
            $prompt .= "\nLesson topic and requirements:\n{$session->user_prompt}\n";
        }

        return $prompt;
    }

    /**
     * Build prompt for slide generation
     */
    protected function buildSlidePrompt(AIUploadSession $session): string
    {
        $count = $session->getItemCount();
        
        $prompt = "Generate {$count} educational slide(s).\n\n";
        
        if (!empty($session->user_prompt)) {
            $prompt .= "Slide content requirements:\n{$session->user_prompt}\n";
        }

        return $prompt;
    }

    /**
     * Build prompt for article generation
     */
    protected function buildArticlePrompt(AIUploadSession $session): string
    {
        $settings = $session->input_settings ?? [];
        $count = $session->getItemCount();
        $category = $settings['article_category'] ?? ($session->getCategory() ?? 'General');
        $titleStyle = $settings['title_style'] ?? null;
        $audienceLevel = $settings['audience_level'] ?? null;
        $tone = $settings['tone'] ?? null;
        $sectionCount = $settings['section_count'] ?? null;
        
        $prompt = "Generate {$count} educational article(s).\n\n";
        $prompt .= "Requirements:\n";
        $prompt .= "- Category: {$category}\n";
        if ($titleStyle) {
            $prompt .= "- Title style: {$titleStyle}\n";
        }
        if ($audienceLevel) {
            $prompt .= "- Audience level: {$audienceLevel}\n";
        }
        if ($tone) {
            $prompt .= "- Tone: {$tone}\n";
        }
        if ($sectionCount) {
            $prompt .= "- Section count: {$sectionCount}\n";
        }
        
        if (!empty($session->user_prompt)) {
            $prompt .= "\nArticle topic and requirements:\n{$session->user_prompt}\n";
        }

        return $prompt;
    }

    /**
     * Refine a proposal based on feedback
     */
    public function refineProposal(AIUploadProposal $proposal, string $feedback): AIUploadProposal
    {
        $session = $proposal->session;
        
        $prompt = "Improve this {$proposal->content_type} based on the feedback.\n\n";
        $prompt .= "Current content:\n" . json_encode($proposal->proposed_data, JSON_PRETTY_PRINT) . "\n\n";
        $prompt .= "Feedback/Improvements needed:\n{$feedback}\n\n";
        $prompt .= "Return the improved content in the same JSON structure.";

        $response = $this->callOpenAI($session, $prompt, 'refine_proposal');
        $refinedData = $this->parseJsonResponse($response);
        
        // Update proposal with refined data
        $proposal->update([
            'proposed_data' => $refinedData[0] ?? $refinedData,
            'status' => AIUploadProposal::STATUS_MODIFIED,
        ]);

        $proposal->validate();

        return $proposal->fresh();
    }
}
