# AI System Comprehensive Logging Documentation

## Overview

The AI Tutor system now includes detailed logging at every stage of the Two-Stage AI architecture, providing complete visibility from user request to final response.

## Log Structure

All logs use a structured format with:
- **Consistent prefixes**: `[AI TUTOR]` or `[AI AGENT]`
- **Stage indicators**: Stage 1, 2, 3, 4
- **Visual separators**: `═══════════════════════════════════════════════════`
- **ISO timestamps**: For precise timing
- **Child identification**: child_id and child_name

## Complete Request Flow

### 1. Request Initiated
**Location**: `TutorAgent.php` - Start of `process()` method

```php
Log::info('[AI TUTOR] Request Initiated', [
    'child_id' => $child->id,
    'child_name' => $child->child_name,
    'user_message' => $message,              // FULL MESSAGE
    'message_length' => strlen($message),
    'session_id' => $session->id,
    'timestamp' => now()->toISOString()
]);
```

**What it captures:**
- ✅ Original user message/prompt
- ✅ Child identification
- ✅ Session ID
- ✅ Request start timestamp

---

### 2. Stage 1: Data Requirements Analysis
**Location**: `TutorAgent.php` - After `DataRequirementsAnalyzer`

```php
Log::info('[AI TUTOR] Stage 1: Data Requirements Analysis', [
    'child_id' => $child->id,
    'user_message' => $message,              // REPEATED FOR CLARITY
    'required_data' => $requirements['required_data'],  // DATA SOURCES
    'filters' => $requirements['filters'],   // CATEGORY, TIME, ETC.
    'reasoning' => $requirements['reasoning'], // WHY THIS DATA
    'analysis_timestamp' => now()->toISOString()
]);
```

**What it captures:**
- ✅ What data sources were requested (e.g., `["submission_history", "performance_trends"]`)
- ✅ What filters were applied (e.g., `{"category": "Mathematics", "time_range": "last_30_days"}`)
- ✅ OpenAI's reasoning for data selection
- ✅ Stage 1 completion timestamp

**Example Output:**
```json
{
  "required_data": ["submission_history", "performance_trends"],
  "filters": {
    "category": "Mathematics",
    "time_range": "last_30_days",
    "limit": 10
  },
  "reasoning": "Student asked about math performance, need recent math submissions and trend analysis"
}
```

---

### 3. Stage 2: Context Data Fetched
**Location**: `TutorAgent.php` - After `ContextDataFetcher`

```php
Log::info('[AI TUTOR] Stage 2: Context Data Fetched', [
    'child_id' => $child->id,
    'data_sources' => array_keys($specificContext),
    'fetched_data' => $specificContext,      // COMPLETE ACTUAL DATA
    'total_size' => strlen(json_encode($specificContext)),
    'fetch_timestamp' => now()->toISOString()
]);
```

**What it captures:**
- ✅ List of data sources fetched
- ✅ **COMPLETE fetched data** (all records from database)
- ✅ Total size of context in bytes
- ✅ Stage 2 completion timestamp

**Example Output:**
```json
{
  "data_sources": ["submission_history", "performance_trends"],
  "fetched_data": {
    "submission_history": [
      {
        "assessment_title": "Basic Algebra Test",
        "category": "Mathematics",
        "score": "18/20",
        "percentage": "90%",
        "date": "2 days ago"
      },
      {
        "assessment_title": "Geometry Quiz",
        "category": "Mathematics",
        "score": "14/20",
        "percentage": "70%",
        "date": "5 days ago"
      }
    ],
    "performance_trends": {
      "average_score": "82%",
      "total_assessments": 8,
      "overall_trend": "improving",
      "category_breakdown": {
        "Mathematics": {
          "average": "80%",
          "assessments": 8,
          "trend": "improving"
        }
      }
    }
  },
  "total_size": 1523
}
```

---

### 4. Stage 3: Sending to OpenAI
**Location**: `AbstractAgent.php` - Before OpenAI API call in `generateAIResponse()`

```php
Log::info('[AI AGENT] Stage 3: Sending to OpenAI', [
    'child_id' => $child->id,
    'agent_type' => $this->agentType,
    'conversation_state' => $conversationState,
    'system_prompt' => $this->getSystemPrompt(),           // FULL SYSTEM PROMPT
    'formatted_context' => $this->formatContext(...),      // FORMATTED CONTEXT
    'conversation_history_count' => count($conversationHistory),
    'conversation_history' => $conversationHistory,        // PREVIOUS MESSAGES
    'current_user_message' => $userMessage,                // CURRENT MESSAGE
    'full_messages_array' => $messages,                    // COMPLETE ARRAY SENT TO OPENAI
    'total_messages' => count($messages),
    'model' => $this->model,
    'temperature' => $this->temperature,
    'max_tokens' => $this->maxTokens,
    'timestamp' => now()->toISOString()
]);
```

