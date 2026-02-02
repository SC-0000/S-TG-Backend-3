# Year Group Bulk Management System

## Overview

This document describes the Year Group Bulk Management system that allows admins and teachers to efficiently update year groups for multiple students in a single operation using an intuitive modal interface with advanced filtering capabilities.

## Feature Summary

The Year Group Bulk Management system provides:

- **Self-contained modal workflow** - All filtering, selection, and updating happens within a single modal
- **Advanced filtering** - Filter students by name, year group, area, and school
- **Bulk selection** - Select multiple students or select all filtered results
- **Real-time filtering** - Instant results as filters are applied
- **Role-based access** - Available to both admins (all students) and teachers (assigned students only)

## Architecture

### Backend Components

#### 1. Controller: `YearGroupManagementController`

**Location:** `app/Http/Controllers/Admin/YearGroupManagementController.php`

**Methods:**

- `getYearGroups()` - Returns all year groups (Kindergarten through Grade 12)
- `bulkUpdate(Request $request)` - Updates year groups for multiple children
- `teacherBulkUpdate(Request $request)` - Scoped version for teachers (only their assigned students)

**Validation:**
```php
$validated = $request->validate([
    'child_ids' => 'required|array',
    'child_ids.*' => 'required|integer|exists:children,id',
    'year_group' => 'required|string',
]);
```

**Security:**
- Admin route: Updates any children
- Teacher route: Only updates children assigned to the teacher (via `child_teacher` pivot table)

#### 2. Routes

**Admin Routes** (`routes/admin.php`):
```php
Route::prefix('year-groups')->name('year-groups.')->group(function () {
    Route::get('/', [YearGroupManagementController::class, 'getYearGroups'])->name('index');
    Route::post('/bulk-update', [YearGroupManagementController::class, 'bulkUpdate'])->name('bulk-update');
});
```

**Teacher Routes** (`routes/teacher.php`):
```php
Route::prefix('year-groups')->name('year-groups.')->group(function () {
    Route::get('/', [YearGroupManagementController::class, 'getYearGroups'])->name('index');
    Route::post('/bulk-update', [YearGroupManagementController::class, 'teacherBulkUpdate'])->name('bulk-update');
});
```

### Frontend Components

#### 1. YearGroupManagementModal Component

**Location:** `resources/js/admin/components/YearGroupManagementModal.jsx`

**Props:**
- `isOpen` (boolean) - Controls modal visibility
- `students` (array) - List of students to manage
- `onClose` (function) - Callback when modal is closed
- `onSuccess` (function) - Callback after successful update
- `apiEndpoint` (string) - API endpoint for the bulk update

**Features:**

1. **Filter Section** - 4 dynamic filters:
   - Search by name (text input)
   - Filter by year group (dropdown)
   - Filter by area (dropdown)
   - Filter by school (dropdown)

2. **Students Table**:
   - Displays filtered results
   - Checkbox for individual selection
   - "Select All" checkbox for filtered results
   - Shows: Name, Current Year, School, Area

3. **Action Section** (sticky bottom):
   - Selected count display
   - Year group selector dropdown
   - Cancel and Update buttons
   - Error messaging

**State Management:**
```javascript
const [filters, setFilters] = useState({
    search: '',
    year_group: '',
    area: '',
    school_name: '',
});
const [selectedStudentIds, setSelectedStudentIds] = useState([]);
const [newYearGroup, setNewYearGroup] = useState('');
const [isSubmitting, setIsSubmitting] = useState(false);
const [error, setError] = useState('');
```

**Filtering Logic:**
```javascript
const filteredStudents = useMemo(() => {
    return students.filter(student => {
        const matchesSearch = !filters.search || 
            student.child_name?.toLowerCase().includes(filters.search.toLowerCase()) ||
            student.first_name?.toLowerCase().includes(filters.search.toLowerCase()) ||
            student.last_name?.toLowerCase().includes(filters.search.toLowerCase());
        
        const matchesYear = !filters.year_group || 
            student.year_group === filters.year_group;
        
        const matchesArea = !filters.area || 
            student.area?.toLowerCase().includes(filters.area.toLowerCase());
        
        const matchesSchool = !filters.school_name || 
            student.school_name?.toLowerCase().includes(filters.school_name.toLowerCase());
        
        return matchesSearch && matchesYear && matchesArea && matchesSchool;
    });
}, [students, filters]);
```

#### 2. Integration in Admin Children Index

**Location:** `resources/js/admin/Pages/Children/Index.jsx`

**Integration:**
```jsx
import YearGroupManagementModal from '@/admin/components/YearGroupManagementModal';

// In component
const [showYearGroupModal, setShowYearGroupModal] = useState(false);

// Button to open modal
<button
    onClick={() => setShowYearGroupModal(true)}
    className="inline-flex items-center bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 transition-all transform hover:scale-105"
>
    <Settings2 className="w-5 h-5 mr-2" />
    Manage Year Groups
</button>

// Modal component
<YearGroupManagementModal
    isOpen={showYearGroupModal}
    students={children}
    onClose={() => setShowYearGroupModal(false)}
    onSuccess={() => {
        router.reload();
        setShowYearGroupModal(false);
    }}
    apiEndpoint={route('admin.year-groups.bulk-update')}
/>
```

#### 3. Integration in Teacher Students Index

**Location:** `resources/js/admin/Pages/Teacher/Students/Index.jsx`

