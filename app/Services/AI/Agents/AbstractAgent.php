<?php

namespace App\Services\AI\Agents;

use App\Models\Child;
use App\Models\ChatSession;
use App\Models\AIAgentSession;
use App\Services\AI\Memory\MemoryManager;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

abstract class AbstractAgent
{
    protected string $agentType;
    protected array $tools = [];
    protected MemoryManager $memoryManager;
    // Increased from 1000 to 2000 to prevent GPT-5 from returning empty responses when hitting token limit
    protected int $maxTokens = 2000;
    protected float $temperature = 0.7;
    protected string $model = 'gpt-5-nano';

    public function __construct()
    {
        $this->memoryManager = app(MemoryManager::class);
    }

    /**
     * Main processing method that all agents must implement
     */
    abstract public function process(Child $child, array $context = []): array;

    /**
     * Generate the system prompt for this agent
     */
    abstract protected function getSystemPrompt(): string;

    /**
     * Get the agent type identifier
     */
    public function getAgentType(): string
    {
        return $this->agentType;
    }

    /**
     * Detect if message is a simple acknowledgment or follow-up
     */
    protected function isAcknowledgmentOrSimpleFollowup(string $message): bool
    {
        $message = strtolower(trim($message));
        
        // Simple acknowledgments
        $acknowledgments = ['ok', 'okay', 'thanks', 'thank you', 'thankyou', 'got it', 'understood', 'cool', 'alright', 'i see'];
        if (in_array($message, $acknowledgments)) {
            return true;
        }
        
        // Short variations
        if (strlen($message) < 15 && preg_match('/^(ok|thanks|thank you|got it|i\'ll try)/i', $message)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Detect conversation state
     */
    protected function getConversationState(AIAgentSession $session, string $userMessage): string
    {
        $sessionData = $session->session_data ?? [];
        $messageCount = count($sessionData['messages'] ?? []);
        
        // First message in session
        if ($messageCount === 0) {
            return 'initial';
        }
        
        // Acknowledgment
        if ($this->isAcknowledgmentOrSimpleFollowup($userMessage)) {
            return 'acknowledgment';
        }
        
        // Follow-up question
        if ($messageCount > 0) {
            return 'followup';
        }
        
        return 'initial';
    }

    /**
     * Generate AI response using OpenAI - WITH CONVERSATION HISTORY
     */
    protected function generateAIResponse(Child $child, string $userMessage, array $context = []): string
    {
        try {
            // Create or get session for this interaction
            $session = $this->getOrCreateSession($child);
            
            // Update session activity
            $session->update([
                'last_interaction' => now(),
            ]);
            
            // Refresh session to ensure update is reflected
            $session->refresh();

            // Detect conversation state
            $conversationState = $this->getConversationState($session, $userMessage);
            
            // For acknowledgments, use minimal context
            if ($conversationState === 'acknowledgment') {
                $compressedContext = [
                    'current_focus' => ['User acknowledged previous response. Provide a brief, friendly acknowledgment back.']
                ];
            }
            // For follow-ups, use lightweight context
            elseif ($conversationState === 'followup' && isset($context['_lightweight_context'])) {
                $compressedContext = $context['_lightweight_context'];
            }
            // For initial requests, use full context
            else {
                $compressedContext = $context;
                
                // Check if context is empty (check both legacy and new formats)
                $hasLegacyContext = !empty($context['recent_performance']) || 
                                    !empty($context['learning_patterns']) || 
                                    !empty($context['current_focus']);
                
                $hasNewContext = isset($context['fetched_data']);
                
                // Only fetch from MemoryManager if NO context provided at all
                if (!$hasLegacyContext && !$hasNewContext) {
                    $compressedContext = $this->memoryManager->getRelevantContext(
                        $child, 
                        $this->agentType, 
                        $context
                    );
                }
            }

            // Build messages array with conversation history
            $messages = [
                ['role' => 'system', 'content' => $this->getSystemPrompt()],
                ['role' => 'system', 'content' => $this->formatContext($compressedContext, $child)],
            ];
            
            // Add conversation history from session (limit is agent-specific)
            $conversationHistory = $this->getConversationHistory($session, $this->getConversationHistoryLimit());
            if (!empty($conversationHistory)) {
                $messages = array_merge($messages, $conversationHistory);
            }
            
            // Add current user message
            $messages[] = ['role' => 'user', 'content' => $userMessage];

            // LOG: Stage 3 - Sending to OpenAI
            Log::info('[AI AGENT] Stage 3: Sending to OpenAI', [
                'child_id' => $child->id,
                'agent_type' => $this->agentType,
                'conversation_state' => $conversationState,
                'system_prompt' => $this->getSystemPrompt(),
                'formatted_context' => $this->formatContext($compressedContext, $child),
                'conversation_history_count' => count($conversationHistory),
                'conversation_history' => $conversationHistory,
                'current_user_message' => $userMessage,
                'full_messages_array' => $messages,
                'total_messages' => count($messages),
                'model' => $this->model,
                'max_completion_tokens' => $this->maxTokens,
                'timestamp' => now()->toISOString()
            ]);

            // Call OpenAI API
            $payload = [
                'model' => $this->model,
                'messages' => $messages,
                'max_completion_tokens' => $this->maxTokens,
                'user' => (string) $child->id,
            ];

            if ($this->supportsTemperature($this->model)) {
                $payload['temperature'] = $this->temperature;
            }

            $response = OpenAI::chat()->create($payload);

            $responseData = $this->normalizeResponse($response);
            
            // LOG: Raw Response Structure (for debugging GPT-5 response format)
            Log::debug('[AI AGENT] Raw OpenAI Response Structure', [
                'child_id' => $child->id,
                'agent_type' => $this->agentType,
                'raw_response_keys' => array_keys($responseData),
                'choices_structure' => isset($responseData['choices'][0]) ? array_keys($responseData['choices'][0]) : 'no choices',
                'message_structure' => isset($responseData['choices'][0]['message']) ? array_keys($responseData['choices'][0]['message']) : 'no message',
                'message_content_type' => isset($responseData['choices'][0]['message']['content']) ? gettype($responseData['choices'][0]['message']['content']) : 'no content field',
                'message_content_preview' => isset($responseData['choices'][0]['message']['content']) 
                    ? (is_string($responseData['choices'][0]['message']['content']) 
                        ? substr($responseData['choices'][0]['message']['content'], 0, 500) 
                        : json_encode($responseData['choices'][0]['message']['content']))
                    : 'N/A',
                'full_choices_0' => isset($responseData['choices'][0]) ? json_encode($responseData['choices'][0], JSON_PARTIAL_OUTPUT_ON_ERROR) : 'N/A',
                'has_output_text' => isset($responseData['output_text']),
                'has_output' => isset($responseData['output']),
            ]);
            
            $aiResponse = $this->extractResponseContent($responseData);
            
            // LOG: Stage 4 - OpenAI Response Received
            Log::info('[AI AGENT] Stage 4: OpenAI Response Received', [
                'child_id' => $child->id,
                'agent_type' => $this->agentType,
                'ai_response' => $aiResponse,
                'response_length' => strlen($aiResponse),
                'tokens_used' => [
                    'prompt_tokens' => $responseData['usage']['prompt_tokens'] ?? null,
                    'completion_tokens' => $responseData['usage']['completion_tokens'] ?? null,
                    'total_tokens' => $responseData['usage']['total_tokens'] ?? null
                ],
                'model_used' => $responseData['model'] ?? $this->model,
                'finish_reason' => $responseData['choices'][0]['finish_reason'] ?? null,
                'timestamp' => now()->toISOString()
            ]);

            if ($aiResponse === '') {
                Log::warning('[AI AGENT] Empty response content', [
                    'child_id' => $child->id,
                    'agent_type' => $this->agentType,
                    'finish_reason' => $responseData['choices'][0]['finish_reason'] ?? null
                ]);
                $aiResponse = "I'm sorry—my response was cut off. Please try again.";
            }

            // Store both user message and AI response in session
            $session->addMessage([
                'role' => 'user',
                'content' => $userMessage,
                'timestamp' => now()->toISOString()
            ]);
            
            if ($aiResponse !== '') {
                $session->addMessage([
                    'role' => 'assistant',
                    'content' => $aiResponse,
                    'timestamp' => now()->toISOString()
                ]);
            }
            
            // DEBUG: Verify messages were saved
            $session->refresh();
            Log::debug('[SESSION DEBUG] Messages Saved to Session', [
                'session_id' => $session->id,
                'child_id' => $child->id,
                'total_messages_now' => count($session->session_data['messages'] ?? []),
                'last_2_messages' => array_slice($session->session_data['messages'] ?? [], -2),
                'full_session_data' => $session->session_data
            ]);

            // Store this interaction in memory for future context
            $this->memoryManager->storeInteraction(
                $child,
                $this->agentType,
                $userMessage,
                $aiResponse,
                $context
            );

            return $aiResponse;

        } catch (\Exception $e) {
            Log::error("AI Agent Error", [
                'agent_type' => $this->agentType,
                'child_id' => $child->id,
                'error' => $e->getMessage()
            ]);

            return "I'm having trouble processing your request right now. Please try again in a moment, or contact support if the issue continues.";
        }
    }
    
    /**
     * Get conversation history from session
     * Returns messages in OpenAI format: [{'role': 'user'|'assistant', 'content': '...'}]
     */
    protected function getConversationHistory(AIAgentSession $session, int $limit = 10): array
    {
        $sessionData = $session->session_data ?? [];
        $allMessages = array_values(array_filter($sessionData['messages'] ?? [], function ($message) {
            return isset($message['content']) && $message['content'] !== '';
        }));
        
        if (empty($allMessages)) {
            return [];
        }
        
        // Get recent messages (limit * 2 because we have user + assistant pairs)
        $recentMessages = array_slice($allMessages, -($limit * 2));
        
        // Convert to OpenAI format (remove timestamp field)
        $formattedMessages = [];
        foreach ($recentMessages as $msg) {
            $formattedMessages[] = [
                'role' => $msg['role'],
                'content' => $msg['content']
            ];
        }
        
        return $formattedMessages;
    }

    /**
     * Allow agents to shrink conversation history to control prompt size.
     */
    protected function getConversationHistoryLimit(): int
    {
        return 10;
    }

    /**
     * Extract response content from different OpenAI response shapes.
     */
    protected function extractResponseContent(array $response): string
    {
        $content = $response['choices'][0]['message']['content'] ?? '';

        if (is_string($content)) {
            $trimmed = trim($content);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                $text = $this->extractTextFragment($part);
                if ($text !== null) {
                    $parts[] = $text;
                }
            }
            $joined = trim(implode('', $parts));
            if ($joined !== '') {
                return $joined;
            }
        }

        if (isset($response['choices'][0]['message'])) {
            $messageText = $this->extractTextFragment($response['choices'][0]['message']);
            if ($messageText !== null && trim($messageText) !== '') {
                return trim($messageText);
            }
        }

        if (isset($response['choices'][0]['message']['output_text'])) {
            $outputText = $response['choices'][0]['message']['output_text'];
            if (is_string($outputText)) {
                return trim($outputText);
            }
            if (is_array($outputText)) {
                $parts = [];
                foreach ($outputText as $part) {
                    $text = $this->extractTextFragment($part);
                    if ($text !== null) {
                        $parts[] = $text;
                    }
                }
                $joined = trim(implode('', $parts));
                if ($joined !== '') {
                    return $joined;
                }
            }
        }

        if (isset($response['choices'][0]['text'])) {
            return trim((string) $response['choices'][0]['text']);
        }

        if (isset($response['output_text'])) {
            $outputText = $response['output_text'];
            if (is_string($outputText)) {
                return trim($outputText);
            }
            if (is_array($outputText)) {
                $parts = [];
                foreach ($outputText as $part) {
                    $text = $this->extractTextFragment($part);
                    if ($text !== null) {
                        $parts[] = $text;
                    }
                }
                $joined = trim(implode('', $parts));
                if ($joined !== '') {
                    return $joined;
                }
            }
        }

        $fallback = $this->findResponseText($response);
        return $fallback !== null ? trim($fallback) : '';
    }

    /**
     * Attempt to locate response text in varied response shapes (gpt-5 responses).
     */
    protected function findResponseText(array $response, int $depth = 0): ?string
    {
        if ($depth > 5) {
            return null;
        }

        if (isset($response['text']) && is_string($response['text'])) {
            return $response['text'];
        }

        if (isset($response['text']['value']) && is_string($response['text']['value'])) {
            return $response['text']['value'];
        }

        if (isset($response['text']['content']) && is_string($response['text']['content'])) {
            return $response['text']['content'];
        }

        if (isset($response['text']['text']) && is_string($response['text']['text'])) {
            return $response['text']['text'];
        }

        if (isset($response['output_text']) && is_string($response['output_text'])) {
            return $response['output_text'];
        }

        if (isset($response['content'])) {
            $content = $response['content'];
            if (is_string($content)) {
                return $content;
            }
            if (is_array($content)) {
                foreach ($content as $part) {
                    if (is_string($part)) {
                        return $part;
                    }
                    if (is_array($part)) {
                        $text = $this->findResponseText($part, $depth + 1);
                        if ($text !== null) {
                            return $text;
                        }
                    }
                }
            }
        }

        $traverseKeys = ['output', 'choices', 'message'];
        foreach ($traverseKeys as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                foreach ($response[$key] as $item) {
                    if (is_array($item)) {
                        $text = $this->findResponseText($item, $depth + 1);
                        if ($text !== null) {
                            return $text;
                        }
                    }
                }
            } elseif (isset($response[$key]) && is_array($response[$key])) {
                $text = $this->findResponseText($response[$key], $depth + 1);
                if ($text !== null) {
                    return $text;
                }
            }
        }

        return null;
    }

