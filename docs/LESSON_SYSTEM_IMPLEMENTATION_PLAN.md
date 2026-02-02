# COMPREHENSIVE IMPLEMENTATION PLAN
## Block-Based Interactive Lesson System with Live Synchronization

**Version:** 1.0  
**Date:** October 13, 2025  
**Status:** Planning Phase  

---

## TABLE OF CONTENTS

1. [Executive Summary](#1-executive-summary)
2. [System Architecture Overview](#2-system-architecture-overview)
3. [Complete Database Schema](#3-complete-database-schema)
4. [Block Type Specifications](#4-block-type-specifications)
5. [API Endpoints & Routes](#5-api-endpoints--routes)
6. [Frontend Component Architecture](#6-frontend-component-architecture)
7. [Real-Time WebSocket System](#7-real-time-websocket-system)
8. [AI Integration Strategy](#8-ai-integration-strategy)
9. [Implementation Phases & Timeline](#9-implementation-phases--timeline)
10. [Code Examples & Patterns](#10-code-examples--patterns)
11. [Testing Strategy](#11-testing-strategy)
12. [Deployment Considerations](#12-deployment-considerations)

---

# 1. EXECUTIVE SUMMARY

## 1.1 Project Goals

Transform the current lesson system into a comprehensive block-based content delivery platform with:

- **Hierarchical content organization** (Course → Module → Lesson → Slide)
- **Rich block-based content editor** with 14+ block types
- **Self-paced and live interactive lesson delivery modes**
- **Real-time teacher-student synchronization**
- **Integrated question bank** (questions embedded in slides with progress tracking)
- **Assessment system integration** (no major changes to existing system)
- **AI-powered authoring and runtime assistance**
- **Comprehensive progress tracking and analytics**
- **Upload and marking workflow** with AI assistance

## 1.2 Key Architectural Decisions

| **Decision** | **Rationale** |
|--------------|---------------|
| Rename current `Lesson` → `LiveSession` | Current model is for scheduled tutoring sessions, not content |
| Create new `Lesson` model | Represents reusable content modules |
| Blocks stored as JSON in slides | Flexibility without schema changes per block type |
| Questions attached from bank to slides | Reuse existing Question model, separate tracking from Assessments |
| WebSocket via Laravel Reverb/Pusher | Native Laravel support, scalable |
| Agora for audio/video | Industry-standard WebRTC, good free tier |
| Maintain existing Assessment model | No breaking changes to working system |

## 1.3 MVP Priorities

**Critical Features:**
1. ✅ Block-based slide engine + editor (core blocks)
2. ✅ Progress tracking (slides, time, questions, uploads)
3. ✅ Help panel v1 (TTS, AI hints, chat)
4. ✅ Uploads + rubric marking (with AI OCR assist)
5. ✅ Analytics v1 (funnels, performance, time vs estimate)
6. ✅ Live lesson bridge (teacher pacing, real-time sync)
7. ✅ Question bank integration in slides
8. ✅ Multi-tenant org/roles (already exists)

**Phase 2 Features:**
- Advanced analytics (skill heatmaps, mastery tracking)
- Lesson templates library
- Bulk import (PDF/Doc → slides)
- Whiteboard collaboration
- Session recording

---

# 2. SYSTEM ARCHITECTURE OVERVIEW

## 2.1 Content Hierarchy

```
Organization
  │
  ├─ Courses (e.g., "Year 5 Mathematics")
  │   │
  │   ├─ Modules (e.g., "Fractions & Decimals")
  │   │   │
  │   │   ├─ Lessons (e.g., "Converting Fractions to Decimals")
  │   │   │   │
  │   │   │   ├─ LessonSlides (individual screens)
  │   │   │   │   │
  │   │   │   │   ├─ Blocks (JSON: text, video, question, upload, etc.)
  │   │   │   │   └─ Questions (from Question bank via QuestionBlock)
  │   │   │   │
  │   │   │   └─ Assessments (linked via junction table)
  │   │   │
  │   │   └─ Assessments (module-level)
  │   │
  │   └─ Assessments (course-level)
```

## 2.2 Delivery Modes

### **Mode 1: Self-Paced Learning** (asynchronous)
- Student accesses lesson independently
- Progresses through slides at own speed
- AI help panel available (hints, TTS, chat)
- Questions tracked per child in `lesson_question_responses`
- Can pause and resume
- Progress stored in `lesson_progress`

### **Mode 2: Live Interactive Lesson** (synchronous)
- Teacher creates live session from lesson content
- Students join via code or link
- Teacher controls slide navigation (all students synced via WebSocket)
- Real-time audio/video communication (Agora)
- Interactive features: polls, whiteboard, raise hand
- Questions can be answered live, tracked same way
- Session recorded for later review (optional)

### **Mode 3: Hybrid**
- Lesson supports both modes
- Students can review async after live session
- Progress tracked separately for each mode

## 2.3 Question Integration Architecture

Questions from the existing `questions` table can be:

1. **Embedded in Assessments** (existing functionality - unchanged)
2. **Embedded in Lesson Slides** (NEW)
   - Added via QuestionBlock
   - Multiple questions per slide supported
   - Responses tracked in `lesson_question_responses`
   - Contributes to lesson completion score
   - Separate from formal Assessment submissions

**Key Difference:**
- **Assessments**: Formal tests with `assessment_submissions` and `assessment_submission_items`
- **Lesson Questions**: Inline practice/checks with `lesson_question_responses`
- Both use the same `questions` table as source

---

# 3. COMPLETE DATABASE SCHEMA

## 3.1 New Tables

### **courses**
```php
Schema::create('courses', function (Blueprint $table) {
    $table->id();
    $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
    $table->string('uid', 50)->unique();
    
    // Basic Info
    $table->string('title');
    $table->text('description')->nullable();
    $table->string('cover_image')->nullable();
    $table->string('thumbnail')->nullable();
    
    // Status & Versioning
    $table->enum('status', ['draft', 'review', 'live', 'archived'])->default('draft');
    $table->integer('version')->default(1);
    $table->json('change_log')->nullable();
    
    // Metadata
    $table->json('metadata')->nullable(); // {
    //   subject, topic, skills[], tags[], objectives[],
    //   exam_board, age_range, difficulty_level,
    //   estimated_hours, prerequisites[]
    // }
    
    // Authorship
    $table->foreignId('created_by')->nullable()->constrained('users');
    $table->foreignId('updated_by')->nullable()->constrained('users');
    
    // Sharing
    $table->boolean('is_global')->default(false);
    $table->foreignId('source_organization_id')->nullable()->constrained('organizations');
    
    $table->timestamps();
    $table->softDeletes();
    
    $table->index(['organization_id', 'status']);
    $table->index(['is_global', 'status']);
});
```

### **modules**
```php
Schema::create('modules', function (Blueprint $table) {
    $table->id();
    $table->foreignId('course_id')->constrained()->cascadeOnDelete();
    $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
    $table->string('uid', 50)->unique();
    
    // Basic Info
    $table->string('title');
    $table->text('description')->nullable();
    $table->integer('order_position')->default(0);
    
    // Status
    $table->enum('status', ['draft', 'review', 'live', 'archived'])->default('draft');
    
    // Metadata
    $table->json('metadata')->nullable();
    $table->json('prerequisites')->nullable(); // [module_id, module_id]
    $table->integer('estimated_minutes')->default(0);
    
    $table->timestamps();
    $table->softDeletes();
    
    $table->index(['course_id', 'order_position']);
});
```

### **lessons** (NEW - content-focused)
```php
Schema::create('lessons', function (Blueprint $table) {
    $table->id();
    $table->foreignId('module_id')->constrained()->cascadeOnDelete();
    $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
    $table->string('uid', 50)->unique();
    
    // Basic Info
    $table->string('title');
    $table->text('description')->nullable();
    $table->integer('order_position')->default(0);
    
    // Type & Delivery
    $table->enum('lesson_type', ['interactive', 'video', 'reading', 'practice', 'assessment'])->default('interactive');
    $table->enum('delivery_mode', ['self_paced', 'live_interactive', 'hybrid'])->default('self_paced');
    
    // Status
    $table->enum('status', ['draft', 'review', 'live', 'archived'])->default('draft');
    
    // Metadata
    $table->json('metadata')->nullable();
    $table->integer('estimated_minutes')->default(0);
    
    // Completion Rules
    $table->json('completion_rules')->nullable(); // {
    //   slides_viewed_percentage: 100,
    //   questions_score_threshold: 70,
    //   uploads_required: true,
    //   time_minimum_minutes: 5
    // }
    
    // AI Features
    $table->boolean('enable_ai_help')->default(true);
    $table->boolean('enable_tts')->default(true);
    
    $table->timestamps();
    $table->softDeletes();
    
    $table->index(['module_id', 'order_position']);
    $table->index(['delivery_mode', 'status']);
});
```

### **lesson_slides**
```php
Schema::create('lesson_slides', function (Blueprint $table) {
    $table->id();
    $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
    $table->string('uid', 50)->unique();
    
    // Basic Info
    $table->string('title');
    $table->integer('order_position')->default(0);
    
    // Content
    $table->json('blocks'); // Array of block objects
    
    // Template & Styling
    $table->string('template_id')->nullable();
    $table->json('layout_settings')->nullable(); // grid, columns, spacing
    
    // Teacher Notes
    $table->text('teacher_notes')->nullable();
    
    // Timing
    $table->integer('estimated_seconds')->default(60);
    $table->boolean('auto_advance')->default(false);
    $table->integer('min_time_seconds')->nullable(); // Time gate
    
    // Settings
    $table->json('settings')->nullable(); // {
    //   must_complete: true,
    //   allow_skip: false,
    //   show_progress: true
    // }
    
    $table->timestamps();
    
    $table->index(['lesson_id', 'order_position']);
});
```

### **lesson_progress**
```php
Schema::create('lesson_progress', function (Blueprint $table) {
    $table->id();
    $table->foreignId('child_id')->constrained()->cascadeOnDelete();
    $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
    
    // Progress Status
    $table->enum('status', ['not_started', 'in_progress', 'completed', 'abandoned'])->default('not_started');
    
    // Tracking
    $table->json('slides_viewed')->nullable(); // [slide_id, slide_id]
    $table->foreignId('last_slide_id')->nullable()->constrained('lesson_slides');
    $table->integer('completion_percentage')->default(0);
    $table->integer('time_spent_seconds')->default(0);
    
    // Scoring
    $table->decimal('score', 5, 2)->nullable();
    $table->integer('checks_passed')->default(0);
    $table->integer('checks_total')->default(0);
    
    // Questions (NEW)
    $table->integer('questions_attempted')->default(0);
    $table->integer('questions_correct')->default(0);
    $table->decimal('questions_score', 5, 2)->nullable();
    
    // Uploads
    $table->integer('uploads_submitted')->default(0);
    $table->integer('uploads_required')->default(0);
    
    // Completion
    $table->timestamp('started_at')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->timestamp('last_accessed_at')->nullable();
    
    // Session Ref (if from live session)
    $table->foreignId('live_lesson_session_id')->nullable()->constrained()->nullOnDelete();
    
    $table->timestamps();
    
    $table->unique(['child_id', 'lesson_id']);
    $table->index(['lesson_id', 'status']);
});
```

### **lesson_question_responses** (NEW)
```php
Schema::create('lesson_question_responses', function (Blueprint $table) {
    $table->id();
    $table->foreignId('child_id')->constrained()->cascadeOnDelete();
    $table->foreignId('lesson_progress_id')->constrained()->cascadeOnDelete();
    $table->foreignId('slide_id')->constrained('lesson_slides')->cascadeOnDelete();
    $table->string('block_id'); // UUID within slide
    $table->foreignId('question_id')->constrained()->cascadeOnDelete();
    
    // Response
    $table->json('answer_data'); // Student's answer (structure depends on question type)
    $table->boolean('is_correct')->nullable();
    $table->decimal('score_earned', 5, 2)->nullable();
    $table->decimal('score_possible', 5, 2);
    
    // Attempts
    $table->integer('attempt_number')->default(1);
    $table->integer('time_spent_seconds')->default(0);
    
    // Feedback
    $table->text('feedback')->nullable(); // Auto-feedback or teacher feedback
    $table->json('hints_used')->nullable(); // [hint_index, hint_index]
    
    // Timestamps
    $table->timestamp('answered_at')->nullable();
    $table->timestamps();
    
    $table->index(['child_id', 'lesson_progress_id']);
    $table->index(['question_id', 'is_correct']);
    $table->index(['slide_id', 'block_id']);
});
```

### **slide_interactions**
```php
Schema::create('slide_interactions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('child_id')->constrained()->cascadeOnDelete();
    $table->foreignId('slide_id')->constrained('lesson_slides')->cascadeOnDelete();
    $table->foreignId('lesson_progress_id')->nullable()->constrained()->cascadeOnDelete();
    
    // Timing
    $table->integer('time_spent_seconds')->default(0);
    $table->integer('interactions_count')->default(0); // clicks, scrolls, etc.
    
    // Engagement
    $table->json('help_requests')->nullable(); // [{ type: 'hint', timestamp, block_id }]
    $table->tinyInteger('confidence_rating')->nullable(); // 1-5
    $table->boolean('flagged_difficult')->default(false);
    
    // Block-level data
    $table->json('block_interactions')->nullable(); // { block_id: { views, time, answered } }
    
    $table->timestamp('first_viewed_at')->nullable();
    $table->timestamp('last_viewed_at')->nullable();
    
    $table->timestamps();
    
    $table->index(['child_id', 'slide_id']);
    $table->index(['slide_id']); // For analytics
});
```

### **lesson_uploads**
```php
Schema::create('lesson_uploads', function (Blueprint $table) {
    $table->id();
    $table->foreignId('child_id')->constrained()->cascadeOnDelete();
    $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
    $table->foreignId('slide_id')->constrained('lesson_slides')->cascadeOnDelete();
    $table->string('block_id'); // UUID reference within slide
    
    // File Info
    $table->string('file_path');
    $table->enum('file_type', ['image', 'pdf', 'audio', 'video', 'document'])->default('image');
    $table->integer('file_size_kb')->default(0);
    $table->string('original_filename')->nullable();
    
    // Grading Status
    $table->enum('status', ['pending', 'reviewing', 'graded', 'returned'])->default('pending');
    $table->decimal('score', 5, 2)->nullable();
    $table->json('rubric_data')->nullable();
    
    // Feedback
    $table->text('feedback')->nullable();
    $table->string('feedback_audio')->nullable(); // Teacher voice feedback
    $table->json('annotations')->nullable(); // Drawing/markup data
    
    // AI Analysis
    $table->json('ai_analysis')->nullable(); // {
    //   ocr_text, detected_steps[], confidence,
    //   suggested_score, suggested_feedback
    // }
    
    // Review
    $table->foreignId('reviewed_by')->nullable()->constrained('users');
    $table->timestamp('reviewed_at')->nullable();
    
    $table->timestamps();
    
    $table->index(['child_id', 'lesson_id']);
    $table->index(['status', 'reviewed_at']);
});
```

### **live_lesson_sessions**
```php
Schema::create('live_lesson_sessions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
    $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
    $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
    $table->string('uid', 50)->unique();
    
    // Session Code
    $table->string('session_code', 10)->unique(); // Join code
    
    // Status
    $table->enum('status', ['scheduled', 'live', 'completed', 'cancelled'])->default('scheduled');
    
    // Timing
    $table->timestamp('scheduled_start_time')->nullable();
    $table->timestamp('actual_start_time')->nullable();
    $table->timestamp('end_time')->nullable();
    
    // Current State
    $table->foreignId('current_slide_id')->nullable()->constrained('lesson_slides')->nullOnDelete();
    $table->enum('pacing_mode', ['teacher_controlled', 'student_flexible'])->default('teacher_controlled');
    
    // Features
    $table->boolean('audio_enabled')->default(true);
    $table->boolean('video_enabled')->default(false);
    $table->boolean('allow_student_questions')->default(true);
    $table->boolean('whiteboard_enabled')->default(true);
    
    // Connection
    $table->json('connection_info')->nullable(); // {
    //   agora_channel, agora_token, websocket_channel
    // }
    
    // Session Data
    $table->json('session_data')->nullable(); // {
    //   polls_created[], whiteboard_snapshots[],
    //   teacher_notes, recording_url
    // }
    
    // Recording
    $table->boolean('record_session')->default(false);
    $table->string('recording_url')->nullable();
    
    $table->timestamps();
    
    $table->index(['teacher_id', 'status']);
    $table->index(['session_code']);
});
```

### **live_session_participants**
```php
Schema::create('live_session_participants', function (Blueprint $table) {
    $table->id();
    $table->foreignId('live_lesson_session_id')->constrained()->cascadeOnDelete();
    $table->foreignId('child_id')->constrained()->cascadeOnDelete();
    
    // Status
    $table->enum('status', ['invited', 'joined', 'left', 'kicked'])->default('invited');
    $table->enum('connection_status', ['connected', 'disconnected', 'reconnecting'])->default('disconnected');
    
    // Timing
    $table->timestamp('invited_at')->nullable();
    $table->timestamp('joined_at')->nullable();
    $table->timestamp('left_at')->nullable();
    
    // Current State
    $table->foreignId('current_slide_id')->nullable()->constrained('lesson_slides')->nullOnDelete();
    $table->boolean('audio_muted')->default(false);
    $table->boolean('video_off')->default(true);
    
    // Engagement
    $table->json('interaction_data')->nullable(); // {
    //   raised_hand_count, questions_asked, polls_answered[]
    // }
    
    // Connection Quality
    $table->json('connection_metrics')->nullable(); // {
    //   latency_ms, packet_loss, bandwidth
    // }
    
    $table->timestamps();
    
    $table->unique(['live_lesson_session_id', 'child_id']);
    $table->index(['live_lesson_session_id', 'status']);
});
```

### **live_slide_interactions**
```php
Schema::create('live_slide_interactions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('live_lesson_session_id')->constrained()->cascadeOnDelete();
    $table->foreignId('slide_id')->constrained('lesson_slides')->cascadeOnDelete();
    $table->foreignId('child_id')->nullable()->constrained()->nullOnDelete();
    
    // Interaction Type
    $table->enum('interaction_type', [
        'poll_response', 'whiteboard_draw', 'question', 
        'annotation', 'raised_hand', 'emoji_reaction'
    ]);
    
    // Data
    $table->json('data'); // Type-specific payload
    
    // Metadata
    $table->boolean('is_teacher')->default(false);
    $table->boolean('visible_to_students')->default(false);
    
    $table->timestamp('created_at');
    
    $table->index(['live_lesson_session_id', 'slide_id']);
    $table->index(['child_id', 'interaction_type']);
});
```

## 3.2 Renamed/Modified Tables

### **Rename: lessons → live_sessions**
```php
// Migration: rename_lessons_to_live_sessions
Schema::rename('lessons', 'live_sessions');

// Rename pivot table
Schema::rename('child_lesson', 'child_live_session');

// Add new columns
Schema::table('live_sessions', function (Blueprint $table) {
    $table->foreignId('lesson_id')->nullable()->after('service_id')->constrained()->nullOnDelete();
    $table->enum('pacing_mode', ['self_paced', 'teacher_led'])->default('teacher_led')->after('status');
});
```

## 3.3 Junction Tables

### **assessment_lesson**
```php
Schema::create('assessment_lesson', function (Blueprint $table) {
    $table->id();
    $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
    $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
    $table->integer('order_position')->default(0);
    $table->enum('timing', ['inline', 'end_of_lesson', 'optional'])->default('end_of_lesson');
    $table->timestamps();
    
    $table->unique(['assessment_id', 'lesson_id']);
});
```

### **assessment_module**
```php
Schema::create('assessment_module', function (Blueprint $table) {
    $table->id();
    $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
    $table->foreignId('module_id')->constrained()->cascadeOnDelete();
    $table->enum('timing', ['pre_test', 'post_test', 'checkpoint'])->default('post_test');
    $table->timestamps();
});
```

### **assessment_course**
```php
Schema::create('assessment_course', function (Blueprint $table) {
    $table->id();
    $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
    $table->foreignId('course_id')->constrained()->cascadeOnDelete();
    $table->enum('timing', ['diagnostic', 'mid_term', 'final'])->default('final');
    $table->timestamps();
});
```

## 3.4 Modify Existing Tables

### **assessments** (add fields)
```php
Schema::table('assessments', function (Blueprint $table) {
    $table->enum('assessment_level', ['lesson', 'module', 'course', 'standalone'])->default('lesson')->after('lesson_id');
    $table->boolean('can_embed_in_slides')->default(false)->after('assessment_level');
});
```

### **accesses** (extend for new hierarchy)
```php
Schema::table('accesses', function (Blueprint $table) {
    $table->json('course_ids')->nullable()->after('lesson_ids');
    $table->json('module_ids')->nullable()->after('course_ids');
});
```

---

# 4. BLOCK TYPE SPECIFICATIONS

## 4.1 Block Base Schema

Every block in `lesson_slides.blocks` JSON array follows this structure:

```typescript
interface BaseBlock {
  id: string; // UUID
  type: BlockType;
  order: number;
  settings: BlockSettings;
  metadata?: BlockMetadata;
}

interface BlockSettings {
  visible: boolean;
  locked: boolean;
  conditional_display?: ConditionalRule[];
}

interface BlockMetadata {
  created_at: string;
  created_by: number;
  ai_generated: boolean;
  version: number;
}

type BlockType = 
  | 'text'
  | 'image'
  | 'video'
  | 'audio'
  | 'callout'
  | 'QuestionBlock'
  | 'UploadBlock'
  | 'embed'
  | 'timer'
  | 'reflection'
  | 'whiteboard'
  | 'code'
  | 'table'
  | 'divider';
```

## 4.2 Text Block

```typescript
interface TextBlock extends BaseBlock {
  type: 'text';
  content: {
    html: string; // Rich text with <p>, <strong>, <em>, <math>
    plain_text: string; // For accessibility/search
    reading_level?: number; // Flesch-Kincaid grade level
    estimated_read_seconds: number;
    language: string; // 'en', 'ur', etc.
  };
  settings: {
    font_size: 'small' | 'medium' | 'large' | 'x-large';
    show_audio_player: boolean;
    audio_url?: string; // TTS-generated audio
    highlight_keywords: boolean;
    allow_student_notes: boolean;
  };
}
```

**Example JSON:**
```json
{
  "id": "b1a2c3d4-e5f6-7890-abcd-ef1234567890",
  "type": "text",
  "order": 1,
  "content": {
    "html": "<p>A <strong>fraction</strong> represents a part of a whole. For example, <math><mfrac><mn>1</mn><mn>2</mn></mfrac></math> means one out of two equal parts.</p>",
    "plain_text": "A fraction represents a part of a whole. For example, 1/2 means one out of two equal parts.",
    "reading_level": 5,
    "estimated_read_seconds": 12,
    "language": "en"
  },
  "settings": {
    "font_size": "medium",
    "show_audio_player": true,
    "audio_url": "/storage/audio/tts_b1a2c3d4.mp3",
    "highlight_keywords": true,
    "allow_student_notes": true,
    "visible": true,
    "locked": false
  }
}
```

## 4.3 Image Block

```typescript
interface ImageBlock extends BaseBlock {
  type: 'image';
  content: {
    url: string;
    alt_text: string; // Required for accessibility
    caption?: string;
    attribution?: string;
    dimensions: { width: number; height: number };
    file_size_kb: number;
  };
  settings: {
    display_mode: 'fit' | 'fill' | 'original';
    allow_zoom: boolean;
    show_caption: boolean;
    lightbox: boolean;
    lazy_load: boolean;
  };
}
```

## 4.4 Video Block

```typescript
interface VideoBlock extends BaseBlock {
  type: 'video';
  content: {
    url: string; // YouTube, Vimeo, or local
    provider: 'youtube' | 'vimeo' | 'local';
    video_id?: string;
    thumbnail?: string;
    duration_seconds: number;
    chapters?: VideoChapter[];
    transcript?: string;
    subtitles_url?: string;
  };
  settings: {
    auto_play: boolean;
    show_controls: boolean;
    must_watch_percentage: number; // 0-100, for completion
    allow_skip: boolean;
    allow_speed_control: boolean;
    start_time_seconds?: number;
  };
}

interface VideoChapter {
  time_seconds: number;
  title: string;
  description?: string;
}
```

## 4.5 Audio Block

```typescript
interface AudioBlock extends BaseBlock {
  type: 'audio';
  content: {
    url: string;
    duration_seconds: number;
    transcript?: string;
    waveform_data?: number[]; // Visual representation
  };
  settings: {
    auto_play: boolean;
    show_transcript: boolean;
    must_listen_percentage: number;
    playback_speed_options: number[]; // [0.5, 1, 1.5, 2]
  };
}
```

## 4.6 Callout Block

```typescript
interface CalloutBlock extends BaseBlock {
  type: 'callout';
  content: {
    title?: string;
    text: string;
    callout_type: 'info' | 'warning' | 'success' | 'danger' | 'tip';
    icon?: string;
  };
  settings: {
    dismissible: boolean;
    collapsible: boolean;
    default_collapsed: boolean;
  };
}
```

## 4.7 Question Block (UPDATED WITH MULTIPLE QUESTIONS)

```typescript
interface QuestionBlock extends BaseBlock {
  type: 'QuestionBlock';
  content: {
    // NEW: Support multiple questions from bank
    question_ids: number[];  // Array of Question model IDs
    
    // Display settings
    display_mode: 'one_at_a_time' | 'all_at_once';
    randomize_order: boolean;
    
    // Scoring
    scoring_mode: 'immediate' | 'deferred' | 'practice';
    show_solution: boolean;
    retry_allowed: boolean;
    max_attempts?: number;
    
    // Help
    hints_enabled: boolean;
  };
  settings: {
    required: boolean;
    show_feedback: boolean;
    feedback_timing: 'immediate' | 'after_block' | 'after_lesson';
  };
}
```

**Example JSON:**
```json
{
  "id": "q-block-123",
  "type": "QuestionBlock",
  "order": 3,
  "content": {
    "question_ids": [45, 67, 89],
    "display_mode": "one_at_a_time",
    "randomize_order": false,
    "scoring_mode": "immediate",
    "show_solution": true,
    "retry_allowed": true,
    "max_attempts": 3,
    "hints_enabled": true
  },
  "settings": {
    "required": true,
    "show_feedback": true,
    "feedback_timing": "immediate",
    "visible": true,
    "locked": false
  }
}
```

**How it works:**
1. Block references questions by ID from `questions` table
2. Questions loaded dynamically when slide viewed
3. Student responses saved to `lesson_question_responses`
4. Progress tracked in `lesson_progress` (questions_attempted, questions_correct)
5. Separate from formal Assessments system

## 4.8 Upload Block

```typescript
interface UploadBlock extends BaseBlock {
  type: 'UploadBlock';
  content: {
    title: string;
    instructions: string;
    accepted_types: string[]; // ['image/*', 'application/pdf', 'audio/*']
    max_size_mb: number;
    max_files: number;
    rubric?: Rubric;
    example_uploads?: string[]; // URLs to example work
    required: boolean;
  };
  settings: {
    allow_camera: boolean; // Direct camera capture
    allow_audio_recording: boolean;
    allow_video_recording: boolean;
    ocr_enabled: boolean; // AI text extraction
    auto_grade: boolean; // AI grading attempt
  };
}

interface Rubric {
  criteria: RubricCriterion[];
  total_points: number;
}

interface RubricCriterion {
  name: string;
  description: string;
  points: number;
  levels: RubricLevel[];
}

interface RubricLevel {
  name: string; // e.g., "Excellent", "Good", "Needs Improvement"
  description: string;
  points: number;
}
```

## 4.9 Embed Block

```typescript
interface EmbedBlock extends BaseBlock {
  type: 'embed';
  content: {
    embed_type: 'iframe' | 'pdf' | 'h5p' | 'geogebra' | 'scratch';
    url: string;
    html?: string; // Raw embed code
    dimensions: { width: string; height: string };
  };
  settings: {
    sandbox: boolean; // iframe sandbox
    allow_fullscreen: boolean;
    interactive: boolean;
  };
}
```

## 4.10 Timer Block

```typescript
interface TimerBlock extends BaseBlock {
  type: 'timer';
  content: {
    duration_seconds: number;
    timer_type: 'countdown' | 'stopwatch';
    message_on_complete?: string;
    auto_advance_on_complete: boolean;
  };
  settings: {
    show_timer: boolean;
    allow_pause: boolean;
    sound_enabled: boolean;
  };
}
```

## 4.11 Reflection Block

```typescript
interface ReflectionBlock extends BaseBlock {
  type: 'reflection';
  content: {
    prompt: string;
    reflection_type: 'text' | 'emoji' | 'rating';
    placeholder?: string;
  };
  settings: {
    required: boolean;
    character_limit?: number;
    save_private: boolean; // Only student sees, or share with teacher
  };
}
```

## 4.12 Whiteboard Block

```typescript
interface WhiteboardBlock extends BaseBlock {
  type: 'whiteboard';
  content: {
    background_type: 'blank' | 'grid' | 'image';
    background_image?: string;
    initial_content?: any; // Saved drawing data
    prompt?: string;
  };
  settings: {
    tools_available: ('pen' | 'highlighter' | 'eraser' | 'shapes' | 'text')[];
    colors_available: string[];
    allow_save: boolean;
    collaborative: boolean; // For live sessions
  };
}
```

## 4.13 Code Block

```typescript
interface CodeBlock extends BaseBlock {
  type: 'code';
  content: {
    code: string;
    language: string; // 'python', 'javascript', 'html', etc.
    filename?: string;
    output?: string; // Expected output
  };
  settings: {
    show_line_numbers: boolean;
    allow_copy: boolean;
    runnable: boolean; // If true, embed code executor
    theme: 'light' | 'dark';
  };
}
```

## 4.14 Table Block

```typescript
interface TableBlock extends BaseBlock {
  type: 'table';
  content: {
    headers: string[];
    rows: string[][];
    caption?: string;
  };
  settings: {
    striped: boolean;
    bordered: boolean;
    hoverable: boolean;
    responsive: boolean;
  };
}
```

## 4.15 Divider Block

```typescript
interface DividerBlock extends BaseBlock {
  type: 'divider';
  content: {
    divider_style: 'solid' | 'dashed' | 'dotted' | 'double';
    thickness: number;
    color?: string;
  };
}
```

---

# 5. API ENDPOINTS & ROUTES

## 5.1 Course Management

```php
// routes/admin.php

// Courses
Route::prefix('courses')->group(function () {
    Route::get('/', [CourseController::class, 'index']); // List all
    Route::post('/', [CourseController::class, 'store']); // Create
    Route::get('/{course}', [CourseController::class, 'show']); // View
    Route::put('/{course}', [CourseController::class, 'update']); // Update
    Route::delete('/{course}', [CourseController::class, 'destroy']); // Delete
    
    // Versioning
    Route::post('/{course}/duplicate', [CourseController::class, 'duplicate']);
    Route::post('/{course}/publish', [CourseController::class, 'publish']);
    Route::post('/{course}/archive', [CourseController::class, 'archive']);
    
    // AI Assists
    Route::post('/{course}/ai/generate-outline', [CourseAIController::class, 'generateOutline']);
    Route::post('/{course}/ai/suggest-objectives', [CourseAIController::class, 'suggestObjectives']);
    
    // Modules within course
    Route::prefix('/{course}/modules')->group(function () {
        Route::get('/', [ModuleController::class, 'index']);
        Route::post('/', [ModuleController::class, 'store']);
        Route::get('/{module}', [ModuleController::class, 'show']);
        Route::put('/{module}', [ModuleController::class, 'update']);
        Route::delete('/{module}', [ModuleController::class, 'destroy']);
        Route::post('/reorder', [ModuleController::class, 'reorder']);
    });
});
```

## 5.2 Lesson Authoring

```php
// Lessons
Route::prefix('lessons')->group(function () {
    Route::get('/', [LessonController::class, 'index']);
    Route::post('/', [LessonController::class, 'store']);
    Route::get('/{lesson}', [LessonController::class, 'show']);
    Route::put('/{lesson}', [LessonController::class, 'update']);
    Route::delete('/{lesson}', [LessonController::class, 'destroy']);
    
    // Slides
    Route::prefix('/{lesson}/slides')->group(function () {
        Route::get('/', [LessonSlideController::class, 'index']);
        Route::post('/', [LessonSlideController::class, 'store']);
        Route::get('/{slide}', [LessonSlideController::class, 'show']);
        Route::put('/{slide}', [LessonSlideController::class, 'update']);
        Route::delete('/{slide}', [LessonSlideController::class, 'destroy']);
        Route::post('/reorder', [LessonSlideController::class, 'reorder']);
        
        // Block operations
        Route::post('/{slide}/blocks', [BlockController::class, 'addBlock']);
        Route::put('/{slide}/blocks/{blockId}', [BlockController::class, 'updateBlock']);
        Route::delete('/{slide}/blocks/{blockId}', [BlockController::class, 'deleteBlock']);
    });
    
    // AI Assists
    Route::post('/{lesson}/ai/generate-slides', [LessonAIController::class, 'generateSlides']);
    Route::post('/{lesson}/slides/{slide}/ai/simplify-text', [LessonAIController::class, 'simplifyText']);
    Route::post('/{lesson}/slides/{slide}/ai/generate-tts', [LessonAIController::class, 'generateTTS']);
    Route::post('/{lesson}/slides/{slide}/ai/alt-text', [LessonAIController::class, 'generateAltText']);
    
    // Assessments
    Route::post('/{lesson}/assessments/{assessment}/attach', [LessonController::class, 'attachAssessment']);
    Route::delete('/{lesson}/assessments/{assessment}/detach', [LessonController::class, 'detachAssessment']);
});
```

## 5.3 Student Lesson Player

```php
// routes/parent.php

// Browse & Access
Route::prefix('courses')->group(function () {
    Route::get('/', [StudentCourseController::class, 'browse']);
    Route::get('/{course}', [StudentCourseController::class, 'show']);
});

Route::prefix('lessons')->group(function () {
    // Start lesson
    Route::post('/{lesson}/start', [LessonPlayerController::class, 'start']);
    
    // Player
    Route::get('/{lesson}/player', [LessonPlayerController::class, 'view']);
    Route::get('/{lesson}/slides/{slide}', [LessonPlayerController::class, 'getSlide']);
    
    // Progress
    Route::post('/{lesson}/progress/update', [LessonPlayerController::class, 'updateProgress']);
    Route::post('/{lesson}/slides/{slide}/view', [LessonPlayerController::class, 'recordSlideView']);
    Route::post('/{lesson}/complete', [LessonPlayerController::class, 'complete']);
    
    // Interactions
    Route::post('/{lesson}/slides/{slide}/interaction', [LessonPlayerController::class, 'recordInteraction']);
    Route::post('/{lesson}/slides/{slide}/confidence', [LessonPlayerController::class, 'submitConfidence']);
    
    // Questions (NEW)
    Route::post('/{lesson}/slides/{slide}/question-response', [LessonQuestionController::class, 'submitResponse']);
    Route::get('/{lesson}/question-responses', [LessonQuestionController::class, 'getResponses']);
    Route::post('/{lesson}/questions/{response}/retry', [LessonQuestionController::class, 'retryQuestion']);
    
    // Uploads
    Route::post('/{lesson}/slides/{slide}/upload', [LessonUploadController::class, 'upload']);
    Route::get('/{lesson}/uploads', [LessonUploadController::class, 'index']);
    Route::get('/uploads/{upload}', [LessonUploadController::class, 'show']);
    
    // AI Help
    Route::post('/{lesson}/ai/hint', [LessonAIController::class, 'getHint']);
    Route::post('/{lesson}/ai/explain', [LessonAIController::class, 'explainConcept']);
    Route::post('/{lesson}/ai/chat', [LessonAIController::class, 'chat']);
});
```

## 5.4 Live Lesson Sessions

```php
// routes/teacher.php (NEW FILE)

Route::prefix('live-lessons')->group(function () {
    // Session Management
    Route::get('/', [LiveLessonController::class, 'index']); // Teacher's sessions
    Route::post('/', [LiveLessonController::class, 'create']); // Create new session
    Route::get('/{session}', [LiveLessonController::class, 'show']); // Session details
    Route::put('/{session}', [LiveLessonController::class, 'update']);
    Route::delete('/{session}', [LiveLessonController::class, 'destroy']);
    
    // Session Control
    Route::post('/{session}/start', [LiveLessonController::class, 'start']);
    Route::post('/{session}/end', [LiveLessonController::class, 'end']);
    Route::post('/{session}/pause', [LiveLessonController::class, 'pause']);
    Route::post('/{session}/resume', [LiveLessonController::class, 'resume']);
    
    // Navigation
    Route::post('/{session}/slide', [LiveLessonController::class, 'changeSlide']);
    Route::post('/{session}/pace', [LiveLessonController::class, 'setPacingMode']);
    
    // Participants
    Route::get('/{session}/participants', [LiveLessonController::class, 'getParticipants']);
    Route::post('/{session}/invite', [LiveLessonController::class, 'inviteParticipants']);
    Route::post('/{session}/kick/{child}', [LiveLessonController::class, 'kickParticipant']);
    
    // Interactive Features
    Route::post('/{session}/poll', [LivePollController::class, 'create']);
    Route::get('/{session}/poll/{poll}/results', [LivePollController::class, 'results']);
    Route::post('/{session}/whiteboard', [LiveWhiteboardController::class, 'update']);
    Route::get('/{session}/questions', [LiveQuestionController::class, 'index']);
    Route::post('/{session}/questions/{question}/answer', [LiveQuestionController::class, 'answer']);
    
    // Control Panel View
    Route::get('/{session}/control', [LiveLessonController::class, 'controlPanel']);
});

// routes/parent.php

Route::prefix('live-lessons')->group(function () {
    // Browse available sessions
    Route::get('/', [StudentLiveLessonController::class, 'browse']);
    
    // Join
    Route::post('/join', [StudentLiveLessonController::class, 'join']); // With session_code
    Route::post('/{session}/join', [StudentLiveLessonController::class, 'joinById']); // With session ID
    
    // Student View
    Route::get('/{session}/view', [StudentLiveLessonController::class, 'view']);
    Route::post('/{session}/leave', [StudentLiveLessonController::class, 'leave']);
    
    // Student Interactions
    Route::post('/{session}/raise-hand', [StudentLiveLessonController::class, 'raiseHand']);
    Route::post('/{session}/question', [StudentLiveLessonController::class, 'askQuestion']);
    Route::post('/{session}/poll/{poll}/respond', [StudentLiveLessonController::class, 'respondToPoll']);
    Route::post('/{session}/emoji', [StudentLiveLessonController::class, 'sendEmoji']);
    
    // Connection Status
    Route::post('/{session}/heartbeat', [StudentLiveLessonController::class, 'heartbeat']);
});
```

## 5.5 Upload Review & Marking

```php
// routes/admin.php or routes/teacher.php

Route::prefix('lesson-uploads')->group(function () {
    Route::get('/', [LessonUploadReviewController::class, 'index']); // Pending uploads
    Route::get('/{upload}', [LessonUploadReviewController::class, 'show']);
    Route::post('/{upload}/grade', [LessonUploadReviewController::class, 'grade']);
    Route::post('/{upload}/feedback', [LessonUploadReviewController::class, 'submitFeedback']);
    Route::post('/{upload}/annotate', [LessonUploadReviewController::class, 'saveAnnotations']);
    Route::post('/{upload}/return', [LessonUploadReviewController::class, 'returnToStudent']);
    
    // AI Assist
    Route::post('/{upload}/ai/analyze', [UploadAIController::class, 'analyze']);
    Route::post('/{upload}/ai/suggest-grade', [UploadAIController::class, 'suggestGrade']);
    Route::post('/{upload}/ai/suggest-feedback', [UploadAIController::class, 'suggestFeedback']);
});
```

## 5.6 Analytics & Reports

```php
Route::prefix('analytics')->group(function () {
    // Course Analytics
    Route::get('/courses/{course}', [AnalyticsController::class, 'courseAnalytics']);
    Route::get('/modules/{module}', [AnalyticsController::class, 'moduleAnalytics']);
    Route::get('/lessons/{lesson}', [AnalyticsController::class, 'lessonAnalytics']);
    
    // Learner Analytics
    Route::get('/learners/{child}', [AnalyticsController::class, 'learnerProgress']);
    Route::get('/learners/{child}/lessons/{lesson}', [AnalyticsController::class, 'learnerLessonDetail']);
    
    // Cohort Analytics
    Route::get('/cohorts', [AnalyticsController::class, 'cohortDashboard']);
    Route::get('/cohorts/{cohort}', [AnalyticsController::class, 'cohortDetail']);
    
    // Heatmaps
    Route::get('/lessons/{lesson}/heatmap', [AnalyticsController::class, 'lessonHeatmap']);
    Route::get('/lessons/{lesson}/slides/{slide}/engagement', [AnalyticsController::class, 'slideEngagement']);
    
    // Question Analytics (NEW)
    Route::get('/lessons/{lesson}/question-performance', [AnalyticsController::class, 'questionPerformance']);
    Route::get('/questions/{question}/stats', [AnalyticsController::class, 'questionStats']);
});
```

---

# 6. FRONTEND COMPONENT ARCHITECTURE

## 6.1 Admin Portal Structure

```
resources/js/admin/
├── Pages/
│   ├── Courses/
│   │   ├── Index.jsx              // Course list
│   │   ├── Create.jsx             // New course form
│   │   ├── Edit.jsx               // Edit course
│   │   ├── Show.jsx               // Course details + modules
│   │   └── Builder.jsx            // Course builder wizard
│   │
│   ├── Modules/
│   │   ├── Create.jsx
│   │   ├── Edit.jsx
│   │   └── Show.jsx               // Module details + lessons
│   │
│   ├── Lessons/
│   │   ├── Index.jsx
│   │   ├── Create.jsx
│   │   ├── Edit.jsx
│   │   ├── Show.jsx
│   │   └── SlideEditor.jsx        // ⭐ Main slide editing interface
│   │
│   └── LiveSessions/              // Renamed from Lessons/
│       ├── Index.jsx
│       ├── Create.jsx
│       ├── Edit.jsx
│       └── Show.jsx
│
├── components/
│   ├── BlockEditor/
│   │   ├── BlockEditor.jsx        // ⭐ Main editor component
│   │   ├── BlockPalette.jsx       // Draggable block list
│   │   ├── BlockCanvas.jsx        // Drop zone
│   │   ├── BlockToolbar.jsx       // Settings toolbar
│   │   ├── BlockPreview.jsx       // Live preview mode
│   │   │
│   │   ├── blocks/
│   │   │   ├── BaseBlock.jsx      // Abstract base
│   │   │   ├── TextBlock/
│   │   │   │   ├── TextBlockEditor.jsx
│   │   │   │   ├── TextBlockSettings.jsx
│   │   │   │   └── TextBlockPreview.jsx
│   │   │   ├── ImageBlock/
│   │   │   ├── VideoBlock/
│   │   │   ├── QuestionBlock/
│   │   │   │   ├── QuestionBlockEditor.jsx
│   │   │   │   ├── QuestionSelector.jsx    // Select from bank
│   │   │   │   └── QuestionBlockPreview.jsx
│   │   │   ├── UploadBlock/
│   │   │   └── ... (all 14 block types)
│   │   │
│   │   └── utilities/
│   │       ├── DragDrop.jsx       // React DnD wrapper
│   │       ├── BlockValidator.jsx
│   │       └── BlockSerializer.jsx
│   │
│   ├── LessonBuilder/
│   │   ├── LessonOutline.jsx      // Tree view of slides
│   │   ├── SlideList.jsx
│   │   ├── SlideCard.jsx
│   │   └── SlideReorder.jsx
│   │
│   ├── AIAssist/
│   │   ├── OutlineGenerator.jsx
│   │   ├── ContentSimplifier.jsx
│   │   ├── TTSGenerator.jsx
│   │   ├── AltTextGenerator.jsx
│   │   └── QuestionGenerator.jsx
│   │
│   └── UploadReview/
│       ├── UploadList.jsx
│       ├── UploadViewer.jsx       // PDF/image viewer
│       ├── AnnotationTool.jsx     // Draw on uploads
│       ├── RubricScorer.jsx
│       └── FeedbackEditor.jsx
```

## 6.2 Teacher Portal Structure

```
resources/js/teacher/                // NEW DIRECTORY
├── Pages/
│   ├── Dashboard.jsx
│   │
│   ├── LiveLesson/
│   │   ├── Index.jsx              // List of teacher's sessions
│   │   ├── Create.jsx             // Create new live session
│   │   ├── Setup.jsx              // Session settings
│   │   ├── Control.jsx            // ⭐ Live control panel
│   │   └── SessionHistory.jsx     // Past sessions
│   │
│   └── UploadReview/
│       └── Index.jsx              // Pending uploads to grade
│
├── components/
│   ├── LiveLesson/
│   │   ├── ControlPanel/
│   │   │   ├── SessionHeader.jsx
│   │   │   ├── SlideNavigator.jsx
│   │   │   ├── PacingControls.jsx
│   │   │   └── SessionStatus.jsx
│   │   │
│   │   ├── Participants/
│   │   │   ├── ParticipantsList.jsx
│   │   │   ├── ParticipantCard.jsx
│   │   │   └── ParticipantStats.jsx
│   │   │
│   │   ├── Interaction/
│   │   │   ├── InteractionQueue.jsx    // Raised hands, questions
│   │   │   ├── QuestionFeed.jsx
│   │   │   ├── PollCreator.jsx
│   │   │   ├── PollResults.jsx
│   │   │   └── EmojiReactions.jsx
│   │   │
│   │   ├── Whiteboard/
│   │   │   ├── WhiteboardTools.jsx
│   │   │   ├── DrawingCanvas.jsx
│   │   │   └── WhiteboardControls.jsx
│   │   │
│   │   └── SlideViewer/
│   │       ├── TeacherSlideView.jsx    // Slide + notes
│   │       ├── TeacherNotes.jsx
│   │       └── StudentViewPreview.jsx  // What students see
│   │
│   └── shared/
│       └── (shared components)
```

## 6.3 Parent/Student Portal Structure

```
resources/js/parent/
├── Pages/
│   ├── Courses/
│   │   ├── Browse.jsx             // Course catalog
│   │   ├── Show.jsx               // Course details
│   │   └── Progress.jsx           // Course progress
│   │
│   ├── Lessons/
│   │   ├── Player.jsx             // ⭐ Self-paced lesson player
│   │   ├── Summary.jsx            // Lesson completion summary
│   │   └── Uploads.jsx            // View uploads & feedback
│   │
│   └── LiveLesson/
│       ├── Browse.jsx             // Available live sessions
│       ├── Join.jsx               // Join with code
│       └── View.jsx               // ⭐ Live lesson student view
│
├── components/
│   ├── LessonPlayer/
│   │   ├── LessonPlayer.jsx       // ⭐ Main player component
│   │   ├── SlideRail.jsx          // Slide navigation sidebar
│   │   ├── ProgressBar.jsx
│   │   ├── SlideViewer.jsx        // Current slide display
│   │   │
│   │   ├── HelpPanel/
│   │   │   ├── HelpPanel.jsx      // Right-side assistance panel
│   │   │   ├── TTSPlayer.jsx      // Text-to-speech
│   │   │   ├── GlossaryViewer.jsx
│   │   │   ├── HintButton.jsx
│   │   │   ├── AIChat.jsx         // Ask questions
│   │   │   └── TranscriptViewer.jsx
│   │   │
│   │   ├── blocks/
│   │   │   ├── TextBlockViewer.jsx
│   │   │   ├── ImageBlockViewer.jsx
│   │   │   ├── VideoBlockViewer.jsx
│   │   │   ├── QuestionBlockViewer.jsx
│   │   │   │   ├── QuestionRenderer.jsx
│   │   │   │   ├── AnswerSubmitter.jsx
│   │   │   │   └── FeedbackDisplay.jsx
│   │   │   ├── UploadBlockViewer.jsx
│   │   │   └── ... (viewer for each block type)
│   │   │
│   │   └── Controls/
│   │       ├── NavigationButtons.jsx
│   │       ├── PaceIndicator.jsx
│   │       ├── ConfidenceSlider.jsx
│   │       └── FlagDifficultButton.jsx
│   │
│   ├── LiveLesson/
│   │   ├── StudentView/
│   │   │   ├── LiveSlideViewer.jsx        // Synced with teacher
│   │   │   ├── SyncIndicator.jsx
│   │   │   └── ConnectionStatus.jsx
│   │   │
│   │   ├── Interaction/
│   │   │   ├── InteractionToolbar.jsx
│   │   │   ├── RaiseHandButton.jsx
│   │   │   ├── AskQuestionModal.jsx
│   │   │   ├── PollResponder.jsx
│   │   │   └── EmojiReactions.jsx
│   │   │
│   │   └── Media/
│   │       ├── AudioControls.jsx          // Mute/unmute
│   │       ├── VideoControls.jsx
│   │       └── ConnectionQuality.jsx
│   │
│   └── shared/
│       ├── BlockRenderer.jsx      // Universal block renderer
│       └── AccessibilityControls.jsx
```

---

# 7. REAL-TIME WEBSOCKET SYSTEM

## 7.1 Technology Stack

**Backend:**
- **Laravel Reverb** (Laravel 11+) OR **Laravel WebSockets** (self-hosted Pusher)
- **Laravel Broadcasting** (built-in)
- **Redis** for pub/sub

**Frontend:**
- **Laravel Echo** (JavaScript client)
- **Pusher JS** (protocol-compatible)

**Audio/Video:**
- **Agora RTC SDK** (recommended)
- Fallback: Jitsi (open-source)

## 7.2 Broadcasting Setup

### **Install Laravel Reverb**
```bash
composer require laravel/reverb
php artisan reverb:install
```

### **config/broadcasting.php**
```php
'connections' => [
    'reverb' => [
        'driver' => 'reverb',
        'app_id' => env('REVERB_APP_ID'),
        'key' => env('REVERB_APP_KEY'),
        'secret' => env('REVERB_APP_SECRET'),
        'options' => [
            'host' => env('REVERB_HOST', '127.0.0.1'),
            'port' => env('REVERB_PORT', 8080),
            'scheme' => env('REVERB_SCHEME', 'http'),
        ],
    ],
],
```

### **.env**
```
BROADCAST_DRIVER=reverb
REVERB_APP_ID=your-app-id
REVERB_APP_KEY=your-app-key
REVERB_APP_SECRET=your-app-secret
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http

# Agora
AGORA_APP_ID=your-agora-app-id
AGORA_APP_CERTIFICATE=your-agora-certificate
```

### **Start Reverb Server**
```bash
php artisan reverb:start
```

## 7.3 Broadcasting Events

### **app/Events/SlideChanged.php**
```php
<?php

namespace App\Events;

use App\Models\LiveLessonSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SlideChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public LiveLessonSession $session,
        public int $slideId,
        public int $teacherId
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("live-session.{$this->session->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'slide.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->session->id,
            'slide_id' => $this->slideId,
            'teacher_id' => $this->teacherId,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
```

### **Other Events:**

**app/Events/**
- `SessionStateChanged.php` - started, paused, ended
- `StudentInteraction.php` - raised_hand, question, poll_response
- `WhiteboardUpdated.php` - drawing data
- `PollBroadcast.php` - new poll, results
- `ParticipantJoined.php`
- `ParticipantLeft.php`
- `ChatMessageSent.php`

## 7.4 Channel Authorization

### **routes/channels.php**
```php
use App\Models\LiveLessonSession;
use App\Models\User;

// Teacher channel
Broadcast::channel('live-session.{sessionId}.teacher', function (User $user, int $sessionId) {
    $session = LiveLessonSession::find($sessionId);
    return $session && $session->teacher_id === $user->id;
});

// Session-wide channel (presence)
Broadcast::channel('live-session.{sessionId}', function (User $user, int $sessionId) {
    $session = LiveLessonSession::find($sessionId);
    
    // Check if user is teacher
    if ($session->teacher_id === $user->id) {
        return ['id' => $user->id, 'name' => $user->name, 'role' => 'teacher'];
    }
    
    // Check if user's child is participant
    $childIds = $user->children->pluck('id');
    $isParticipant = $session->participants()
        ->whereIn('child_id', $childIds)
        ->exists();
    
    if ($isParticipant) {
        return ['id' => $user->id, 'name' => $user->name, 'role' => 'student'];
    }
    
    return false;
});
```

## 7.5 Frontend WebSocket Client

### **resources/js/bootstrap.js**
```javascript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
    authorizer: (channel) => {
        return {
            authorize: (socketId, callback) => {
                axios.post('/broadcasting/auth', {
                    socket_id: socketId,
                    channel_name: channel.name
                })
                .then(response => callback(null, response.data))
                .catch(error => callback(error));
            }
        };
    },
});
```

### **Student View Example**
```jsx
// resources/js/parent/Pages/LiveLesson/View.jsx
import { useEffect, useState } from 'react';

export default function LiveLessonView({ session, lesson }) {
    const [currentSlideId, setCurrentSlideId] = useState(session.current_slide_id);
    const [participants, setParticipants] = useState([]);
    const [pollActive, setPollActive] = useState(null);

    useEffect(() => {
        // Subscribe to session channel
        const channel = window.Echo.private(`live-session.${session.id}`)
            .listen('.slide.changed', (e) => {
                console.log('Teacher changed slide:', e);
                setCurrentSlideId(e.slide_id);
            })
            .listen('.poll.broadcast', (e) => {
                console.log('New poll:', e);
                setPollActive(e.poll);
            })
            .listen('.session.state.changed', (e) => {
                console.log('Session state:', e);
                if (e.state === 'ended') {
                    // Redirect to summary
                }
            });

        // Presence channel for participant list
        const presenceChannel = window.Echo.join(`live-session.${session.id}`)
            .here((users) => {
                setParticipants(users);
            })
            .joining((user) => {
                setParticipants(prev => [...prev, user]);
            })
            .leaving((user) => {
                setParticipants(prev => prev.filter(p => p.id !== user.id));
            });

        return () => {
            window.Echo.leave(`live-session.${session.id}`);
            channel.stopListening('.slide.changed');
            channel.stopListening('.poll.broadcast');
            channel.stopListening('.session.state.changed');
        };
    }, [session.id]);

    return (
        <div className="live-lesson-container">
            <SyncIndicator connected={true} />
            
            <SlideViewer 
                slideId={currentSlideId} 
                locked={true} 
            />
            
            {pollActive && (
                <PollModal poll={pollActive} onRespond={handlePollResponse} />
            )}
            
            <InteractionToolbar 
                sessionId={session.id}
                onRaiseHand={handleRaiseHand}
                onAskQuestion={handleQuestion}
            />
            
            <ParticipantCounter count={participants.length} />
        </div>
    );
}
```

## 7.6 Agora Audio/Video Integration

### **Installation**
```bash
npm install agora-rtc-sdk-ng
```

### **Backend Token Generation**
```php
// app/Services/AgoraTokenService.php
namespace App\Services;

use RtcTokenBuilder2;

class AgoraTokenService
{
    public function generateToken(string $channelName, int $uid, string $role = 'publisher'): string
    {
        $appId = config('services.agora.app_id');
        $appCertificate = config('services.agora.app_certificate');
        
        $expireTimeInSeconds = 3600; // 1 hour
        $currentTimestamp = now()->timestamp;
        $privilegeExpireTs = $currentTimestamp + $expireTimeInSeconds;
        
        $token = RtcTokenBuilder2::buildTokenWithUid(
            $appId,
            $appCertificate,
            $channelName,
            $uid,
            $role,
            $privilegeExpireTs
        );
        
        return $token;
    }
}
```

### **Frontend Usage**
```jsx
// resources/js/teacher/components/LiveLesson/AgoraVideo.jsx
import AgoraRTC from 'agora-rtc-sdk-ng';
import { useEffect, useRef, useState } from 'react';

export default function AgoraVideo({ channelName, token, role }) {
    const client = useRef(null);
    const localAudioTrack = useRef(null);
    const localVideoTrack = useRef(null);
    const [remoteUsers, setRemoteUsers] = useState([]);

    useEffect(() => {
        // Initialize Agora client
        client.current = AgoraRTC.createClient({ mode: 'rtc', codec: 'vp8' });

        // Event handlers
        client.current.on('user-published', async (user, mediaType) => {
            await client.current.subscribe(user, mediaType);
            setRemoteUsers(prev => [...prev, user]);
        });

        client.current.on('user-unpublished', (user) => {
            setRemoteUsers(prev => prev.filter(u => u.uid !== user.uid));
        });

        // Join channel
        const join = async () => {
            await client.current.join(
                import.meta.env.VITE_AGORA_APP_ID,
                channelName,
                token,
                null
            );

            // Create local tracks
            if (role === 'teacher') {
                localAudioTrack.current = await AgoraRTC.createMicrophoneAudioTrack();
                localVideoTrack.current = await AgoraRTC.createCameraVideoTrack();
                await client.current.publish([
                    localAudioTrack.current,
                    localVideoTrack.current
                ]);
            } else {
                localAudioTrack.current = await AgoraRTC.createMicrophoneAudioTrack();
                await client.current.publish([localAudioTrack.current]);
            }
        };

        join();

        return () => {
            localAudioTrack.current?.close();
            localVideoTrack.current?.close();
            client.current?.leave();
        };
    }, [channelName, token, role]);

    return (
        <div className="agora-video-container">
            {/* Render video tracks */}
            <div id="local-player"></div>
            {remoteUsers.map(user => (
                <div key={user.uid} id={`remote-player-${user.uid}`}></div>
            ))}
        </div>
    );
}
```

---

# 8. AI INTEGRATION STRATEGY

## 8.1 Authoring AI Agents

### **OutlineGenerator**
```php
// app/Services/AI/Authoring/OutlineGenerator.php
namespace App\Services\AI\Authoring;

use OpenAI\Laravel\Facades\OpenAI;

class OutlineGenerator
{
    public function generateCourseOutline(string $title, string $subject, array $objectives): array
    {
        $prompt = "Generate a course outline for: {$title}
        Subject: {$subject}
        Learning Objectives: " . implode(', ', $objectives) . "
        
        Return JSON structure with modules and lessons.";
        
        $response = OpenAI::chat()->create([
            'model' => 'gpt-4-turbo',
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'response_format' => ['type' => 'json_object'],
        ]);
        
        return json_decode($response->choices[0]->message->content, true);
    }
}
```

### **TTSGenerator**
```php
// app/Services/AI/Authoring/TTSGenerator.php
namespace App\Services\AI\Authoring;

use OpenAI\Laravel\Facades\OpenAI;
use Illuminate\Support\Str;

class TTSGenerator
{
    public function generateAudioForText(string $text, string $voice = 'alloy'): string
    {
        $response = OpenAI::audio()->speech()->create([
            'model' => 'tts-1',
            'input' => $text,
            'voice' => $voice,
        ]);
        
        $filename = 'tts_' . Str::random(16) . '.mp3';
        $path = storage_path("app/public/audio/{$filename}");
        file_put_contents($path, $response);
        
        return "/storage/audio/{$filename}";
    }
}
```

### **AltTextGenerator**
```php
// app/Services/AI/Authoring/AltTextGenerator.php
namespace App\Services\AI\Authoring;

use OpenAI\Laravel\Facades\OpenAI;

class AltTextGenerator
{
    public function generateAltText(string $imageUrl): string
    {
        $response = OpenAI::chat()->create([
            'model' => 'gpt-4-vision-preview',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Generate descriptive alt text for this educational image. Be concise and accurate.'],
                        ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]],
                    ],
                ],
            ],
        ]);
        
        return $response->choices[0]->message->content;
    }
}
```

## 8.2 Runtime AI Agents

### **HintAgent**
```php
// app/Services/AI/Runtime/HintAgent.php
namespace App\Services\AI\Runtime;

use OpenAI\Laravel\Facades\OpenAI;

class HintAgent
{
    public function generateHint(
        string $lessonContext,
        string $slideContent,
        string $studentQuestion
    ): string {
        $prompt = "You are a helpful tutor for 10-12 year olds. A student is stuck on this concept:
        
        Lesson Context: {$lessonContext}
        Current Slide: {$slideContent}
        Student says: {$studentQuestion}
        
        Provide a helpful hint (not the answer) that guides them toward understanding.
        Keep it friendly and age-appropriate.";
        
        $response = OpenAI::chat()->create([
            'model' => 'gpt-4-turbo',
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'max_tokens' => 150,
        ]);
        
        return $response->choices[0]->message->content;
    }
}
```

### **UploadGradingAgent**
```php
// app/Services/AI/Runtime/UploadGradingAgent.php
namespace App\Services\AI\Runtime;

use App\Models\LessonUpload;
use OpenAI\Laravel\Facades\OpenAI;

class UploadGradingAgent
{
    public function analyzeUpload(LessonUpload $upload): array
    {
        if ($upload->file_type === 'image') {
            $ocrText = $this->performOCR($upload->file_path);
            
            $block = collect($upload->slide->blocks)->firstWhere('id', $upload->block_id);
            $rubric = $block['content']['rubric'] ?? null;
            
            $analysis = $this->analyzeWork($ocrText, $rubric);
            
            return [
                'ocr_text' => $ocrText,
                'detected_steps' => $analysis['steps'],
                'confidence' => $analysis['confidence'],
                'suggested_score' => $analysis['score'],
                'suggested_feedback' => $analysis['feedback'],
            ];
        }
        
        return [];
    }
    
    private function performOCR(string $imagePath): string
    {
        // Use Tesseract, Google Vision, or AWS Textract
        // Implementation depends on your chosen service
    }
    
    private function analyzeWork(string $text, ?array $rubric): array
    {
        $prompt = "Analyze this student work:\n{$text}\n\nRubric: " . json_encode($rubric);
        
        $response = OpenAI::chat()->create([
            'model' => 'gpt-4-turbo',
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);
        
        // Parse response and return structured analysis
        return [
            'steps' => [],
            'confidence' => 0.85,
            'score' => 7.5,
            'feedback' => $response->choices[0]->message->content,
        ];
    }
}
```

---

# 9. IMPLEMENTATION PHASES & TIMELINE

## Phase 1: Core Data Models & Migrations (Week 1)

### Tasks:
1. ✅ Create all new migration files
2. ✅ Create Eloquent models with relationships
3. ✅ Rename current Lesson → LiveSession
4. ✅ Create UidObserver for auto-generating UIDs
5. ✅ Seed sample data for testing
6. ✅ Create model factories

### Deliverables:
- All database tables created
- Models with proper relationships
- Seeders for demo data
- Unit tests for models

---

## Phase 2: Block Engine Foundation (Week 2-3)

### Tasks:
1. ✅ Create BlockRenderer service (backend)
2. ✅ Create BlockValidator service
3. ✅ Build React BlockEditor component
4. ✅ Implement drag-and-drop functionality
5. ✅ Create all 14 block type editors
6. ✅ Build block preview mode
7. ✅ Implement block serialization/deserialization

### Deliverables:
- Functional block editor UI
- All block types editable
- Block validation working
- Preview mode functional

---

## Phase 3: Authoring Interface (Week 3-4)

### Tasks:
1. ✅ Course CRUD pages
2. ✅ Module CRUD pages
3. ✅ Lesson CRUD pages
4. ✅ Slide editor with block palette
5. ✅ Slide reordering
6. ✅ AI assist integration:
   - Outline generator
   - TTS generator
   - Alt text generator
7. ✅ Template system (basic)

### Deliverables:
- Complete authoring workflow
- AI assists functional
- Teachers can create courses/lessons

---

## Phase 4: Self-Paced Runtime & Progress Tracking (Week 4-5)

### Tasks:
1. ✅ Lesson player component
2. ✅ Slide rail navigation
3. ✅ Block viewers for all types
4. ✅ Question block with bank integration
5. ✅ Progress tracking service
6. ✅ Slide interaction tracking
7. ✅ Question response tracking
8. ✅ Completion calculation
9. ✅ Help panel v1:
   - TTS player
   - AI chat
   - Hints

### Deliverables:
- Functional lesson player
- Progress tracking working
- Question responses saved
- Help panel accessible

---

## Phase 5: Live Lesson Infrastructure (Week 5-6)

### Tasks:
1. ✅ Install Laravel Reverb/WebSockets
2. ✅ Create broadcasting events
3. ✅ Set up channel authorization
4. ✅ Create LiveLessonSession management
5. ✅ Session code generation
6. ✅ Participant management
7. ✅ Frontend Echo setup

### Deliverables:
- WebSocket infrastructure working
- Session creation/joining functional
- Basic real-time sync operational

---

## Phase 6: Live Lesson UI - Teacher Portal (Week 6-7)

### Tasks:
1. ✅ Create teacher portal structure
2. ✅ Session control panel
3. ✅ Slide navigation controls
4. ✅ Participant list with status
5. ✅ Slide sync broadcasting
6. ✅ Interaction queue (raised hands, questions)
7. ✅ Poll creator
8. ✅ Basic whiteboard (optional)

### Deliverables:
- Teacher can control live sessions
- Students sync to teacher's slide
- Basic interactive features work

---

## Phase 7: Live Lesson UI - Student Portal (Week 7-8)

### Tasks:
1. ✅ Join with code interface
2. ✅ Synced slide viewer
3. ✅ Interaction toolbar
4. ✅ Raise hand functionality
5. ✅ Ask question functionality
6. ✅ Poll responder
7. ✅ Connection status indicator
8. ✅ Agora audio/video integration (basic)

### Deliverables:
- Students can join live sessions
- Real-time slide sync working
- Audio communication functional
- Interactive features accessible

---

## Phase 8: Uploads & Marking (Week 8-9)

### Tasks:
1. ✅ Upload block viewer/uploader
2. ✅ File upload service
3. ✅ Upload review interface
4. ✅ Annotation tool
5. ✅ Rubric scorer
6. ✅ AI OCR service
7. ✅ AI grading suggestions
8. ✅ Feedback delivery

### Deliverables:
- Students can upload work
- Teachers can review/grade uploads
- AI assists with grading
- Feedback delivered to students

---

## Phase 9: Analytics & Progress (Week 9-10)

### Tasks:
1. ✅ Lesson analytics dashboard
2. ✅ Slide engagement heatmaps
3. ✅ Question performance analytics
4. ✅ Learner progress views
5. ✅ Cohort dashboards
6. ✅ Time tracking analytics
7. ✅ Completion rate tracking

### Deliverables:
- Analytics dashboards functional
- Teachers can see student progress
- Identify difficult slides/questions
- Track time vs estimates

---

## Phase 10: Polish & Testing (Week 10-11)

### Tasks:
1. ✅ Comprehensive testing
2. ✅ Bug fixes
3. ✅ Performance optimization
4. ✅ Accessibility audit
5. ✅ Documentation updates
6. ✅ User training materials

### Deliverables:
- Production-ready system
- Documentation complete
- Training materials ready

---

# 10. CODE EXAMPLES & PATTERNS

## 10.1 Eloquent Models

### **Course Model**
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'uid', 'title', 'description',
        'cover_image', 'thumbnail', 'status', 'version',
        'change_log', 'metadata', 'created_by', 'updated_by',
        'is_global', 'source_organization_id',
    ];

    protected $casts = [
        'change_log' => 'array',
        'metadata' => 'array',
        'is_global' => 'boolean',
    ];

    // Relationships
    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function modules()
    {
        return $this->hasMany(Module::class)->orderBy('order_position');
    }

    public function assessments()
    {
        return $this->belongsToMany(Assessment::class, 'assessment_course')
                    ->withPivot('timing')
                    ->withTimestamps();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Scopes
    public function scopeLive($query)
    {
        return $query->where('status', 'live');
    }

    public function scopeForOrganization($query, int $orgId)
    {
        return $query->where(function ($q) use ($orgId) {
            $q->where('organization_id', $orgId)
              ->orWhere('is_global', true);
        });
    }

    // Methods
    public function publish()
    {
        $this->update(['status' => 'live']);
        $this->modules()->update(['status' => 'live']);
    }
}
```

### **Lesson Model**
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lesson extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'module_id', 'organization_id', 'uid', 'title',
        'description', 'order_position', 'lesson_type',
        'delivery_mode', 'status', 'metadata',
        'estimated_minutes', 'completion_rules',
        'enable_ai_help', 'enable_tts',
    ];

    protected $casts = [
        'metadata' => 'array',
        'completion_rules' => 'array',
        'enable_ai_help' => 'boolean',
        'enable_tts' => 'boolean',
    ];

    // Relationships
    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    public function slides()
    {
        return $this->hasMany(LessonSlide::class)->orderBy('order_position');
    }

    public function progress()
    {
        return $this->hasMany(LessonProgress::class);
    }

    public function assessments()
    {
        return $this->belongsToMany(Assessment::class, 'assessment_lesson')
                    ->withPivot('order_position', 'timing')
                    ->withTimestamps();
    }

    public function liveSessions()
    {
        return $this->hasMany(LiveLessonSession::class);
    }

    // Methods
    public function calculateProgress(Child $child): int
    {
        $progress = $this->progress()->where('child_id', $child->id)->first();
        return $progress ? $progress->completion_percentage : 0;
    }

    public function getTotalQuestions(): int
    {
        $count = 0;
        foreach ($this->slides as $slide) {
            foreach ($slide->blocks as $block) {
                if ($block['type'] === 'QuestionBlock') {
                    $count += count($block['content']['question_ids'] ?? []);
                }
            }
        }
        return $count;
    }
}
```

### **LessonProgress Model**
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LessonProgress extends Model
{
    protected $fillable = [
        'child_id', 'lesson_id', 'status', 'slides_viewed',
        'last_slide_id', 'completion_percentage', 'time_spent_seconds',
        'score', 'checks_passed', 'checks_total',
        'questions_attempted', 'questions_correct', 'questions_score',
        'uploads_submitted', 'uploads_required',
        'started_at', 'completed_at', 'last_accessed_at',
        'live_lesson_session_id',
    ];

    protected $casts = [
        'slides_viewed' => 'array',
        'score' => 'decimal:2',
        'questions_score' => 'decimal:2',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_accessed_at' => 'datetime',
    ];

    // Relationships
    public function child()
    {
        return $this->belongsTo(Child::class);
    }

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    public function questionResponses()
    {
        return $this->hasMany(LessonQuestionResponse::class);
    }

    public function liveSession()
    {
        return $this->belongsTo(LiveLessonSession::class, 'live_lesson_session_id');
    }

    // Methods
    public function markSlideViewed(int $slideId): void
    {
        $viewed = $this->slides_viewed ?? [];
        if (!in_array($slideId, $viewed)) {
            $viewed[] = $slideId;
            $this->update(['slides_viewed' => $viewed]);
            $this->updateCompletionPercentage();
        }
    }

    public function updateCompletionPercentage(): void
    {
        $totalSlides = $this->lesson->slides()->count();
        $viewedSlides = count($this->slides_viewed ?? []);
        
        $this->update([
            'completion_percentage' => $totalSlides > 0 
                ? round(($viewedSlides / $totalSlides) * 100) 
                : 0
        ]);
    }

    public function checkCompletion(): void
    {
        $rules = $this->lesson->completion_rules ?? [];
        
        $slideThreshold = $rules['slides_viewed_percentage'] ?? 100;
        $questionThreshold = $rules['questions_score_threshold'] ?? null;
        $uploadsRequired = $rules['uploads_required'] ?? false;
        
        $isComplete = true;
        
        // Check slides
        if ($this->completion_percentage < $slideThreshold) {
            $isComplete = false;
        }
        
        // Check questions
        if ($questionThreshold && $this->questions_score < $questionThreshold) {
            $isComplete = false;
        }
        
        // Check uploads
        if ($uploadsRequired && $this->uploads_submitted < $this->uploads_required) {
            $isComplete = false;
        }
        
        if ($isComplete && $this->status !== 'completed') {
            $this->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        }
    }
}
```

### **LessonQuestionResponse Model**
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LessonQuestionResponse extends Model
{
    protected $fillable = [
        'child_id', 'lesson_progress_id', 'slide_id', 'block_id',
        'question_id', 'answer_data', 'is_correct', 'score_earned',
        'score_possible', 'attempt_number', 'time_spent_seconds',
        'feedback', 'hints_used', 'answered_at',
    ];

    protected $casts = [
        'answer_data' => 'array',
        'is_correct' => 'boolean',
        'score_earned' => 'decimal:2',
        'score_possible' => 'decimal:2',
        'hints_used' => 'array',
        'answered_at' => 'datetime',
    ];

    // Relationships
    public function child()
    {
        return $this->belongsTo(Child::class);
    }

    public function lessonProgress()
    {
        return $this->belongsTo(LessonProgress::class);
    }

    public function slide()
    {
        return $this->belongsTo(LessonSlide::class);
    }

    public function question()
    {
        return $this->belongsTo(Question::class);
    }

    // Methods
    public function grade(): void
    {
        $question = $this->question;
        $answerData = $this->answer_data;
        
        // Grading logic based on question type
        switch ($question->question_type) {
            case 'mcq':
                $this->gradeMCQ($question, $answerData);
                break;
            case 'true_false':
                $this->gradeTrueFalse($question, $answerData);
                break;
            case 'short_answer':
                $this->gradeShortAnswer($question, $answerData);
                break;
            // Add other types
        }
        
        $this->save();
        
        // Update lesson progress
        $this->lessonProgress->increment('questions_attempted');
        if ($this->is_correct) {
            $this->lessonProgress->increment('questions_correct');
        }
        $this->lessonProgress->updateQuestionScore();
    }

    private function gradeMCQ($question, $answerData): void
    {
        $correctAnswer = $question->answer_schema['correct_answer'] ?? null;
        $selectedAnswer = $answerData['selected_option'] ?? null;
        
        $this->is_correct = $selectedAnswer === $correctAnswer;
        $this->score_earned = $this->is_correct ? $this->score_possible : 0;
    }
}
```

## 10.2 Controllers

### **LessonPlayerController**
```php
<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\LessonSlide;
use App\Models\LessonProgress;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LessonPlayerController extends Controller
{
    public function start(Request $request, Lesson $lesson)
    {
        $child = $request->user()->children()->firstOrFail();
        
        $progress = LessonProgress::firstOrCreate(
            ['child_id' => $child->id, 'lesson_id' => $lesson->id],
            [
                'status' => 'in_progress',
                'started_at' => now(),
                'last_accessed_at' => now(),
            ]
        );
        
        return redirect()->route('lessons.player', $lesson);
    }

    public function view(Request $request, Lesson $lesson)
    {
        $child = $request->user()->children()->firstOrFail();
        
        $progress = LessonProgress::where('child_id', $child->id)
                                   ->where('lesson_id', $lesson->id)
                                   ->firstOrFail();
        
        $lesson->load(['slides' => function ($query) {
            $query->orderBy('order_position');
        }]);
        
        return Inertia::render('@parent/Lessons/Player', [
            'lesson' => $lesson,
            'progress' => $progress,
        ]);
    }

    public function getSlide(Request $request, Lesson $lesson, LessonSlide $slide)
    {
        // Load questions for QuestionBlocks
        $blocks = $slide->blocks;
        foreach ($blocks as &$block) {
            if ($block['type'] === 'QuestionBlock') {
                $questionIds = $block['content']['question_ids'] ?? [];
                $block['content']['questions'] = Question::whereIn('id', $questionIds)->get();
            }
        }
        
        return response()->json([
            'slide' => array_merge($slide->toArray(), ['blocks' => $blocks]),
        ]);
    }

    public function recordSlideView(Request $request, Lesson $lesson, LessonSlide $slide)
    {
        $child = $request->user()->children()->firstOrFail();
        
        $progress = LessonProgress::where('child_id', $child->id)
                                   ->where('lesson_id', $lesson->id)
                                   ->firstOrFail();
        
        $progress->markSlideViewed($slide->id);
        $progress->update(['last_accessed_at' => now()]);
        
        return response()->json(['success' => true]);
    }
}
```

### **LessonQuestionController**
```php
<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\LessonSlide;
use App\Models\LessonQuestionResponse;
use Illuminate\Http\Request;

class LessonQuestionController extends Controller
{
    public function submitResponse(Request $request, Lesson $lesson, LessonSlide $slide)
    {
        $validated = $request->validate([
            'block_id' => 'required|string',
            'question_id' => 'required|exists:questions,id',
            'answer_data' => 'required|array',
            'time_spent_seconds' => 'required|integer',
        ]);
        
        $child = $request->user()->children()->firstOrFail();
        
        $progress = LessonProgress::where('child_id', $child->id)
                                   ->where('lesson_id', $lesson->id)
                                   ->firstOrFail();
        
        // Check for existing attempts
        $existingAttempts = LessonQuestionResponse::where('child_id', $child->id)
            ->where('lesson_progress_id', $progress->id)
            ->where('question_id', $validated['question_id'])
            ->count();
        
        $response = LessonQuestionResponse::create([
            'child_id' => $child->id,
            'lesson_progress_id' => $progress->id,
            'slide_id' => $slide->id,
            'block_id' => $validated['block_id'],
            'question_id' => $validated['question_id'],
            'answer_data' => $validated['answer_data'],
            'score_possible' => Question::find($validated['question_id'])->marks,
            'attempt_number' => $existingAttempts + 1,
            'time_spent_seconds' => $validated['time_spent_seconds'],
            'answered_at' => now(),
        ]);
        
        // Grade the response
        $response->grade();
        
        return response()->json([
            'response' => $response,
            'is_correct' => $response->is_correct,
            'feedback' => $response->feedback,
        ]);
    }
}
```

## 10.3 React Components

### **LessonPlayer Component**
```jsx
// resources/js/parent/Pages/Lessons/Player.jsx
import React, { useState, useEffect } from 'react';
import SlideViewer from '@/parent/components/LessonPlayer/SlideViewer';
import SlideRail from '@/parent/components/LessonPlayer/SlideRail';
import HelpPanel from '@/parent/components/LessonPlayer/HelpPanel';
import ProgressBar from '@/parent/components/LessonPlayer/ProgressBar';

export default function LessonPlayer({ lesson, progress }) {
    const [currentSlideIndex, setCurrentSlideIndex] = useState(0);
    const [currentSlide, setCurrentSlide] = useState(null);
    const [helpPanelOpen, setHelpPanelOpen] = useState(false);

    useEffect(() => {
        loadSlide(currentSlideIndex);
    }, [currentSlideIndex]);

    const loadSlide = async (index) => {
        const slide = lesson.slides[index];
        const response = await axios.get(`/lessons/${lesson.id}/slides/${slide.id}`);
        setCurrentSlide(response.data.slide);
        
        // Record slide view
        await axios.post(`/lessons/${lesson.id}/slides/${slide.id}/view`);
    };

    const handleNext = () => {
        if (currentSlideIndex < lesson.slides.length - 1) {
            setCurrentSlideIndex(currentSlideIndex + 1);
        } else {
            // Lesson complete
            handleComplete();
        }
    };

    const handlePrevious = () => {
        if (currentSlideIndex > 0) {
            setCurrentSlideIndex(currentSlideIndex - 1);
        }
    };

    const handleComplete = async () => {
        await axios.post(`/lessons/${lesson.id}/complete`);
        window.location.href = `/lessons/${lesson.id}/summary`;
    };

    return (
        <div className="lesson-player-container">
            <ProgressBar 
                current={currentSlideIndex + 1} 
                total={lesson.slides.length}
                percentage={progress.completion_percentage}
            />
            
            <div className="lesson-content">
                <SlideRail 
                    slides={lesson.slides}
                    currentIndex={currentSlideIndex}
                    onSlideClick={setCurrentSlideIndex}
                />
                
                <div className="main-viewer">
                    {currentSlide && (
                        <SlideViewer 
                            slide={currentSlide}
                            lessonId={lesson.id}
                            onNext={handleNext}
                            onPrevious={handlePrevious}
                        />
                    )}
                </div>
                
                <button 
                    className="help-button"
                    onClick={() => setHelpPanelOpen(!helpPanelOpen)}
                >
                    Help
                </button>
            </div>
            
            {helpPanelOpen && (
                <HelpPanel 
                    lesson={lesson}
                    slide={currentSlide}
                    onClose={() => setHelpPanelOpen(false)}
                />
            )}
        </div>
    );
}
```

### **QuestionBlock Viewer**
```jsx
// resources/js/parent/components/LessonPlayer/blocks/QuestionBlockViewer.jsx
import React, { useState } from 'react';
import QuestionRenderer from './QuestionRenderer';

export default function QuestionBlockViewer({ block, lessonId, slideId }) {
    const [currentQuestionIndex, setCurrentQuestionIndex] = useState(0);
    const [responses, setResponses] = useState({});
    
    const questions = block.content.questions || [];
    const displayMode = block.content.display_mode;
    
    const handleSubmitAnswer = async (questionId, answerData) => {
        const response = await axios.post(
            `/lessons/${lessonId}/slides/${slideId}/question-response`,
            {
                block_id: block.id,
                question_id: questionId,
                answer_data: answerData,
                time_spent_seconds: 30, // Track this
            }
        );
        
        setResponses({
            ...responses,
            [questionId]: response.data,
        });
        
        // Move to next question if one_at_a_time
        if (displayMode === 'one_at_a_time' && currentQuestionIndex < questions.length - 1) {
            setCurrentQuestionIndex(currentQuestionIndex + 1);
        }
    };

    if (displayMode === 'one_at_a_time') {
        const currentQuestion = questions[currentQuestionIndex];
        
        return (
            <div className="question-block">
                <div className="question-counter">
                    Question {currentQuestionIndex + 1} of {questions.length}
                </div>
                
                <QuestionRenderer 
                    question={currentQuestion}
                    onSubmit={handleSubmitAnswer}
                    response={responses[currentQuestion.id]}
                    settings={block.content}
                />
            </div>
        );
    }
    
    // all_at_once mode
    return (
        <div className="question-block-all">
            {questions.map((question, index) => (
                <div key={question.id} className="question-item">
                    <div className="question-number">Question {index + 1}</div>
                    <QuestionRenderer 
                        question={question}
                        onSubmit={handleSubmitAnswer}
                        response={responses[question.id]}
                        settings={block.content}
                    />
                </div>
            ))}
        </div>
    );
}
```

---

# 11. TESTING STRATEGY

## 11.1 Unit Tests

### **Model Tests**
```php
// tests/Unit/Models/LessonProgressTest.php
namespace Tests\Unit\Models;

use Tests\TestCase;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Child;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LessonProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_marks_slide_as_viewed()
    {
        $progress = LessonProgress::factory()->create();
        
        $progress->markSlideViewed(1);
        
        $this->assertContains(1, $progress->slides_viewed);
    }

    public function test_calculates_completion_percentage()
    {
        $lesson = Lesson::factory()->create();
        LessonSlide::factory()->count(10)->create(['lesson_id' => $lesson->id]);
        
        $progress = LessonProgress::factory()->create([
            'lesson_id' => $lesson->id,
            'slides_viewed' => [1, 2, 3, 4, 5],
        ]);
        
        $progress->updateCompletionPercentage();
        
        $this->assertEquals(50, $progress->completion_percentage);
    }
}
```

## 11.2 Feature Tests

### **Lesson Player Tests**
```php
// tests/Feature/LessonPlayerTest.php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Child;
use App\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LessonPlayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_start_lesson()
    {
        $user = User::factory()->create(['role' => 'parent']);
        $child = Child::factory()->create(['user_id' => $user->id]);
        $lesson = Lesson::factory()->create();
        
        $response = $this->actingAs($user)
            ->post("/lessons/{$lesson->id}/start");
        
        $response->assertRedirect("/lessons/{$lesson->id}/player");
        $this->assertDatabaseHas('lesson_progress', [
            'child_id' => $child->id,
            'lesson_id' => $lesson->id,
            'status' => 'in_progress',
        ]);
    }
}
```

## 11.3 Browser Tests (Laravel Dusk)

```php
// tests/Browser/LessonPlayerTest.php
namespace Tests\Browser;

use Tests\DuskTestCase;
use App\Models\User;
use App\Models\Lesson;

class LessonPlayerTest extends DuskTestCase
{
    public function test_user_can_navigate_through_lesson()
    {
        $user = User::factory()->create();
        $lesson = Lesson::factory()->create();
        
        $this->browse(function ($browser) use ($user, $lesson) {
            $browser->loginAs($user)
                    ->visit("/lessons/{$lesson->id}/player")
                    ->assertSee($lesson->title)
                    ->click('@next-button')
                    ->waitForText('Slide 2')
                    ->assertSee('Slide 2');
        });
    }
}
```

---

# 12. DEPLOYMENT CONSIDERATIONS

## 12.1 Server Requirements

- **PHP**: 8.2+
- **Laravel**: 11.x
- **Node.js**: 18+
- **Database**: MySQL 8.0+ or PostgreSQL 14+
- **Redis**: 6.0+ (for queues and broadcasting)
- **WebSocket Server**: Laravel Reverb or compatible
- **Storage**: S3-compatible (AWS S3, MinIO, DigitalOcean Spaces)

## 12.2 Environment Variables

```env
# Application
APP_NAME="Lesson System"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password

# Broadcasting
BROADCAST_DRIVER=reverb
REVERB_APP_ID=your-app-id
REVERB_APP_KEY=your-app-key
REVERB_APP_SECRET=your-app-secret
REVERB_HOST=0.0.0.0
REVERB_PORT=8080
REVERB_SCHEME=https

# Queue
QUEUE_CONNECTION=redis

# OpenAI
OPENAI_API_KEY=your-openai-key

# Agora
AGORA_APP_ID=your-agora-app-id
AGORA_APP_CERTIFICATE=your-agora-certificate

# Storage
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=your-key
AWS_SECRET_ACCESS_KEY=your-secret
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=your-bucket
```

## 12.3 Deployment Checklist

- [ ] Run migrations: `php artisan migrate --force`
- [ ] Compile assets: `npm run build`
- [ ] Cache config: `php artisan config:cache`
- [ ] Cache routes: `php artisan route:cache`
- [ ] Cache views: `php artisan view:cache`
- [ ] Start queue workers: `php artisan queue:work --daemon`
- [ ] Start Reverb server: `php artisan reverb:start`
- [ ] Set up supervisor for queues
- [ ] Configure HTTPS/SSL
- [ ] Set up CDN for assets
- [ ] Configure backups

## 12.4 Performance Optimization

1. **Database Indexing**: Ensure all foreign keys and frequently queried columns are indexed
2. **Caching**: Use Redis for session, cache, and broadcast drivers
3. **CDN**: Serve static assets (images, videos, audio) via CDN
4. **Lazy Loading**: Implement pagination and lazy loading for large datasets
5. **Queue Jobs**: Offload heavy operations (AI calls, file processing) to queues
6. **Horizon**: Use Laravel Horizon for queue monitoring

---

# APPENDIX

## A. Migration Order

Run migrations in this order to avoid foreign key errors:

1. Organizations (already exists)
2. Courses
3. Modules
4. Lessons (new)
5. LessonSlides
6. LessonProgress
7. LessonQuestionResponses
8. SlideInteractions
9. LessonUploads
10. LiveLessonSessions
11. LiveSessionParticipants
12. LiveSlideInteractions
13. Rename lessons → live_sessions
14. Junction tables (assessment_lesson, etc.)

## B. Model Factory Examples

```php
// database/factories/LessonFactory.php
namespace Database\Factories;

use App\Models\Module;
use Illuminate\Database\Eloquent\Factories\Factory;

class LessonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'module_id' => Module::factory(),
            'organization_id' => 1,
            'uid' => $this->faker->unique()->uuid,
            'title' => $this->faker->sentence,
            'description' => $this->faker->paragraph,
            'order_position' => 0,
            'lesson_type' => 'interactive',
            'delivery_mode' => 'self_paced',
            'status' => 'live',
            'estimated_minutes' => $this->faker->numberBetween(15, 60),
            'enable_ai_help' => true,
            'enable_tts' => true,
        ];
    }
}
```

## C. Seeder Example

```php
// database/seeders/LessonSystemSeeder.php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Course;
use App\Models\Module;
use App\Models\Lesson;
use App\Models\LessonSlide;