**What it captures:**
- ✅ Complete system prompt
- ✅ Formatted context (how data appears to OpenAI)
- ✅ Conversation history (previous exchanges)
- ✅ **Exact messages array sent to OpenAI API**
- ✅ Model parameters (temperature, max_tokens)

**Example Output:**
```json
{
  "system_prompt": "You are a helpful AI tutor for primary school students...",
  "formatted_context": "Student: Sarah (ID: 123)\nCurrent Context for tutor agent:\n\n=== SUBMISSION HISTORY ===\n[...]\n\n=== PERFORMANCE TRENDS ===\n[...]",
  "conversation_history": [
    {"role": "user", "content": "What is algebra?"},
    {"role": "assistant", "content": "Algebra is..."}
  ],
  "current_user_message": "How am I doing in math?",
  "full_messages_array": [
    {"role": "system", "content": "You are a helpful AI tutor..."},
    {"role": "system", "content": "Student: Sarah...\n=== DATA ==="},
    {"role": "user", "content": "What is algebra?"},
    {"role": "assistant", "content": "Algebra is..."},
    {"role": "user", "content": "How am I doing in math?"}
  ],
  "total_messages": 5,
  "model": "gpt-5-nano"
}
```

---

### 5. Stage 4: OpenAI Response Received
**Location**: `AbstractAgent.php` - After OpenAI API call

```php
Log::info('[AI AGENT] Stage 4: OpenAI Response Received', [
    'child_id' => $child->id,
    'agent_type' => $this->agentType,
    'ai_response' => $aiResponse,                    // COMPLETE RESPONSE
    'response_length' => strlen($aiResponse),
    'tokens_used' => [
        'prompt_tokens' => $response['usage']['prompt_tokens'],
        'completion_tokens' => $response['usage']['completion_tokens'],
        'total_tokens' => $response['usage']['total_tokens']
    ],
    'model_used' => $response['model'],
    'finish_reason' => $response['choices'][0]['finish_reason'],
    'timestamp' => now()->toISOString()
]);
```

**What it captures:**
- ✅ **Complete AI response text**
- ✅ Token usage breakdown (cost tracking)
- ✅ Model actually used by OpenAI
- ✅ Finish reason (completed, length, etc.)

**Example Output:**
```json
{
  "ai_response": "Based on your recent math assessments, you're doing great! Your average score is 82%, and I can see a clear upward trend...",
  "response_length": 342,
  "tokens_used": {
    "prompt_tokens": 2145,
    "completion_tokens": 187,
    "total_tokens": 2332
  },
  "model_used": "gpt-5-nano",
  "finish_reason": "stop"
}
```

---

### 6. Request Completed Successfully
**Location**: `TutorAgent.php` - End of `process()` method

```php
Log::info('[AI TUTOR] Request Completed Successfully', [
    'child_id' => $child->id,
    'user_message' => $message,                      // ORIGINAL MESSAGE
    'ai_response' => $aiResponse,                    // FINAL RESPONSE
    'data_sources_used' => $requirements['required_data'],
    'filters_applied' => $requirements['filters'],
    'context_size_bytes' => strlen(json_encode($specificContext)),
    'message_length' => strlen($message),
    'response_length' => strlen($aiResponse),
    'processing_time_seconds' => round($processingTime, 3),  // TOTAL TIME
    'timestamp' => now()->toISOString()
]);
```

**What it captures:**
- ✅ Complete summary of the request
- ✅ Total processing time (performance metric)
- ✅ Data efficiency metrics (context size)
- ✅ Final completion timestamp

---

## Error Logging

### Request Failed
**Location**: `TutorAgent.php` - Exception handler

```php
Log::error('[AI TUTOR] Request Failed', [
    'child_id' => $child->id,
    'message' => $context['message'],
    'error' => $e->getMessage(),
    'processing_time_seconds' => round($processingTime, 3),
    'trace' => $e->getTraceAsString()
]);
```

---

## Log Example: Complete Flow