    /**
     * Extract a text fragment from a mixed response part.
     */
    protected function extractTextFragment($part): ?string
    {
        if (is_string($part)) {
            return $part;
        }

        if (!is_array($part)) {
            return null;
        }

        if (isset($part['text'])) {
            if (is_string($part['text'])) {
                return $part['text'];
            }
            if (is_array($part['text'])) {
                if (isset($part['text']['value']) && is_string($part['text']['value'])) {
                    return $part['text']['value'];
                }
                if (isset($part['text']['content']) && is_string($part['text']['content'])) {
                    return $part['text']['content'];
                }
                if (isset($part['text']['text']) && is_string($part['text']['text'])) {
                    return $part['text']['text'];
                }
            }
        }

        if (isset($part['output_text'])) {
            if (is_string($part['output_text'])) {
                return $part['output_text'];
            }
            if (is_array($part['output_text'])) {
                $text = $this->findResponseText($part['output_text']);
                if ($text !== null) {
                    return $text;
                }
            }
        }

        if (isset($part['content'])) {
            if (is_string($part['content'])) {
                return $part['content'];
            }
            if (is_array($part['content'])) {
                $text = $this->findResponseText($part['content']);
                if ($text !== null) {
                    return $text;
                }
            }
        }

        return $this->findResponseText($part);
    }

