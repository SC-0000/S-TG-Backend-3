# Progress Tracker Journey View - Courses Integration Plan

**Date:** November 11, 2025  
**Status:** Planning Phase  
**Component:** OverviewTab.jsx (Journey-based Overview)

---

## 📋 Table of Contents

1. [Project Context](#project-context)
2. [Current System Analysis](#current-system-analysis)
3. [Design Requirements](#design-requirements)
4. [Proposed Solution: Three-Way Toggle](#proposed-solution-three-way-toggle)
5. [Implementation Plan](#implementation-plan)
6. [Backend Changes](#backend-changes)
7. [Frontend Components](#frontend-components)
8. [Testing Strategy](#testing-strategy)

---

## 📖 Project Context

### Background

The Progress Tracker has a Journey-based Overview section that currently shows:
- **Journeys** (e.g., "11+ Preparation")
- **Topics** (e.g., "English", "Maths") 
- **Categories** (e.g., "Grammar", "Reading")
- **Content** (Lessons + Assessments)

**Problem:** Courses are not integrated into this journey view, making it difficult for users to see structured learning paths.

### Recent Changes (November 11, 2025)

**✅ COMPLETED:**
- Added `journey_category_id` field to `courses` table (migration: `2025_11_11_014456_add_journey_category_to_courses_table.php`)
- Added `journey_category_id` field to `new_lessons` (ContentLesson) table (migration: `2025_11_11_194256_add_journey_category_to_content_lessons_table.php`)
- Updated Course and ContentLesson models with journey category relationships
- Courses now auto-assign journey_category_id when created
- Content lessons auto-inherit journey_category_id from parent course when created via course edit page
- Content lessons can have journey_category_id set manually when created standalone
- Frontend create/edit pages include journey category dropdown

**Impact on Progress Tracker:**
- Content lessons can now be directly linked to journey categories (not just through courses)
- Standalone content identification logic needs to check `journey_category_id` field
- More accurate categorization of learning content by journey

---

## 🔍 Current System Analysis

### Existing Data Structure

```
Journey → Topics → Categories → Content
```

**Current Flow:**
```javascript
// Backend (TrackerController.php)
Journey {
  id, title,
  topics: {
    "English": [
      {id: 1, name: "Grammar", topic: "English", lessons: [...], assessments: [...]},
      {id: 2, name: "Reading", topic: "English", lessons: [...], assessments: [...]
    ],
    "Maths": [...]
  }
}
```

**Frontend (OverviewTab.jsx):**
- 3-column layout with SVG connections
- Desktop: Side-by-side columns with animated connections
- Mobile: Stacked sections
- Filter cascade: Topic → Category → Content
- "Purchased" badges via Access table check

### Current File Locations

**Backend:**
- `app/Http/Controllers/TrackerController.php` - Data fetching (lines 493-561 handle journey data)
- `app/Models/JourneyCategory.php` - Category model with relationships

**Frontend:**
- `resources/js/parent/components/ProgressTracker/Overview/OverviewTab.jsx` - Main component (~800 lines)
- Current navigation: Journey Selection → Filter Controls → 3-Column Grid

---

## 📐 Design Requirements

### User Requirements

1. **Three viewing modes** to accommodate different user preferences
2. **Show courses** integrated into the journey structure
3. **Content lessons focus** - Display content lessons (not live sessions in UI, but prepare backend)
4. **Smart display** - Skip courses column for standalone content
5. **Timeline view** - Show courses compactly without module details

### Technical Requirements

1. **Content Lessons Only in Frontend**
   - Use `ContentLesson` model for all displayed lessons
   - Backend should fetch live sessions data but comment out in frontend rendering
   - Keep future flexibility for live sessions

2. **Timeline Simplification**
   - Show courses as single items (don't expand modules)
   - Display standalone content alongside courses
   - Keep hierarchy clear: Topic → Category → (Courses | Standalone Content)

3. **Backward Compatibility**
   - Classic 3-column view must work exactly as before
   - No breaking changes to existing functionality
   - Progressive enhancement approach

---

## 🎯 Proposed Solution: Three-Way Toggle

### Overview

Add a toggle control that switches between three distinct view modes:

```
┌────────────────────────────────────────────┐
│  [📊 Timeline] [🗂️ Classic] [📚 Courses]  │
│     Mode 1        Mode 2        Mode 3      │
└────────────────────────────────────────────┘
```

### Mode 1: Timeline/Roadmap View

**Purpose:** Vertical, hierarchical view of entire learning journey

**Structure:**
```
Journey: 11+ Preparation
  │
  ├─ 🎯 English
  │   ├─ 📁 Grammar
  │   │   ├─ 📚 Advanced Grammar [75%]
  │   │   ├─ 📚 Grammar Basics [100%]
  │   │   ├─ 📖 Quick Grammar Tips (standalone)
  │   │   └─ 📝 Grammar Quiz (standalone)
  │   └─ 📁 Reading
  │       └─ ...
  └─ 🎯 Maths
      └─ ...
```

**Key Features:**
- Collapsible tree structure
- Courses shown WITHOUT module expansion
- Progress bars for courses
- Color-coded icons
- Vertical, mobile-friendly
- Clear visual hierarchy

---

### Mode 2: Classic View (Current)

**Purpose:** Maintain existing functionality, no changes

**Structure:**
```
Topics → Categories → Content Lessons + Assessments
```

**Features:**
- Keeps all existing behavior
- 3-column grid with SVG connections
- Filter cascade system
- No modification needed

---

### Mode 3: Courses View (4-Column with Smart Display)

**Purpose:** Show course-based learning paths with intelligent layout

**Structure:**
```
Topics → Categories → Courses → Content
```

**Smart Display Logic:**

1. **If content belongs to a course:**
   ```
   Category → Course → Course Content
   ```
   
2. **If content is standalone:**
   ```
   Category ──────────→ Standalone Content
              (skip courses column)
   ```

**Visual Example:**
```
┌──────────┐   ┌─────────────┐   ┌──────────┐
│Categories│   │   Courses   │   │ Content  │
├──────────┤   ├─────────────┤   ├──────────┤
│          │   │             │   │          │
│ Grammar ─┼──→│ Advanced    ├──→│ Lesson 1 │ ← Course content
│          │   │ Grammar     │   │ Lesson 2 │
│          │   │ [75%]       │   │          │
│          │   │             │   │          │
│          │   │   (skip)    │   │          │
│          ├───┼─────────────┼──→│ Quick    │ ← Standalone
│          │   │             │   │ Tip      │   (purple connection)
└──────────┘   └─────────────┘   └──────────┘
```

**Connection Types:**
- **Blue:** Category → Course → Course Content
- **Purple:** Category → Standalone Content (bypasses courses)

---

## 🔧 Implementation Plan

### Phase 1: Backend Data Enhancement

**File:** `app/Http/Controllers/TrackerController.php`

**Objective:** Add course data and identify standalone content

#### Step 1.1: Fetch Courses for Categories

```php
// In show() method, add after journey fetching
foreach ($journeys as $journey) {
    foreach ($journey->categories as $category) {
        
        // 1. Get courses linked to this category
        $category->courses = Course::where('journey_category_id', $category->id)
            ->with([
                'modules.contentLessons', // Content lessons within modules
                'modules.lessons',        // Live sessions (for backend only)
            ])
            ->get()
            ->map(function($course) use ($childIds) {
                // Calculate course progress
                $allLessons = $course->modules->flatMap(fn($m) => $m->contentLessons);
                $progressRecords = LessonProgress::whereIn('child_id', $childIds)
                    ->whereIn('lesson_id', $allLessons->pluck('id'))
                    ->get();
                
                $totalLessons = $allLessons->count();
                $completedLessons = $progressRecords->where('status', 'completed')->count();
                $avgProgress = $totalLessons > 0 
                    ? ($completedLessons / $totalLessons) * 100 
                    : 0;
                
                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'thumbnail' => $course->thumbnail,
                    'description' => $course->description,
                    'progress' => round($avgProgress),
                    'total_lessons' => $totalLessons,
                    'completed_lessons' => $completedLessons,
                    'modules_count' => $course->modules->count(),
                    
                    // Content lessons from all modules
                    'content_lessons' => $allLessons->map(fn($l) => [
                        'id' => $l->id,
                        'title' => $l->title,
                        'module_id' => $l->pivot->module_id ?? null,
                    ]),
                    
                    // OPTIONAL: Live sessions (prepared but not used in frontend initially)
                    'live_sessions' => $course->modules->flatMap(fn($m) => $m->lessons)->map(fn($l) => [
                        'id' => $l->id,
                        'title' => $l->title,
                        'date' => $l->start_time?->toDateString(),
                    ]),
                ];
            });
        
        // 2. Identify standalone content lessons (not in any course)
        // UPDATED: Now using direct journey_category_id relationship
        $courseLessonIds = $category->courses->flatMap(function($course) {
            return collect($course['content_lessons'])->pluck('id');
        });
        
        // Get all content lessons directly linked to this category via journey_category_id
        $categoryContentLessons = ContentLesson::where('journey_category_id', $category->id)->get();
        
        $category->standalone_content_lessons = $categoryContentLessons
            ->whereNotIn('id', $courseLessonIds)
            ->map(fn($l) => [
                'id' => $l->id,
                'title' => $l->title,
                'type' => 'content_lesson',
            ]);
        
        // 3. Identify standalone assessments (not linked to courses)
        $category->standalone_assessments = $category->assessments
            ->reject(function($assessment) use ($category) {
                // Check if assessment is linked to any course in this category
                return $assessment->courses()
                    ->where('journey_category_id', $category->id)
                    ->exists();
            })
            ->map(fn($a) => [
                'id' => $a->id,
                'title' => $a->title,
                'type' => 'assessment',
            ]);
        
        // 4. OPTIONAL: Standalone live sessions (prepared for future)
        // $category->standalone_live_sessions = Lesson::whereHas(...)
        //     ->whereNotIn('id', $courseSessionIds)
        //     ->get();
    }
}
```

#### Step 1.2: Add Progress Tracking

```php
// Add child access checking for highlighting
$category->child_has_access_to_courses = Course::where('journey_category_id', $category->id)
    ->whereHas('access', function($q) use ($childIds) {
        $q->whereIn('child_id', $childIds)
          ->where('access', true);
    })
    ->pluck('id')
    ->toArray();
```

**Expected Output Structure:**
```php
[
    'journeys' => [
        [
            'id' => 1,
            'title' => '11+ Preparation',
            'topics' => [
                'English' => [
                    [
                        'id' => 1,
                        'name' => 'Grammar',
                        'topic' => 'English',
                        
                        // NEW: Courses
                        'courses' => [
                            [
                                'id' => 101,
                                'title' => 'Advanced Grammar',
                                'progress' => 75,
                                'total_lessons' => 10,
                                'completed_lessons' => 7,
                                'modules_count' => 3,
                                'content_lessons' => [...],
                            ]
                        ],
                        
                        // NEW: Standalone content
                        'standalone_content_lessons' => [...],
                        'standalone_assessments' => [...],
                        
                        // Existing
                        'lessons' => [...], // OLD structure (keep for backward compatibility)
                        'assessments' => [...],
                    ]
                ]
            ]
        ]
    ]
]
```

---

### Phase 2: Frontend State Management

**File:** `resources/js/parent/components/ProgressTracker/Overview/OverviewTab.jsx`

#### Step 2.1: Add View Mode State

```javascript
// Add to existing state declarations
const [viewMode, setViewMode] = useState(
  localStorage.getItem('journey-view-mode') || 'classic'
); // Options: 'timeline', 'classic', 'courses'

// Persist view mode selection
useEffect(() => {
  localStorage.setItem('journey-view-mode', viewMode);
}, [viewMode]);

// Add expanded nodes state for timeline
const [expandedNodes, setExpandedNodes] = useState({
  topics: [],
  categories: [],
  courses: []
});

const toggleNode = (type, id) => {
  setExpandedNodes(prev => ({
    ...prev,
    [type]: prev[type].includes(id)
      ? prev[type].filter(nodeId => nodeId !== id)
      : [...prev[type], id]
  }));
};
```

#### Step 2.2: Create Toggle Component

```javascript
// Add after journey selection, before filter controls
<div className="mb-6 flex justify-center gap-2 bg-white rounded-xl p-2 shadow-lg border border-gray-200">
  <button
    onClick={() => setViewMode('timeline')}
    className={`px-6 py-3 rounded-lg font-medium transition-all duration-200 flex items-center gap-2 ${
      viewMode === 'timeline'
        ? 'bg-accent-soft text-white shadow-md scale-105'
        : 'text-gray-600 hover:bg-gray-100'
    }`}
  >
    <List className="h-5 w-5" />
    <span className="hidden sm:inline">Timeline</span>
    <span className="sm:hidden">Time</span>
  </button>
  
  <button
    onClick={() => setViewMode('classic')}
    className={`px-6 py-3 rounded-lg font-medium transition-all duration-200 flex items-center gap-2 ${
      viewMode === 'classic'
        ? 'bg-accent-soft text-white shadow-md scale-105'
        : 'text-gray-600 hover:bg-gray-100'
    }`}
  >
    <LayoutGrid className="h-5 w-5" />
    <span className="hidden sm:inline">Classic</span>
    <span className="sm:hidden">Grid</span>
  </button>
  
  <button
    onClick={() => setViewMode('courses')}
    className={`px-6 py-3 rounded-lg font-medium transition-all duration-200 flex items-center gap-2 ${
      viewMode === 'courses'
        ? 'bg-accent-soft text-white shadow-md scale-105'
        : 'text-gray-600 hover:bg-gray-100'
    }`}
  >
    <BookOpen className="h-5 w-5" />
    <span className="hidden sm:inline">Courses</span>
    <span className="sm:hidden">Cour</span>
  </button>
</div>
```

---

### Phase 3: Timeline View Implementation

**Objective:** Create collapsible vertical timeline showing complete journey hierarchy

#### Timeline Component Hierarchy

```javascript
TimelineView
  ├─ TimelineTopicNode (for each topic)
  │   └─ TimelineCategoryNode (for each category)
  │       ├─ TimelineCourseNode (for each course - NO module expansion)
  │       └─ TimelineContentNode (for standalone content)
```

#### Implementation Details

See the comprehensive code examples in the full plan document sections 3.1-3.5

Key Features:
- Collapsible at topic and category levels
- Courses display progress bars but don't expand to show modules
- Standalone content clearly marked with "Direct" badge
- Smooth animations with Framer Motion
- Mobile-friendly vertical layout

---

### Phase 4: Courses View Implementation (4-Column)

**Objective:** Create 4-column grid with smart content routing

#### Smart Content Aggregation Logic

The key to this view is aggregating content from both courses and standalone sources, then routing connections appropriately:

**Connection Rules:**
1. Course content → Blue connection through courses column
2. Standalone content → Purple connection skipping courses column

See detailed implementation in sections 4.1-4.6 of the full plan

---

### Phase 5: Mobile Responsiveness

**All three modes stack vertically on mobile:**

- **Timeline:** Already vertical-friendly
- **Classic:** Use existing mobile layout
- **Courses:** Stack 4 sections (Topics → Categories → Courses → Content)

---

## 🎨 Styling & Visual Design

### Color Scheme

**Timeline Mode:**
- Topics: `bg-primary` (#411183)
- Categories: `bg-accent` 
- Courses: `bg-blue-50 border-blue-500`
- Content Lessons: `bg-yellow-50 border-yellow-400`
- Assessments: `bg-gray-50 border-gray-400`

**Courses View:**
- Courses Column: Blue gradient (`bg-blue-100` to `bg-blue-600`)
- Course → Content: Blue connections (#2563eb)
- Category → Standalone: Purple connections (#9333ea)
- Standalone Badge: `bg-purple-500`

### Icons

```javascript
import {
  Target,          // Topics
  Layers,          // Categories
  GraduationCap,   // Courses
  BookOpen,        // Content Lessons
  NotebookPenIcon, // Assessments
  ChevronRight,    // Expand/collapse
  List,            // Timeline mode icon
  LayoutGrid,      // Classic mode icon
} from 'lucide-react';

import { GiJourney } from 'react-icons/gi';
import { MdLibraryBooks } from 'react-icons/md';
```

---

## 🧪 Testing Strategy

### Manual Testing Checklist

#### Backend Data
- [ ] Courses appear in category data
- [ ] Standalone content lessons identified correctly
- [ ] Standalone assessments identified correctly
- [ ] Course progress calculated accurately
- [ ] Child access data included correctly

#### Timeline View
- [ ] All topics render and are collapsible
- [ ] Categories expand/collapse smoothly
- [ ] Courses display with progress bars
- [ ] Standalone content shows "Direct" badge
- [ ] No module details shown inside courses
- [ ] Mobile stacking works correctly

#### Classic View
- [ ] No regressions in existing functionality
- [ ] All filters work as before
- [ ] SVG connections render correctly
- [ ] Mobile layout unchanged

#### Courses View
- [ ] 4-column grid displays correctly
- [ ] Courses column shows all category courses
- [ ] Content column shows both course and standalone items
- [ ] Blue connections: Category → Course → Content
- [ ] Purple connections: Category → Standalone Content
- [ ] Standalone content shows "Direct" badge
- [ ] Course selection highlights related content
- [ ] Mobile 4-section stack works

#### General
- [ ] Toggle switches between modes smoothly
- [ ] View mode persists in localStorage
- [ ] All icons display correctly
- [ ] Responsive design on all breakpoints
- [ ] Animations perform smoothly
- [ ] No console errors

---

## 📦 Deployment Checklist

### Pre-Deployment

1. **Backend**
   - [ ] Run migrations
   - [ ] Test course-category relationships
   - [ ] Verify backward compatibility
   - [ ] Check query performance

2. **Frontend**
   - [ ] Build assets (`npm run build`)
   - [ ] Test all three view modes
   - [ ] Verify mobile responsiveness
   - [ ] Check browser compatibility

3. **Documentation**
   - [ ] Update API documentation
   - [ ] Add user guide for new views
   - [ ] Document any configuration changes

### Post-Deployment

1. **Monitoring**
   - [ ] Check for JavaScript errors
   - [ ] Monitor backend performance
   - [ ] Verify data accuracy
   - [ ] Test with real user data

2. **User Feedback**
   - [ ] Collect initial impressions
   - [ ] Note any UI/UX issues
   - [ ] Track which view mode is most popular

---

## 🔮 Future Enhancements

### Phase 6 (Optional)

1. **Live Sessions Integration**
   - Uncomment live session code
   - Add to timeline view
   - Create separate filter for live content

2. **Advanced Filtering**
   - Filter by completion status
   - Filter by purchase status
   - Date range filtering

3. **Progress Visualization**
   - Completion heatmaps
   - Timeline progress bars
   - Comparative analytics

4. **User Preferences**
   - Save default view mode per user
   - Customizable column visibility
   - Adjustable icon sizes

---

## 📝 Notes & Considerations

### Important Design Decisions

1. **Content Lessons Only (Initially)**
   - Frontend displays only content lessons
   - Backend prepares live session data
   - Easy to enable live sessions later by uncommenting code

2. **Timeline Simplification**
   - Courses shown as single items (no module expansion)
   - Reduces visual clutter
   - Maintains clear hierarchy

3. **Smart Column Skipping**
   - Visual consistency maintained
   - Purple connections indicate "direct" paths
   - Positions calculated to avoid gaps

### Performance Considerations

- Use `useMemo` for expensive calculations
- Lazy load SVG connections (desktop only)
- Debounce connection recalculations on resize
- Consider pagination for large course lists

### Accessibility

- Use semantic HTML
- Add ARIA labels for screen readers
- Ensure keyboard navigation works
- Test with screen readers
- Maintain color contrast ratios

---

## 🎯 Success Criteria

**The implementation is successful when:**

1. ✅ All three view modes render correctly
2. ✅ Backend provides accurate course and standalone content data
3. ✅ SVG connections work in courses view
4. ✅ Timeline view shows clear learning hierarchy
5. ✅ Classic view remains unchanged
6. ✅ Mobile responsiveness maintained across all modes
7. ✅ No performance degradation
8. ✅ User can switch modes seamlessly
9. ✅ View mode preference persists
10. ✅ All tests pass

---

**Last Updated:** November 11, 2025  
**Version:** 1.0  
**Status:** Ready for Implementation
