# Flexible Service System - Frontend Implementation Plan

## Overview
This document outlines the frontend components and modifications needed to support the Flexible Service System in the admin interface.

## Phase 1: Admin Service Creation/Edit Enhancement

### Changes to CreateService.jsx

#### 1. Add "Flexible" Service Type
**Location:** Service Type selector in Basic Information section

**Current Options:**
- Lesson
- Assessment  
- Bundle
- Course

**New Option:**
- **Flexible** - "User selects N from M items"

#### 2. New Section: Flexible Service Configuration
**Appears when:** `data._type === 'flexible'`

**Features:**
- Selection requirement inputs (how many live sessions, how many assessments)
- Content attachment with enrollment limits
- Real-time enrollment status display
- Validation warnings

**UI Components:**

```jsx
<EnhancedCard
  id="flexible-config"
  title="Flexible Service Configuration"
  icon={AdjustmentsIcon}
  expanded={expandedSections.flexibleConfig}
  onToggle={() => toggleSection('flexibleConfig')}
>
  {/* Selection Requirements */}
  <SelectionRequirements
    liveSessions={data.selection_config?.live_sessions || 0}
    assessments={data.selection_config?.assessments || 0}
    onChange={(config) => setData('selection_config', config)}
  />
  
  {/* Content Attachment with Limits */}
  <FlexibleContentAttachment
    lessons={lessons}
    assessments={assessments}
    selected={data.flexible_content || []}
    onChange={(content) => setData('flexible_content', content)}
  />
</EnhancedCard>
```

### New Components Needed

#### 1. SelectionRequirements Component
**Purpose:** Configure how many items users must select

```jsx
const SelectionRequirements = ({ liveSessions, assessments, onChange }) => (
  <div className="bg-blue-50 rounded-lg p-6 space-y-4">
    <h4 className="font-semibold text-gray-900">Selection Rules</h4>
    <p className="text-sm text-gray-600">
      Define how many items customers must choose from each category
    </p>
    
    <div className="grid grid-cols-2 gap-4">
      <NumberInput
        label="Live Sessions Required"
        value={liveSessions}
        min={0}
        onChange={(val) => onChange({ live_sessions: val, assessments })}
      />
      
      <NumberInput
        label="Assessments Required"
        value={assessments}
        min={0}
        onChange={(val) => onChange({ live_sessions: liveSessions, assessments: val })}
      />
    </div>
    
    {(liveSessions > 0 || assessments > 0) && (
      <div className="bg-white rounded-lg p-4">
        <p className="text-sm text-gray-700">
          Customers will select <strong>{liveSessions} live session(s)</strong>
          {assessments > 0 && ` and <strong>${assessments} assessment(s)</strong>`}
        </p>
      </div>
    )}
  </div>
);
```

#### 2. FlexibleContentAttachment Component
**Purpose:** Attach content items with enrollment limits

```jsx
const FlexibleContentAttachment = ({ lessons, assessments, selected, onChange }) => {
  const [activeTab, setActiveTab] = useState('sessions');
  
  return (
    <div className="space-y-4">
      {/* Tabs */}
      <div className="border-b border-gray-200">
        <nav className="flex space-x-8">
          <button
            onClick={() => setActiveTab('sessions')}
            className={`pb-4 px-1 border-b-2 font-medium text-sm ${
              activeTab === 'sessions'
                ? 'border-blue-500 text-blue-600'
                : 'border-transparent text-gray-500'
            }`}
          >
            Live Sessions
          </button>
          <button
            onClick={() => setActiveTab('assessments')}
            className={`pb-4 px-1 border-b-2 font-medium text-sm ${
              activeTab === 'assessments'
                ? 'border-blue-500 text-blue-600'
                : 'border-transparent text-gray-500'
            }`}
          >
            Assessments
          </button>
        </nav>
      </div>
      
      {/* Content List */}
      {activeTab === 'sessions' && (
        <ContentItemList
          items={lessons}
          type="lesson"
          selected={selected.filter(s => s.type === 'lesson')}
          onChange={(items) => {
            const updated = [
              ...selected.filter(s => s.type !== 'lesson'),
              ...items
            ];
            onChange(updated);
          }}
        />
      )}
      
      {activeTab === 'assessments' && (
        <ContentItemList
          items={assessments}
          type="assessment"
          selected={selected.filter(s => s.type === 'assessment')}
          onChange={(items) => {
            const updated = [
              ...selected.filter(s => s.type !== 'assessment'),
              ...items
            ];
            onChange(updated);
          }}
        />
      )}
    </div>
  );
};
```

#### 3. ContentItemList Component
**Purpose:** Display available content with enrollment limit inputs