```
[2025-10-10 23:10:00] local.INFO: ═══════════════════════════════════════════════════

[2025-10-10 23:10:00] local.INFO: [AI TUTOR] Request Initiated
{
  "child_id": 42,
  "child_name": "Sarah Johnson",
  "user_message": "How am I doing in math?",
  "message_length": 23,
  "session_id": 156
}

[2025-10-10 23:10:01] local.INFO: [AI TUTOR] Stage 1: Data Requirements Analysis
{
  "required_data": ["submission_history", "performance_trends"],
  "filters": {"category": "Mathematics", "time_range": "last_30_days"},
  "reasoning": "Student asking about math performance"
}

[2025-10-10 23:10:01] local.INFO: [AI TUTOR] Stage 2: Context Data Fetched
{
  "data_sources": ["submission_history", "performance_trends"],
  "fetched_data": { /* complete data here */ },
  "total_size": 1523
}

[2025-10-10 23:10:01] local.INFO: [AI AGENT] Stage 3: Sending to OpenAI
{
  "system_prompt": "You are a helpful AI tutor...",
  "formatted_context": "Student: Sarah...",
  "full_messages_array": [ /* all messages */ ],
  "model": "gpt-5-nano"
}

[2025-10-10 23:10:02] local.INFO: [AI AGENT] Stage 4: OpenAI Response Received
{
  "ai_response": "Based on your recent math assessments...",
  "tokens_used": {"total_tokens": 2332}
}

[2025-10-10 23:10:02] local.INFO: [AI TUTOR] Request Completed Successfully
{
  "processing_time_seconds": 2.147
}

[2025-10-10 23:10:02] local.INFO: ═══════════════════════════════════════════════════
```

---

## Analyzing Logs

### Find All Stages for a Specific Child
```bash
tail -f storage/logs/laravel.log | grep "child_id.*42"
```

### Track Processing Time
```bash
grep "processing_time_seconds" storage/logs/laravel.log
```

### Monitor Token Usage
```bash
grep "total_tokens" storage/logs/laravel.log
```

### Check Data Sources Used
```bash
grep "required_data" storage/logs/laravel.log
```

### View Complete User Messages
```bash
grep "user_message" storage/logs/laravel.log | jq '.user_message'
```

### View AI Responses
```bash
grep "ai_response" storage/logs/laravel.log | jq '.ai_response'
```

---

## Performance Metrics from Logs

### Average Processing Time
```bash
grep "processing_time_seconds" storage/logs/laravel.log | \
  jq -r '.processing_time_seconds' | \
  awk '{sum+=$1; count++} END {print sum/count}'
```

### Total Tokens Used (Cost Tracking)
```bash
grep "total_tokens" storage/logs/laravel.log | \
  jq -r '.tokens_used.total_tokens' | \
  awk '{sum+=$1} END {print sum}'
```

### Most Common Data Sources
```bash
grep "required_data" storage/logs/laravel.log | \
  jq -r '.required_data[]' | \
  sort | uniq -c | sort -nr
```

---

## Privacy & Security

⚠️ **Important**: These logs contain sensitive information including:
- Student names and IDs
- Complete user messages
- Complete AI responses
- Student performance data

**Recommendations:**
1. Use Laravel's log rotation to manage file size
2. Set appropriate file permissions (600 or 640)
3. Consider encrypting archived logs
4. Implement log retention policies
5. Use separate log channels for production (see below)

---

## Production Configuration

### Create Dedicated Log Channel

**config/logging.php**:
```php
'channels' => [
    'ai_interactions' => [
        'driver' => 'daily',
        'path' => storage_path('logs/ai-interactions.log'),
        'level' => 'info',
        'days' => 14,
        'permission' => 0640,
    ],
],
```

### Use in Code
```php
Log::channel('ai_interactions')->info('[AI TUTOR] Request Initiated', [...]);
```

---

## Benefits

1. **Complete Transparency**: Every step is logged
2. **Debugging**: Easy to identify where issues occur
3. **Performance Monitoring**: Track processing times and token usage
4. **Cost Tracking**: Monitor OpenAI API costs via token usage
5. **Data Efficiency**: Verify Two-Stage system is working (minimal data fetched)
6. **Audit Trail**: Complete record of all AI interactions

---

## Conclusion

The enhanced logging system provides unprecedented visibility into the AI Tutor's operation, enabling:
- Easy debugging and troubleshooting
- Performance optimization
- Cost monitoring
- Quality assurance
- Compliance and auditing

All while maintaining the efficiency gains of the Two-Stage AI System!