    /**
     * Normalize OpenAI response to array form.
     */
    protected function normalizeResponse($response): array
    {
        if (is_array($response)) {
            return $response;
        }

        if (is_object($response) && method_exists($response, 'toArray')) {
            return $response->toArray();
        }

        if ($response instanceof \ArrayAccess) {
            return [
                'choices' => $response['choices'] ?? null,
                'usage' => $response['usage'] ?? null,
                'model' => $response['model'] ?? null,
            ];
        }

        return [];
    }

    /**
     * Determine whether the model accepts temperature overrides.
     */
    protected function supportsTemperature(string $model): bool
    {
        return !str_starts_with($model, 'gpt-5');
    }

    /**
     * Format context for the AI prompt - PRESERVES FORMATTING
     * Supports both legacy and new two-stage context formats
     */
    protected function formatContext(array $context, Child $child): string
    {
        $contextLines = [
            "Student: {$child->child_name} (ID: {$child->id})",
            "Current Context for {$this->agentType} agent:",
            ""
        ];

        // NEW FORMAT: Two-stage context with fetched_data
        if (isset($context['fetched_data'])) {
            if (!empty($context['reasoning'])) {
                $contextLines[] = "Data Fetched Because: {$context['reasoning']}";
                $contextLines[] = "";
            }
            
            if (!empty($context['fetched_data'])) {
                foreach ($context['fetched_data'] as $source => $data) {
                    $contextLines[] = "=== " . strtoupper(str_replace('_', ' ', $source)) . " ===";
                    
                    if (is_array($data) && !empty($data)) {
                        $contextLines[] = json_encode($data, JSON_PRETTY_PRINT);
                    } else {
                        $contextLines[] = "No data available";
                    }
                    
                    $contextLines[] = "";
                }
            } else {
                $contextLines[] = "No database context needed for this question.";
            }
            
            return implode("\n", $contextLines);
        }

        // LEGACY FORMAT: Direct context fields
        if (!empty($context['recent_performance'])) {
            $contextLines[] = "Recent Performance:";
            foreach ($context['recent_performance'] as $performance) {
                if (str_contains($performance, "\n") || str_contains($performance, "━━━━━") || str_contains($performance, "═══════")) {
                    $contextLines[] = $performance;
                } else {
                    $contextLines[] = "- {$performance}";
                }
            }
        }

        if (!empty($context['learning_patterns'])) {
            $contextLines[] = "";
            $contextLines[] = "Learning Patterns:";
            foreach ($context['learning_patterns'] as $pattern) {
                if (str_contains($pattern, "\n")) {
                    $contextLines[] = $pattern;
                } else {
                    $contextLines[] = "- {$pattern}";
                }
            }
        }

        if (!empty($context['current_focus'])) {
            $contextLines[] = "";
            $contextLines[] = "Current Focus Areas:";
            foreach ($context['current_focus'] as $focus) {
                if (str_contains($focus, "\n")) {
                    $contextLines[] = $focus;
                } else {
                    $contextLines[] = "- {$focus}";
                }
            }
        }

        return implode("\n", $contextLines);
    }

