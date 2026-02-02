<?php

namespace App\Services\AI;

use App\Models\Child;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

/**
 * DataRequirementsAnalyzer - Stage 1 of Two-Stage AI System
 * Analyzes user questions to determine what database information is needed
 */
class DataRequirementsAnalyzer
{
    /**
     * Analyze user message and determine data requirements
     */
    public function analyze(string $userMessage, Child $child, array $conversationHistory = []): array
    {
        try {
            $systemPrompt = $this->getAnalysisSystemPrompt();
            
            // Build messages array with conversation history
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
            ];
            
            // Add recent conversation history (last 5 exchanges for context)
            $recentHistory = array_slice($conversationHistory, -10); // Last 5 user+assistant pairs
            foreach ($recentHistory as $msg) {
                $messages[] = $msg;
            }
            
            // Add current analysis request
            $messages[] = ['role' => 'user', 'content' => $this->buildAnalysisPrompt($userMessage, $child, $conversationHistory)];
            
            $response = OpenAI::chat()->create([
                'model' => 'gpt-5-nano',
                'messages' => $messages,
                'functions' => [$this->getDataRequirementsFunctionDefinition()],
                'function_call' => ['name' => 'specify_data_requirements'],
            ]);
            
            return $this->parseDataRequirements($response);
            
        } catch (\Exception $e) {
            Log::error('DataRequirementsAnalyzer error', [
                'child_id' => $child->id,
                'message' => $userMessage,
                'error' => $e->getMessage()
            ]);
            
            // Fallback: return minimal requirements
            return [
                'required_data' => ['none'],
                'filters' => [],
                'reasoning' => 'Error in analysis, proceeding without database context'
            ];
        }
    }
    
    /**
     * Get system prompt for analysis
     */
    protected function getAnalysisSystemPrompt(): string
    {
        return "You are a data requirements analyzer for an educational AI tutor.

Your task: Analyze the student's question and determine what database information is needed.

Available data sources:
- submission_history: Past assessment scores and attempts
- submission_details: Individual question answers (right/wrong)
- lesson_schedule: Upcoming and past lessons
- assessment_catalog: Available assessments
- access_records: What the student has paid access to
- performance_trends: Performance metrics over time
- question_bank: Question database with categories and difficulty levels
- none: No database data needed (general knowledge)

IMPORTANT RULES:
1. ONLY request data that is DIRECTLY relevant to answering the question
2. Use category/subject filters when the question mentions specific subjects
3. If it's a general knowledge question (e.g., 'What is photosynthesis?'), return 'none'
4. Be specific with filters to minimize data fetching
5. Consider the time context (recent vs historical)

Category/Subject Examples:
- Mathematics → category: 'Mathematics' or 'Math'
- English → category: 'English'
- Science → category: 'Science'
- Verbal Reasoning → category: 'Verbal Reasoning'
- Non-Verbal Reasoning → category: 'Non-Verbal Reasoning'

Question Examples with Category Filtering:

1. 'How am I doing in math?'
   → submission_history, performance_trends
   → filters: { category: 'Mathematics', time_range: 'last_30_days' }

2. 'Show me my wrong English questions'
   → submission_details
   → filters: { category: 'English', is_correct: false, limit: 20 }

3. 'What science lessons do I have coming up?'
   → lesson_schedule
   → filters: { category: 'Science', time_range: 'upcoming_30_days' }

4. 'What are my hardest math questions?'
   → submission_details, question_bank
   → filters: { category: 'Mathematics', difficulty_level: 8, is_correct: false }

5. 'Explain photosynthesis to me'
   → none (general knowledge - no database needed)

6. 'What homework is due this week?'
   → lesson_schedule, assessment_catalog
   → filters: { time_range: 'upcoming_7_days' }

7. 'Show me algebra questions I got wrong'
   → submission_details, question_bank
   → filters: { category: 'Mathematics', subcategory: 'Algebra', is_correct: false }

8. 'What Year 6 assessments are available?'
   → assessment_catalog
   → filters: { grade: 'Year 6', status: 'active' }

9. 'Help me with my homework'
   → lesson_schedule, assessment_catalog
   → filters: { time_range: 'upcoming_7_days' }

10. 'Why did I get question 5 wrong on the last test?'
    → submission_details
    → filters: { limit: 1, time_range: 'last_7_days' }
";
    }
    
    /**
     * Build analysis prompt with child context
     */
    protected function buildAnalysisPrompt(string $userMessage, Child $child, array $conversationHistory = []): string
    {
        $prompt = "Student Question: \"{$userMessage}\"\n\n" .
                  "Student Context:\n" .
                  "- Name: {$child->child_name}\n" .
                  "- Child ID: {$child->id}\n\n";
        
        if (!empty($conversationHistory)) {
            $prompt .= "IMPORTANT: Review the conversation history above before deciding data requirements.\n\n";
            $prompt .= "If the user is asking a follow-up question about something already discussed:\n";
            $prompt .= "- Check if the answer is in recent conversation history\n";
            $prompt .= "- If YES: Return 'none' with reasoning 'Answer available in conversation history'\n";
            $prompt .= "- If NO: Specify what new data is needed and explain why\n\n";
        }
        
        $prompt .= "Determine what database information is needed to answer this question.";
        
        return $prompt;
    }
    
    /**
     * Get OpenAI function definition for structured output
     */
    protected function getDataRequirementsFunctionDefinition(): array
    {
        return [
            'name' => 'specify_data_requirements',
            'description' => 'Specify what database information is needed to answer the student question',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'required_data' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string',
                            'enum' => [
                                'submission_history',
                                'submission_details', 
                                'lesson_schedule',
                                'assessment_catalog',
                                'access_records',
                                'performance_trends',
                                'question_bank',
                                'none'
                            ]
                        ],
                        'description' => 'List of required data sources'
                    ],
                    'filters' => [
                        'type' => 'object',
                    'properties' => [
                        'time_range' => [
                            'type' => 'string',
                            'enum' => ['last_7_days', 'last_30_days', 'last_90_days', 'upcoming_7_days', 'upcoming_30_days', 'all'],
                            'description' => 'Time period to filter data. Use "last_X_days" for historical data, "upcoming_X_days" for future lessons/assessments, or "all" for no time restriction.'
                        ],
                        'next_only' => [
                            'type' => 'boolean',
                            'description' => 'Set to true when user asks for "next" or "upcoming" single lesson/assessment. This will return only the very next item regardless of time range. Use this for queries like "when is my next lesson?" or "what is my next assessment?"'
                        ],
                            'category' => [
                                'type' => 'string',
                                'description' => 'Subject/category name (e.g., Mathematics, English, Science, Verbal Reasoning)'
                            ],
                            'subcategory' => [
                                'type' => 'string',
                                'description' => 'Subcategory within a subject (e.g., Algebra, Grammar)'
                            ],
                            'grade' => [
                                'type' => 'string',
                                'description' => 'Grade level filter (e.g., Year 5, Year 6)'
                            ],
                            'question_type' => [
                                'type' => 'string',
                                'description' => 'Type of question (e.g., multiple_choice, short_answer, essay)'
                            ],
                            'difficulty_level' => [
                                'type' => 'integer',
                                'minimum' => 1,
                                'maximum' => 10,
                                'description' => 'Difficulty level of questions'
                            ],
                            'status' => [
                                'type' => 'string',
                                'enum' => ['graded', 'pending', 'scheduled', 'completed', 'active', 'draft'],
                                'description' => 'Status filter'
                            ],
                            'is_correct' => [
                                'type' => 'boolean',
                                'description' => 'Filter by correct/incorrect answers'
                            ],
                            'assessment_id' => [
                                'type' => 'integer',
                                'description' => 'Specific assessment ID'
                            ],
                            'submission_id' => [
                                'type' => 'integer',
                                'description' => 'Specific submission ID'
                            ],
                            'question_id' => [
                                'type' => 'integer',
                                'description' => 'Specific question ID'
                            ],
                            'limit' => [
                                'type' => 'integer',
                                'description' => 'Max number of records',
                                'default' => 10,
                                'minimum' => 1,
                                'maximum' => 50
                            ]
                        ]
                    ],
                    'reasoning' => [
                        'type' => 'string',
                        'description' => 'Brief explanation of why this data is needed'
                    ]
                ],
                'required' => ['required_data', 'reasoning']
            ]
        ];
    }
    
    /**
     * Parse OpenAI response to extract data requirements
     */
    protected function parseDataRequirements($response): array
    {
        try {
            $functionCall = $response['choices'][0]['message']['function_call'] ?? null;
            
            if (!$functionCall || !isset($functionCall['arguments'])) {
                throw new \Exception('No function call in response');
            }
            
            $arguments = json_decode($functionCall['arguments'], true);
            
            if (!$arguments) {
                throw new \Exception('Failed to parse function arguments');
            }
            
            // Ensure required fields exist
            $requirements = [
                'required_data' => $arguments['required_data'] ?? ['none'],
                'filters' => $arguments['filters'] ?? [],
                'reasoning' => $arguments['reasoning'] ?? 'Data requirements determined'
            ];
            
            // Validate and clean filters
            $requirements['filters'] = $this->validateFilters($requirements['filters']);
            
            return $requirements;
            
        } catch (\Exception $e) {
            Log::warning('Failed to parse data requirements', [
                'error' => $e->getMessage(),
                'response' => $response
            ]);
            
            // Fallback
            return [
                'required_data' => ['none'],
                'filters' => [],
                'reasoning' => 'Failed to parse requirements, proceeding without database context'
            ];
        }
    }
    
    /**
     * Validate and clean filters
     */
    protected function validateFilters(array $filters): array
    {
        $validFilters = [];
        
        // Validate time_range
        if (isset($filters['time_range']) && in_array($filters['time_range'], [
            'last_7_days', 'last_30_days', 'last_90_days', 'upcoming_7_days', 'upcoming_30_days', 'all'
        ])) {
            $validFilters['time_range'] = $filters['time_range'];
        }
        
        // Validate category (string)
        if (isset($filters['category']) && is_string($filters['category']) && !empty($filters['category'])) {
            $validFilters['category'] = $filters['category'];
        }
        
        // Validate subcategory (string)
        if (isset($filters['subcategory']) && is_string($filters['subcategory']) && !empty($filters['subcategory'])) {
            $validFilters['subcategory'] = $filters['subcategory'];
        }
        
        // Validate grade (string)
        if (isset($filters['grade']) && is_string($filters['grade']) && !empty($filters['grade'])) {
            $validFilters['grade'] = $filters['grade'];
        }
        
        // Validate question_type (string)
        if (isset($filters['question_type']) && is_string($filters['question_type'])) {
            $validFilters['question_type'] = $filters['question_type'];
        }
        
        // Validate difficulty_level (integer 1-10)
        if (isset($filters['difficulty_level']) && is_numeric($filters['difficulty_level'])) {
            $level = (int) $filters['difficulty_level'];
            if ($level >= 1 && $level <= 10) {
                $validFilters['difficulty_level'] = $level;
            }
        }
        
        // Validate status
        if (isset($filters['status']) && in_array($filters['status'], [
            'graded', 'pending', 'scheduled', 'completed', 'active', 'draft'
        ])) {
            $validFilters['status'] = $filters['status'];
        }
        
        // Validate is_correct (boolean)
        if (isset($filters['is_correct']) && is_bool($filters['is_correct'])) {
            $validFilters['is_correct'] = $filters['is_correct'];
        }
        
        // Validate IDs (integers)
        foreach (['assessment_id', 'submission_id', 'question_id'] as $idField) {
            if (isset($filters[$idField]) && is_numeric($filters[$idField])) {
                $validFilters[$idField] = (int) $filters[$idField];
            }
        }
        
        // Validate limit (integer 1-50)
        if (isset($filters['limit']) && is_numeric($filters['limit'])) {
            $limit = (int) $filters['limit'];
            $validFilters['limit'] = max(1, min(50, $limit));
        }
        
        // Validate next_only (boolean)
        if (isset($filters['next_only']) && is_bool($filters['next_only'])) {
            $validFilters['next_only'] = $filters['next_only'];
        }
        
        return $validFilters;
    }
}