```jsx
const ContentItemList = ({ items, type, selected, onChange }) => {
  const handleToggle = (itemId) => {
    if (selected.find(s => s.id === itemId)) {
      onChange(selected.filter(s => s.id !== itemId));
    } else {
      onChange([...selected, { id: itemId, type, max_enrollments: null }]);
    }
  };
  
  const handleLimitChange = (itemId, limit) => {
    onChange(selected.map(s => 
      s.id === itemId ? { ...s, max_enrollments: limit } : s
    ));
  };
  
  return (
    <div className="space-y-2">
      {items.map(item => {
        const isSelected = selected.find(s => s.id === item.id);
        
        return (
          <div
            key={item.id}
            className={`border rounded-lg p-4 ${
              isSelected ? 'border-blue-500 bg-blue-50' : 'border-gray-200'
            }`}
          >
            <div className="flex items-start justify-between">
              <div className="flex items-start space-x-3">
                <input
                  type="checkbox"
                  checked={!!isSelected}
                  onChange={() => handleToggle(item.id)}
                  className="mt-1 w-4 h-4 text-blue-600"
                />
                <div>
                  <h5 className="font-medium text-gray-900">{item.title}</h5>
                  {item.start_time && (
                    <p className="text-sm text-gray-500">
                      {new Date(item.start_time).toLocaleDateString()}
                    </p>
                  )}
                </div>
              </div>
              
              {isSelected && (
                <div className="w-40">
                  <label className="block text-xs text-gray-600 mb-1">
                    Enrollment Limit
                  </label>
                  <input
                    type="number"
                    min="1"
                    value={isSelected.max_enrollments || ''}
                    onChange={(e) => handleLimitChange(
                      item.id, 
                      e.target.value ? parseInt(e.target.value) : null
                    )}
                    placeholder="Unlimited"
                    className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm"
                  />
                </div>
              )}
            </div>
          </div>
        );
      })}
    </div>
  );
};
```

#### 4. EnrollmentStatusBadge Component
**Purpose:** Show enrollment status for each item

```jsx
const EnrollmentStatusBadge = ({ current, max }) => {
  if (!max) {
    return (
      <span className="px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-700">
        Unlimited
      </span>
    );
  }
  
  const percentage = (current / max) * 100;
  let colorClass = 'bg-green-100 text-green-800';
  
  if (percentage >= 90) colorClass = 'bg-red-100 text-red-800';
  else if (percentage >= 75) colorClass = 'bg-yellow-100 text-yellow-800';
  
  return (
    <span className={`px-2 py-1 text-xs rounded-full ${colorClass}`}>
      {current}/{max} enrolled
    </span>
  );
};
```

### Data Structure Changes

#### Form Data Addition
```javascript
const { data, setData, post } = useForm({
  // ... existing fields ...
  
  // New fields for flexible services
  selection_config: {
    live_sessions: 0,
    assessments: 0
  },
  flexible_content: [
    // { id: 1, type: 'lesson', max_enrollments: 10 },
    // { id: 2, type: 'assessment', max_enrollments: 15 },
  ]
});
```

### Validation Rules

1. If type is "flexible":
   - Must have selection_config with at least one requirement > 0
   - Must have enough content items to satisfy requirements
   - Cannot also have course_id set

2. Content validation:
   - Number of attached sessions >= required sessions
   - Number of attached assessments >= required assessments

### Backend Integration

#### On Submit
```javascript
const submit = (e) => {
  e.preventDefault();
  
  // Transform flexible_content into pivot data
  if (data._type === 'flexible') {
    const lessonPivot = data.flexible_content
      .filter(c => c.type === 'lesson')
      .reduce((acc, c) => ({
        ...acc,
        [c.id]: { max_enrollments: c.max_enrollments }
      }), {});
      
    const assessmentPivot = data.flexible_content
      .filter(c => c.type === 'assessment')
      .reduce((acc, c) => ({
        ...acc,
        [c.id]: { max_enrollments: c.max_enrollments }
      }), {});
    
    // Send with pivot data
    post(route('services.store'), {
      ...data,
      lesson_pivot_data: lessonPivot,
      assessment_pivot_data: assessmentPivot
    });
  } else {
    post(route('services.store'));
  }
};
```

## Phase 2: Service Show/Edit Page Enhancement

### Display Flexible Service Info
- Show selection requirements
- Display attached content with limits
- Show current enrollment status
- Provide enrollment analytics

### Edit Functionality
- Allow updating selection requirements
- Allow adding/removing content
- Allow adjusting enrollment limits
- Show warnings if changes affect existing purchases

## Phase 3: Validation & Error Handling

### Frontend Validation
- Ensure requirements don't exceed available content
- Warn if lowering limits below current enrollments
- Validate enrollment limit is a positive number

### Error Display
- Clear error messages for configuration issues
- Visual indicators for validation problems
- Help text for complex scenarios

## Implementation Priority

1. ✅ Backend complete
2. ✅ API endpoints complete
3. 🔄 Admin service creation (current phase)
4. ⏳ Admin service editing
5. ⏳ Service show page enhancements
6. ⏳ User purchase interface
7. ⏳ Cart integration

## Next Steps

1. Modify CreateService.jsx to add flexible type
2. Create FlexibleServiceConfig component
3. Update StoreServiceRequest validation
4. Update ServiceController to handle pivot data
5. Test with real data

## Notes

- Keep UI consistent with existing design patterns
- Ensure mobile responsiveness
- Add helpful tooltips and examples
- Consider accessibility (screen readers, keyboard navigation)