class LessonSystemSeeder extends Seeder
{
    public function run(): void
    {
        $course = Course::create([
            'organization_id' => 1,
            'title' => 'Year 5 Mathematics',
            'description' => 'Complete mathematics curriculum for Year 5',
            'status' => 'live',
        ]);

        $module = Module::create([
            'course_id' => $course->id,
            'organization_id' => 1,
            'title' => 'Fractions and Decimals',
            'description' => 'Understanding fractions, decimals, and their relationships',
            'order_position' => 1,
            'status' => 'live',
        ]);

        $lesson = Lesson::create([
            'module_id' => $module->id,
            'organization_id' => 1,
            'title' => 'Introduction to Fractions',
            'description' => 'Learn what fractions are and how to represent them',
            'order_position' => 1,
            'lesson_type' => 'interactive',
            'delivery_mode' => 'self_paced',
            'status' => 'live',
            'estimated_minutes' => 30,
        ]);

        LessonSlide::create([
            'lesson_id' => $lesson->id,
            'title' => 'What is a Fraction?',
            'order_position' => 1,
            'blocks' => [
                [
                    'id' => 'block-1',
                    'type' => 'text',
                    'order' => 1,
                    'content' => [
                        'html' => '<p>A <strong>fraction</strong> represents part of a whole.</p>',
                        'plain_text' => 'A fraction represents part of a whole.',
                        'reading_level' => 5,
                        'estimated_read_seconds' => 10,
                        'language' => 'en',
                    ],
                    'settings' => [
                        'font_size' => 'medium',
                        'show_audio_player' => true,
                        'visible' => true,
                        'locked' => false,
                    ],
                ],
            ],
        ]);
    }
}
```

---

# END OF DOCUMENT

**Document Version:** 1.0  
**Last Updated:** October 13, 2025  
**Status:** Ready for Implementation

This comprehensive plan provides a complete roadmap for implementing the block-based interactive lesson system with live synchronization, question bank integration, and AI-powered features.

For questions or clarifications, please refer to the specific sections above or contact the development team.