**Integration:**
```jsx
import YearGroupManagementModal from '@/admin/components/YearGroupManagementModal';

// In component
const [showYearGroupModal, setShowYearGroupModal] = useState(false);

// Button to open modal
<button
    onClick={() => setShowYearGroupModal(true)}
    className="inline-flex items-center bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-all"
>
    <Settings2 className="w-5 h-5 mr-2" />
    Manage Year Groups
</button>

// Modal component
<YearGroupManagementModal
    isOpen={showYearGroupModal}
    students={students.data}
    onClose={() => setShowYearGroupModal(false)}
    onSuccess={() => {
        router.reload();
        setShowYearGroupModal(false);
    }}
    apiEndpoint={route('teacher.year-groups.bulk-update')}
/>
```

## User Workflows

### Admin Workflow

1. Navigate to **Admin Portal → Children**
2. Click **"Manage Year Groups"** button in the header
3. Modal opens with all children displayed
4. Apply filters to narrow down students:
   - Search by name
   - Filter by current year group
   - Filter by area
   - Filter by school
5. Select students:
   - Check individual students
   - Or use "Select All" for filtered results
6. Choose new year group from dropdown
7. Click **"Update Year Group"**
8. Modal closes and page refreshes with updated data

### Teacher Workflow

1. Navigate to **Teacher Portal → My Students**
2. Click **"Manage Year Groups"** button in the header
3. Modal opens with assigned students only
4. Apply filters to narrow down students
5. Select students to update
6. Choose new year group from dropdown
7. Click **"Update Year Group"**
8. Modal closes and page refreshes with updated data

## Data Structure

### Year Groups Available

```javascript
const YEAR_GROUPS = [
    'Kindergarten',
    'Grade 1',
    'Grade 2',
    'Grade 3',
    'Grade 4',
    'Grade 5',
    'Grade 6',
    'Grade 7',
    'Grade 8',
    'Grade 9',
    'Grade 10',
    'Grade 11',
    'Grade 12',
];
```

### Student Object Structure

Expected properties on student objects:
```javascript
{
    id: number,
    child_name: string,      // Or first_name + last_name
    first_name: string,
    last_name: string,
    year_group: string,
    school_name: string,
    area: string,
    // ... other properties
}
```

## API Endpoints

### Get Year Groups
```
GET /admin/year-groups
GET /teacher/year-groups
```

**Response:**
```json
{
    "year_groups": [
        "Kindergarten",
        "Grade 1",
        ...
    ]
}
```

### Bulk Update (Admin)
```
POST /admin/year-groups/bulk-update
```

**Request Body:**
```json
{
    "child_ids": [1, 2, 3],
    "year_group": "Grade 5"
}
```

**Response:**
```json
{
    "success": true,
    "message": "Year groups updated successfully",
    "updated_count": 3
}
```

### Bulk Update (Teacher)
```
POST /teacher/year-groups/bulk-update
```

**Request Body:** Same as admin
**Response:** Same as admin

**Note:** Teachers can only update students assigned to them.

## Security Considerations

1. **Authorization**:
   - Admin routes require `auth` and `role:admin` middleware
   - Teacher routes require `auth` and `role:teacher,admin` middleware

2. **Data Scoping**:
   - Teachers only see and can update their assigned students
   - Admins can update any students

3. **Validation**:
   - Child IDs must exist in the database
   - Year group must be a valid string
   - Teacher bulk update verifies child-teacher relationship

4. **Error Handling**:
   - Validation errors are caught and displayed in the modal
   - Database errors are caught and logged
   - User-friendly error messages are shown

## UI/UX Features

### Modal Design

- **Full-screen modal** with max-width constraint
- **Fixed header** with title and close button
- **Filters section** with gray background for visual separation
- **Scrollable table** for long student lists
- **Sticky action bar** at bottom for easy access
- **Responsive design** for various screen sizes

### User Feedback

- **Selection counter** shows how many students selected
- **Filtered results counter** shows "X of Y students"
- **Loading states** during submission
- **Error messages** in red alert boxes
- **Success handling** via page reload

### Accessibility

- **Keyboard navigation** supported
- **Clear labels** on all form elements
- **Semantic HTML** structure
- **Hover states** on interactive elements
- **Disabled states** when appropriate

## Testing Recommendations

### Unit Tests

1. Test filter logic with various combinations
2. Test selection/deselection logic
3. Test form validation
4. Test API error handling

### Integration Tests

1. Test admin bulk update workflow
2. Test teacher bulk update workflow  
3. Test teacher data scoping
4. Test validation rules
5. Test concurrent updates

### E2E Tests

1. Complete workflow from opening modal to successful update
2. Filter combinations and edge cases
3. Select all functionality
4. Error scenarios
5. Permission boundaries

## Future Enhancements

Potential improvements for future iterations:

1. **Bulk Import** - CSV upload for year group updates
2. **History Tracking** - Log who changed year groups when
3. **Bulk Operations** - Additional bulk actions (e.g., bulk assign to teacher)
4. **Advanced Filters** - Date ranges, custom fields
5. **Export** - Export filtered results to CSV
6. **Undo** - Ability to revert bulk changes
7. **Preview** - Show what will change before confirming

## Troubleshooting

### Common Issues

**Modal doesn't open:**
- Check that `showYearGroupModal` state is being set
- Verify modal component is imported correctly

**No students showing:**
- Check that students array is being passed correctly
- Verify data structure matches expected format

**Updates not saving:**
- Check API endpoint configuration
- Verify route names match in frontend and backend
- Check browser console for errors

**Teacher can't update students:**
- Verify child-teacher relationships exist in database
- Check middleware permissions
- Verify teacher is authenticated

## Conclusion

The Year Group Bulk Management system provides an efficient, user-friendly way to manage student year group assignments at scale. The modal-based approach keeps the workflow self-contained while the powerful filtering system ensures users can quickly find and update the exact students they need.