    /**
     * Create or get session for this agent and child
     */
    protected function getOrCreateSession(Child $child): AIAgentSession
    {
        // Use the new AIAgentSession model for proper tracking
        $session = AIAgentSession::firstOrCreate([
            'child_id' => $child->id,
            'agent_type' => $this->agentType,
        ], [
            'is_active' => true,
            'session_metadata' => [],
            'last_interaction' => now(),
        ]);
        
        Log::debug('[SESSION DEBUG] Session Retrieved/Created', [
            'session_id' => $session->id,
            'child_id' => $child->id,
            'agent_type' => $this->agentType,
            'was_recently_created' => $session->wasRecentlyCreated,
            'current_messages_count' => count($session->session_data['messages'] ?? []),
            'session_data' => $session->session_data
        ]);
        
        return $session;
    }

    /**
     * Validate input before processing
     */
    protected function validateInput(array $context): bool
    {
        // Basic validation - can be overridden by specific agents
        return true;
    }

    /**
     * Get available tools for this agent
     */
    public function getAvailableTools(): array
    {
        return $this->tools;
    }

    /**
     * Execute a tool if available
     */
    protected function executeTool(string $toolName, array $parameters = []): array
    {
        if (!in_array($toolName, $this->tools)) {
            throw new \InvalidArgumentException("Tool {$toolName} is not available for this agent.");
        }

        // Tool execution logic will be implemented in Phase 2
        return ['success' => false, 'message' => 'Tool execution not yet implemented'];
    }

    /**
     * Log agent activity
     */
    protected function logActivity(Child $child, string $action, array $data = []): void
    {
        Log::info("Agent Activity", [
            'agent_type' => $this->agentType,
            'child_id' => $child->id,
            'action' => $action,
            'data' => $data,
            'timestamp' => now()->toISOString()
        ]);
    }
}
