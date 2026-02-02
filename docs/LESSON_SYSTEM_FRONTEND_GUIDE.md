# Lesson System Frontend Implementation Guide

**Date:** October 13, 2025  
**Status:** 🔄 IN PROGRESS  
**Phase:** 3 - Frontend Development

---

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Component Structure](#component-structure)
4. [Data Flow](#data-flow)
5. [Student Lesson Player](#student-lesson-player)
6. [Admin Block Editor](#admin-block-editor)
7. [Block Types Reference](#block-types-reference)
8. [API Integration](#api-integration)
9. [State Management](#state-management)
10. [UI/UX Guidelines](#uiux-guidelines)
11. [Implementation Order](#implementation-order)

---

## Overview

### Goal
Create an interactive, user-friendly frontend for the block-based lesson system with emphasis on:
- **Interactive lesson player** for students
- **Intuitive block editor** for content creators
- **Real-time progress tracking**
- **Responsive, modern design**

### Tech Stack
- **React 18+** with Hooks
- **Inertia.js** for server-side rendering
- **Tailwind CSS** for styling
- **Framer Motion** for animations
- **Heroicons** for icons
- **Axios** for API calls

### Directory Structure

```
resources/js/
├── parent/
│   ├── Pages/
│   │   └── ContentLessons/           # NEW - Block-based lessons
│   │       ├── Browse.jsx            # Course browser
│   │       ├── Player.jsx            # Main lesson player
│   │       └── Summary.jsx           # Completion summary
│   └── components/
│       └── LessonPlayer/             # NEW - Player components
│           ├── SlideRenderer.jsx     # Main slide display
│           ├── ProgressTracker.jsx   # Progress sidebar
│           ├── HelpPanel.jsx         # TTS/AI help
│           ├── NavigationControls.jsx
│           └── blocks/               # Block type components
│               ├── TextBlock.jsx
│               ├── ImageBlock.jsx
│               ├── VideoBlock.jsx
│               ├── QuestionBlock.jsx
│               ├── UploadBlock.jsx
│               └── ... (14 total)
├── admin/
│   ├── Pages/
│   │   └── ContentManagement/        # NEW - Content authoring
│   │       ├── Courses/
│   │       │   ├── Index.jsx
│   │       │   ├── Create.jsx
│   │       │   └── Edit.jsx
│   │       ├── Modules/
│   │       │   ├── Index.jsx
│   │       │   └── Edit.jsx
│   │       └── Lessons/
│   │           ├── Index.jsx
│   │           └── SlideEditor.jsx   # Main editor
│   └── components/
│       └── BlockEditor/              # NEW - Editor components
│           ├── BlockPalette.jsx      # Drag-drop block picker
│           ├── BlockSettings.jsx     # Block configuration
│           ├── SlideCanvas.jsx       # Drag-drop canvas
│           └── PreviewMode.jsx       # Live preview
└── contexts/
    └── LessonPlayerContext.jsx       # NEW - Global player state
```

---

## Architecture

### Component Hierarchy

```
Student View:
ContentLessons/Browse.jsx
  └── CourseCard components
  
ContentLessons/Player.jsx
  ├── ProgressTracker (left sidebar)
  ├── SlideRenderer (center)
  │   ├── Block components (dynamic)
  │   └── NavigationControls (bottom)
  └── HelpPanel (right sidebar - toggleable)
  
ContentLessons/Summary.jsx
  └── Analytics & completion data

Admin View:
ContentManagement/Courses/Index.jsx
  └── Course list with actions
  
ContentManagement/Lessons/SlideEditor.jsx
  ├── BlockPalette (left)
  ├── SlideCanvas (center)
  │   └── Draggable block components
  └── BlockSettings (right)
```

### Data Flow Pattern

```
1. Page Load (Inertia)
   Controller → Inertia → Page Component
   
2. Slide Navigation
   Component → API Call → Update State → Re-render
   
3. Block Interaction
   Block Component → Handler → API → Progress Update
   
4. Progress Tracking
   Local State + Debounced API Updates
```

---

## Student Lesson Player

### 1. Browse Page (`ContentLessons/Browse.jsx`)

**Purpose:** Display available courses for students

**Props (from Inertia):**
```javascript
{
  courses: [
    {
      id, uid, title, description,
      estimated_minutes, thumbnail,
      modules_count, lessons_count,
      completion_percentage
    }
  ]
}
```

**Features:**
- Grid layout with course cards
- Search and filter
- Progress indicators
- "Start" or "Continue" buttons
- Responsive design (mobile-first)

**UI Components:**
- Course cards with hover effects
- Progress rings (Framer Motion)
- Filter sidebar
- Search bar with live results

---

### 2. Lesson Player (`ContentLessons/Player.jsx`) 🎯 **PRIORITY**

**Purpose:** Main interactive lesson player with slides

**Props (from Inertia):**
```javascript
{
  lesson: {
    id, uid, title, description,
    lesson_type, estimated_minutes,
    enable_ai_help, enable_tts,
    slides: [
      { id, uid, title, order_position }
    ]
  },
  progress: {
    id, status, slides_viewed,
    last_slide_id, completion_percentage,
    time_spent_seconds, questions_attempted,
    questions_correct, questions_score
  }
}
```

**State Management:**
```javascript
const [currentSlideIndex, setCurrentSlideIndex] = useState(0);
const [slideData, setSlideData] = useState(null);
const [loading, setLoading] = useState(false);
const [showHelp, setShowHelp] = useState(false);
const [progress, setProgress] = useState(props.progress);
```

**Key Functions:**
```javascript
// Load slide with blocks
const loadSlide = async (slideId) => {
  const response = await axios.get(
    route('parent.lessons.slides.get', { lesson, slide: slideId })
  );
  setSlideData(response.data.slide);
  recordSlideView(slideId);
};

// Record slide view
const recordSlideView = async (slideId) => {
  await axios.post(
    route('parent.lessons.slides.view', { lesson, slide: slideId })
  );
};

// Navigate slides
const nextSlide = () => {
  if (currentSlideIndex < slides.length - 1) {
    setCurrentSlideIndex(prev => prev + 1);
  }
};

const prevSlide = () => {
  if (currentSlideIndex > 0) {
    setCurrentSlideIndex(prev => prev - 1);
  }
};

// Track time
useEffect(() => {
  const interval = setInterval(() => {
    // Update time spent every 10 seconds
    axios.post(route('parent.lessons.progress.update', lesson), {
      time_spent_seconds: 10,
      slide_id: currentSlide.id
    });
  }, 10000);
  
  return () => clearInterval(interval);
}, [currentSlide]);
```

**Layout:**
```jsx
<div className="flex h-screen">
  {/* Left: Progress Tracker */}
  <ProgressTracker 
    slides={slides}
    currentIndex={currentSlideIndex}
    progress={progress}
    onSlideClick={(index) => setCurrentSlideIndex(index)}
  />
  
  {/* Center: Slide Display */}
  <main className="flex-1 overflow-y-auto">
    <SlideRenderer 
      slide={slideData}
      onInteraction={handleInteraction}
    />
    
    <NavigationControls 
      onPrev={prevSlide}
      onNext={nextSlide}
      canPrev={currentSlideIndex > 0}
      canNext={currentSlideIndex < slides.length - 1}
    />
  </main>
  
  {/* Right: Help Panel (toggleable) */}
  {showHelp && (
    <HelpPanel 
      lessonId={lesson.id}
      slideId={currentSlide.id}
      enableTTS={lesson.enable_tts}
      enableAI={lesson.enable_ai_help}
    />
  )}
</div>
```

---

### 3. Slide Renderer (`components/LessonPlayer/SlideRenderer.jsx`)

**Purpose:** Dynamically render blocks based on type

**Props:**
```javascript
{
  slide: {
    id, title, blocks: [
      {
        id, type, order, content, settings, metadata
      }
    ]
  },
  onInteraction: (type, data) => {}
}
```

**Implementation:**
```jsx
const blockComponents = {
  text: TextBlock,
  image: ImageBlock,
  video: VideoBlock,
  audio: AudioBlock,
  callout: CalloutBlock,
  QuestionBlock: QuestionBlock,
  UploadBlock: UploadBlock,
  embed: EmbedBlock,
  timer: TimerBlock,
  reflection: ReflectionBlock,
  whiteboard: WhiteboardBlock,
  code: CodeBlock,
  table: TableBlock,
  divider: DividerBlock,
};

return (
  <div className="max-w-4xl mx-auto p-6 space-y-6">
    <h1 className="text-3xl font-bold">{slide.title}</h1>
    
    {slide.blocks
      .sort((a, b) => a.order - b.order)
      .map(block => {
        const BlockComponent = blockComponents[block.type];
        return (
          <motion.div
            key={block.id}
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
          >
            <BlockComponent 
              block={block}
              onInteraction={(data) => 
                onInteraction(block.type, data)
              }
            />
          </motion.div>
        );
      })}
  </div>
);
```

---

### 4. Progress Tracker (`components/LessonPlayer/ProgressTracker.jsx`)

**Purpose:** Show lesson progress and slide navigation

**UI:**
```jsx
<aside className="w-64 bg-white border-r border-gray-200 overflow-y-auto">
  <div className="p-4">
    {/* Overall Progress */}
    <div className="mb-6">
      <h3 className="text-lg font-semibold mb-2">Progress</h3>
      <div className="relative pt-1">
        <div className="flex mb-2 items-center justify-between">
          <span className="text-xs font-semibold inline-block text-blue-600">
            {progress.completion_percentage}%
          </span>
        </div>
        <div className="overflow-hidden h-2 mb-4 text-xs flex rounded bg-blue-200">
          <motion.div 
            style={{ width: `${progress.completion_percentage}%` }}
            className="shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center bg-blue-600"
            initial={{ width: 0 }}
            animate={{ width: `${progress.completion_percentage}%` }}
          />
        </div>
      </div>
    </div>
    
    {/* Slide List */}
    <div className="space-y-2">
      {slides.map((slide, index) => {
        const isViewed = progress.slides_viewed.includes(slide.id);
        const isCurrent = index === currentIndex;
        
        return (
          <button
            key={slide.id}
            onClick={() => onSlideClick(index)}
            className={`w-full text-left p-3 rounded-lg transition-colors ${
              isCurrent 
                ? 'bg-blue-100 border-2 border-blue-500' 
                : isViewed 
                ? 'bg-green-50 border border-green-200' 
                : 'bg-gray-50 border border-gray-200'
            }`}
          >
            <div className="flex items-center">
              <div className="mr-3">
                {isViewed ? (
                  <CheckCircleIcon className="h-5 w-5 text-green-600" />
                ) : (
                  <div className="h-5 w-5 rounded-full border-2 border-gray-300" />
                )}
              </div>
              <span className={`text-sm ${isCurrent ? 'font-semibold' : ''}`}>
                {index + 1}. {slide.title}
              </span>
            </div>
          </button>
        );
      })}
    </div>
  </div>
</aside>
```

---

### 5. Help Panel (`components/LessonPlayer/HelpPanel.jsx`)

**Purpose:** Provide TTS, AI chat, hints, and glossary

**Features:**
- Text-to-Speech for slide content
- AI chat assistant
- Contextual hints
- Glossary lookup
- Transcript viewer

**UI Tabs:**
```jsx
<div className="w-80 bg-white border-l border-gray-200 flex flex-col h-full">
  <div className="border-b border-gray-200">
    <nav className="flex">
      <button className={tabClass('tts')}>
        <SpeakerWaveIcon /> TTS
      </button>
      <button className={tabClass('chat')}>
        <ChatBubbleLeftRightIcon /> Chat
      </button>
      <button className={tabClass('hints')}>
        <LightBulbIcon /> Hints
      </button>
    </nav>
  </div>
  
  <div className="flex-1 overflow-y-auto p-4">
    {activeTab === 'tts' && <TTSPanel />}
    {activeTab === 'chat' && <AIChatPanel />}
    {activeTab === 'hints' && <HintsPanel />}
  </div>
</div>
```

---

## Block Types Reference

### 1. TextBlock

**Content Structure:**
```json
{
  "text": "Rich text content with **markdown**",
  "format": "markdown",
  "math_enabled": true
}
```

**Component:**
```jsx
const TextBlock = ({ block }) => {
  return (
    <div className="prose max-w-none">
      <ReactMarkdown>{block.content.text}</ReactMarkdown>
    </div>
  );
};
```

---

### 2. ImageBlock

**Content Structure:**
```json
{
  "url": "/storage/images/...",
  "alt": "Description",
  "caption": "Image caption",
  "width": "full",
  "alignment": "center"
}
```

**Component:**
```jsx
const ImageBlock = ({ block }) => {
  return (
    <figure className="my-6">
      <img 
        src={block.content.url}
        alt={block.content.alt}
        className="rounded-lg shadow-md"
      />
      {block.content.caption && (
        <figcaption className="text-center text-gray-600 mt-2">
          {block.content.caption}
        </figcaption>
      )}
    </figure>
  );
};
```

---

### 3. VideoBlock

**Content Structure:**
```json
{
  "url": "https://youtube.com/...",
  "type": "youtube",
  "autoplay": false,
  "controls": true,
  "caption": "Video description"
}
```

**Component:**
```jsx
const VideoBlock = ({ block }) => {
  return (
    <div className="my-6">
      <div className="aspect-w-16 aspect-h-9">
        <iframe 
          src={block.content.url}
          allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
          allowFullScreen
          className="rounded-lg"
        />
      </div>
      {block.content.caption && (
        <p className="text-center text-gray-600 mt-2">
          {block.content.caption}
        </p>
      )}
    </div>
  );
};
```

---

### 4. QuestionBlock 🎯 **COMPLEX**

**Content Structure:**
```json
{
  "question_ids": [1, 2, 3],
  "questions": [...],  // Loaded from API
  "immediate_feedback": true,
  "retry_allowed": true,
  "max_attempts": 3,
  "show_solution": true
}
```

**Component:**
```jsx
const QuestionBlock = ({ block, onInteraction }) => {
  const [answers, setAnswers] = useState({});
  const [submitted, setSubmitted] = useState({});
  const [results, setResults] = useState({});
  
  const handleSubmit = async (questionId) => {
    const response = await axios.post(
      route('parent.lessons.questions.submit'),
      {
        block_id: block.id,
        question_id: questionId,
        answer_data: answers[questionId],
        time_spent_seconds: 30
      }
    );
    
    setResults(prev => ({
      ...prev,
      [questionId]: response.data.response
    }));
    setSubmitted(prev => ({ ...prev, [questionId]: true }));
    
    onInteraction({ type: 'question_answered', data: response.data });
  };
  
  return (
    <div className="bg-blue-50 rounded-lg p-6 my-6">
      <h3 className="text-xl font-semibold mb-4 flex items-center">
        <QuestionMarkCircleIcon className="h-6 w-6 mr-2 text-blue-600" />
        Practice Questions
      </h3>
      
      {block.content.questions.map((question, index) => (
        <div key={question.id} className="bg-white rounded-lg p-4 mb-4">
          <p className="font-medium mb-3">
            {index + 1}. {question.question_text}
          </p>
          
          {/* Render question type-specific inputs */}
          {question.question_type === 'multiple_choice' && (
            <div className="space-y-2">
              {question.options.map(option => (
                <label key={option.id} className="flex items-center">
                  <input 
                    type="radio"
                    name={`question-${question.id}`}
                    value={option.id}
                    onChange={(e) => setAnswers({
                      ...answers,
                      [question.id]: e.target.value
                    })}
                    disabled={submitted[question.id]}
                    className="mr-2"
                  />
                  {option.text}
                </label>
              ))}
            </div>
          )}
          
          {/* Feedback */}
          {submitted[question.id] && results[question.id] && (
            <div className={`mt-3 p-3 rounded ${
              results[question.id].is_correct 
                ? 'bg-green-100 text-green-800' 
                : 'bg-red-100 text-red-800'
            }`}>
              <p className="font-semibold">
                {results[question.id].is_correct ? '✓ Correct!' : '✗ Incorrect'}
              </p>
              {results[question.id].feedback && (
                <p className="mt-1">{results[question.id].feedback}</p>
              )}
            </div>
          )}
          
          {/* Submit button */}
          {!submitted[question.id] && (
            <button
              onClick={() => handleSubmit(question.id)}
              disabled={!answers[question.id]}
              className="mt-3 px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50"
            >
              Submit Answer
            </button>
          )}
        </div>
      ))}
    </div>
  );
};
```

---

### 5. UploadBlock 🎯 **COMPLEX**

**Content Structure:**
```json
{
  "title": "Upload your work",
  "description": "Instructions...",
  "accepted_types": ["image", "pdf"],
  "max_size_mb": 10,
  "required": true,
  "rubric": {...}
}
```

**Component:**
```jsx
const UploadBlock = ({ block, lessonId, slideId, onInteraction }) => {
  const [file, setFile] = useState(null);
  const [uploading, setUploading] = useState(false);
  const [uploaded, setUploaded] = useState(null);
  
  const handleUpload = async () => {
    setUploading(true);
    
    const formData = new FormData();
    formData.append('file', file);
    formData.append('block_id', block.id);
    formData.append('file_type', file.type.startsWith('image/') ? 'image' : 'document');
    
    try {
      const response = await axios.post(
        route('parent.lessons.upload.submit', { lesson: lessonId, slide: slideId }),
        formData,
        { headers: { 'Content-Type': 'multipart/form-data' } }
      );
      
      setUploaded(response.data.upload);
      onInteraction({ type: 'file_uploaded', data: response.data });
    } catch (error) {
      alert('Upload failed');
    } finally {
      setUploading(false);
    }
  };
  
  return (
    <div className="bg-purple-50 rounded-lg p-6 my-6">
      <h3 className="text-xl font-semibold mb-2 flex items-center">
        <ArrowUpTrayIcon className="h-6 w-6 mr-2 text-purple-600" />
        {block.content.title}
      </h3>
      
      {block.content.description && (
        <p className="text-gray-600 mb-4">{block.content.description}</p>
      )}
      
      {!uploaded ? (
        <div className="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center">
          <input
            type="file"
            id={`file-${block.id}`}
            onChange={(e) => setFile(e.target.files[0])}
            className="hidden"
            accept={block.content.accepted_types.map(t => `.${t}`).join(',')}
          />
          <label htmlFor={`file-${block.id}`} className="cursor-pointer">
            <CloudArrowUpIcon className="h-12 w-12 text-gray-400 mx-auto mb-2" />
            <p className="text-gray-600">
              {file ? file.name : 'Click to select file'}
            </p>
          </label>
          
          {file && (
            <button
              onClick={handleUpload}
              disabled={uploading}
              className="mt-4 px-6 py-2 bg-purple-600 text-white rounded hover:bg-purple-700 disabled:opacity-50"
            >
              {uploading ? 'Uploading...' : 'Upload File'}
            </button>
          )}
        </div>
      ) : (
        <div className="bg-green-100 border border-green-300 rounded-lg p-4">
          <p className="text-green-800 font-semibold">✓ File uploaded successfully!</p>
          <p className="text-sm text-green-700 mt-1">
            Status: {uploaded.status}
          </p>
        </div>
      )}
    </div>
  );
};
```

---

## Admin Block Editor

### 1. Course Management (`ContentManagement/Courses/Index.jsx`)

**Purpose:** List and manage courses

**Features:**
- Course list with search/filter
- Create new course
- Edit/delete courses
- Publish/archive workflow
- Duplicate courses

**UI:**
- Data table with actions
- Create modal/page
- Bulk actions
- Status badges

---

### 2. Slide Editor (`ContentManagement/Lessons/SlideEditor.jsx`) 🎯 **COMPLEX**

**Purpose:** Drag-and-drop block editor for slides

**State:**
```javascript
const [blocks, setBlocks] = useState([]);
const [selectedBlock, setSelectedBlock] = useState(null);
const [dragging, setDragging] = useState(false);
```

**Layout:**
```jsx
<div className="flex h-screen">
  {/* Left: Block Palette */}
  <BlockPalette 
    onBlockAdd={(type) => addBlock(type)}
  />
  
  {/* Center: Slide Canvas */}
  <SlideCanvas 
    blocks={blocks}
    onReorder={reorderBlocks}
    onSelect={setSelectedBlock}
    onDelete={deleteBlock}
  />
  
  {/* Right: Block Settings */}
  {selectedBlock && (
    <BlockSettings 
      block={selectedBlock}
      onChange={updateBlock}
    />
  )}
</div>
```

---

## API Integration

### Axios Configuration

```javascript
// resources/js/utils/api.js
import axios from 'axios';

// Configure axios defaults
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.headers.common['X-CSRF-TOKEN'] = 
  document.querySelector('meta[name="csrf-token"]').content;

// Response interceptor for errors
axios.interceptors.response.use(
  response => response,
  error => {
    if (error.response?.status === 401) {
      window.location.href = '/login';
    }
    return Promise.reject(error);
  }
);

export default axios;
```

### API Endpoints Reference

```javascript
// Student endpoints
route('parent.courses.browse')                           // GET
route('parent.lessons.start', { lesson })                 // POST
route('parent.lessons.player', { lesson })                // GET
route('parent.lessons.slides.get', { lesson, slide })     // GET
route('parent.lessons.slides.view', { lesson, slide })    // POST
route('parent.lessons.progress.update', { lesson })       // POST
route('parent.lessons.questions.submit', { lesson, slide }) // POST
route('parent.lessons.upload.submit', { lesson, slide })  // POST
route('parent.lessons.complete', { lesson })              // POST

// Admin endpoints
route('admin.courses.index')                              // GET
route('admin.courses.store')                              // POST
route('admin.lesson-slides.blocks.add', { slide })        // POST
route('admin.lesson-slides.blocks.update', { slide, blockId }) // PUT
route('admin.lesson-slides.blocks.delete', { slide, blockId }) // DELETE
```

---

## State Management

### Context Provider Pattern

```javascript
// contexts/LessonPlayerContext.jsx
import { createContext, useContext, useState, useEffect } from 'react';

const LessonPlayerContext = createContext();

export const useLessonPlayer = () => {
  const context = useContext(LessonPlayerContext);
  if (!context) {
    throw new Error('useLessonPlayer must be used within LessonPlayerProvider');
  }
  return context;
};

export const LessonPlayerProvider = ({ children, lesson, initialProgress }) => {
  const [progress, setProgress] = useState(initialProgress);
  const [currentSlide, setCurrentSlide] = useState(null);
  const [timeSpent, setTimeSpent] = useState(0);
  
  // Track time
  useEffect(() => {
    const interval = setInterval(() => {
      setTimeSpent(prev => prev + 1);
    }, 1000);
    
    return () => clearInterval(interval);
  }, []);
  
  // Update progress periodically
  useEffect(() => {
    if (timeSpent % 10 === 0 && timeSpent > 0) {
      updateProgress();
    }
  }, [timeSpent]);
  
  const updateProgress = async () => {
    await axios.post(
      route('parent.lessons.progress.update', lesson.id),
      { time_spent_seconds: 10, slide_id: currentSlide?.id }
    );
  };
  
  const value = {
    lesson,
    progress,
    setProgress,
    currentSlide,
    setCurrentSlide,
    timeSpent,
    updateProgress
  };
  
  return (
    <LessonPlayerContext.Provider value={value}>
      {children}
    </LessonPlayerContext.Provider>
  );
};
```

---

## UI/UX Guidelines

### Design Principles

1. **Interactive First**
   - Smooth animations (Framer Motion)
   - Immediate feedback
   - Loading states
   - Error handling

2. **Mobile-Responsive**
   - Mobile-first approach
   - Touch-friendly controls
   - Responsive grid layouts
   - Collapsible sidebars

3. **Accessible**
   - ARIA labels
   - Keyboard navigation
   - Screen reader support
   - Color contrast (WCAG AA)

4. **Performance**
   - Lazy loading blocks
   - Image optimization
   - Debounced API calls
   - Virtual scrolling for long lists

### Color Palette

```javascript
// Tailwind colors
const theme = {
  primary: 'blue-600',
  secondary: 'purple-600',
  success: 'green-600',
  warning: 'yellow-600',
  error: 'red-600',
  info: 'indigo-600',
  
  // Block-specific
  text: 'gray-800',
  question: 'blue-100',
  upload: 'purple-100',
  callout: 'yellow-100',
  code: 'gray-900'
};
```

### Animation Patterns

```javascript
// Slide transitions
const slideVariants = {
  enter: { x: 1000, opacity: 0 },
  center: { x: 0, opacity: 1 },
  exit: { x: -1000, opacity: 0 }
};

// Block reveal
const blockVariants = {
  hidden: { opacity: 0, y: 20 },
  visible: { opacity: 1, y: 0 }
};

// Progress animation
const progressVariants = {
  initial: { width: 0 },
  animate: { width: `${percentage}%` }
};
```

---

## Implementation Order

### Phase 3A: Student Player (Priority 1) - Weeks 1-2

1. **Course Browser** (Day 1)
   - `ContentLessons/Browse.jsx`
   - Course cards component

2. **Lesson Player Shell** (Day 2)
   - `ContentLessons/Player.jsx`
   - Basic layout with navigation

3. **Basic Block Renderer** (Days 3-4)
   - `SlideRenderer.jsx`
   - TextBlock, ImageBlock, VideoBlock

4. **Progress Tracker** (Day 5)
   - `ProgressTracker.jsx`
   - Sidebar with slide list

5. **Question Block** (Days 6-7)
   - `QuestionBlock.jsx`
   - Submit logic
   - Feedback display

6. **Upload Block** (Days 8-9)
   - `UploadBlock.jsx`
   - File upload UI
   - Progress indicator

7. **Remaining Blocks** (Days 10-11)
   - AudioBlock, CalloutBlock, EmbedBlock
   - TimerBlock, ReflectionBlock
   - WhiteboardBlock, CodeBlock
   - TableBlock, DividerBlock

8. **Help Panel** (Days 12-13)
   - `HelpPanel.jsx`
   - TTS integration
   - AI chat integration

9. **Summary Page** (Day 14)
   - `Summary.jsx`
   - Analytics display

### Phase 3B: Admin Editor (Priority 2) - Weeks 3-4

1. **Course Management** (Days 15-16)
   - List, create, edit pages

2. **Module Management** (Days 17-18)
   - Nested CRUD

3. **Lesson Management** (Days 19-20)
   - Lesson list and edit

4. **Block Palette** (Day 21)
   - Draggable block picker

5. **Slide Canvas** (Days 22-24)
   - Drag-drop area
   - Block reordering

6. **Block Settings** (Days 25-26)
   - Dynamic settings panel
   - Per-block configuration

7. **Preview Mode** (Day 27)
   - Live preview

8. **Polish & Testing** (Day 28)
   - Bug fixes
   - Performance optimization

### Phase 3C: Dashboards (Priority 3) - Week 5

1. **Admin Analytics** (Days 29-30)
2. **Student Progress View** (Days 31-32)
3. **Teacher Review Queue** (Days 33-34)
4. **Final Testing** (Day 35)

---

## Next Steps

1. ✅ Review this guide
2. ✅ Set up directory structure
3. ✅ Start with Course Browser (easiest)
4. ✅ Build Lesson Player incrementally
5. ✅ Test each component thoroughly
6. ✅ Move to Admin Editor
7. ✅ Add dashboards
8. ✅ Final polish and deployment

---

**Status:** 📖 Documentation Complete - Ready for Implementation  
**Estimated Time:** 5 weeks (35 days) for full Phase 3  
**Priority:** Student Player → Admin Editor → Dashboards
