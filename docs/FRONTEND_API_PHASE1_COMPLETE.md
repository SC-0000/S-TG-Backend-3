# Frontend API Conversion - Phase 1 Complete

**Status:** ✅ Complete  
**Date:** February 2, 2026

## Overview

Phase 1 establishes the foundational infrastructure for converting the frontend from Inertia.js to direct API calls. This phase provides all the core utilities, contexts, and helpers needed to build API-driven React components.

---

## What Was Built

### 1. API Client (`resources/js/api/client.js`)

**Enhanced Features:**
- ✅ Token-based authentication with automatic attachment
- ✅ Automatic token refresh on 401 errors
- ✅ Request/response envelope handling (`{ data, meta, errors }`)
- ✅ Custom `ApiError` class for structured error handling
- ✅ Support for FormData and JSON payloads
- ✅ Query parameter building with array support

**Token Refresh Logic:**
- Automatically retries failed requests after refreshing token
- Prevents multiple simultaneous refresh requests
- Queues pending requests during token refresh
- Clears token if refresh fails (forces re-authentication)

### 2. API Helpers (`resources/js/api/helpers.js`)

**Pagination:**
```javascript
import { buildPaginationParams, parsePaginationMeta } from '@/api';

// Build pagination params
const params = buildPaginationParams(2, 20); 
// Returns: { page: 2, per_page: 20 }

// Parse response meta
const pagination = parsePaginationMeta(response.meta);
// Returns: { currentPage, lastPage, total, hasNextPage, etc. }
```

**Filtering:**
```javascript
import { buildFilterParams } from '@/api';

const params = buildFilterParams({ status: 'active', search: 'test' });
// Returns: { 'filter[status]': 'active', 'filter[search]': 'test' }
```

**Sorting:**
```javascript
import { buildSortParams } from '@/api';

const params = buildSortParams('created_at', 'desc');
// Returns: { sort: '-created_at' }

// Multiple fields
const params = buildSortParams(['name', 'created_at'], 'asc');
// Returns: { sort: 'name,created_at' }
```

**Combined Query Building:**
```javascript
import { buildQueryParams } from '@/api';

const params = buildQueryParams({
    page: 2,
    perPage: 20,
    filters: { status: 'active' },
    sortBy: 'created_at',
    sortDirection: 'desc'
});
// Returns complete query object for API calls
```

**File Uploads:**
```javascript
import { objectToFormData, uploadWithProgress } from '@/api';

// Convert object to FormData
const formData = objectToFormData({
    title: 'Test',
    file: fileObject,
    tags: ['tag1', 'tag2']
});

// Upload with progress tracking
await uploadWithProgress(
    '/api/v1/uploads/images',
    fileObject,
    { title: 'My Image' },
    (percentage) => console.log(`Upload: ${percentage}%`)
);
```

**Error Handling:**
```javascript
import { extractValidationErrors, isValidationError, isAuthError } from '@/api';

try {
    await apiClient.post('/api/v1/courses', data);
} catch (error) {
    if (isValidationError(error)) {
        const fieldErrors = extractValidationErrors(error);
        // fieldErrors = { title: 'Title is required', ... }
    } else if (isAuthError(error)) {
        // Redirect to login
    }
}
```

### 3. AuthContext (`resources/js/contexts/AuthContext.jsx`)

**Features:**
- ✅ Centralized authentication state management
- ✅ Automatic user fetching on mount (if token exists)
- ✅ Login, register, logout, and update user methods
- ✅ Role-based access control helpers
- ✅ Loading and initialization states

**Usage:**
```javascript
import { useAuth } from '@/contexts/AuthContext';

function MyComponent() {
    const { 
        user, 
        isAuthenticated, 
        loading, 
        login, 
        logout, 
        hasRole 
    } = useAuth();

    // Login
    const handleLogin = async () => {
        const result = await login({ email, password });
        if (result.success) {
            // Login successful
        } else {
            // Handle error: result.error
        }
    };

    // Role checking
    if (hasRole('admin')) {
        return <AdminPanel />;
    }

    return <UserPanel />;
}
```

**Setup in App Root:**
```javascript
import { AuthProvider } from '@/contexts/AuthContext';

function App() {
    return (
        <AuthProvider initialUser={window.initialUser}>
            <YourApp />
        </AuthProvider>
    );
}
```

### 4. ToastContext (`resources/js/contexts/ToastContext.jsx`)

**Features:**
- ✅ Global toast notification system
- ✅ Success, error, and info message types
- ✅ Auto-dismiss with configurable duration
- ✅ Manual dismiss support
- ✅ Multiple simultaneous toasts

**Usage:**
```javascript
import { useToast } from '@/contexts/ToastContext';

function MyComponent() {
    const { showSuccess, showError, showInfo } = useToast();

    const handleSubmit = async () => {
        try {
            await apiClient.post('/api/v1/courses', data);
            showSuccess('Course created successfully!');
        } catch (error) {
            showError(error.message || 'Failed to create course');
        }
    };
}
```

**Setup in App Root:**
```javascript
import { ToastProvider } from '@/contexts/ToastContext';

function App() {
    return (
        <ToastProvider>
            <YourApp />
        </ToastProvider>
    );
}
```

---

## File Structure

```
resources/js/
├── api/
│   ├── client.js       # Core API client with token refresh
│   ├── config.js       # API base URL configuration
│   ├── helpers.js      # Pagination, filtering, upload utilities
│   ├── index.js        # Main exports
│   └── token.js        # Token storage management
├── contexts/
│   ├── AuthContext.jsx # Authentication state management
│   └── ToastContext.jsx # Global toast notifications
└── stores/
    └── authStore.js    # (Legacy - can be deprecated)
```

---

## Integration Example

Here's a complete example of using all Phase 1 components together:

```javascript
import React, { useState, useEffect } from 'react';
import { apiClient, buildQueryParams, extractValidationErrors } from '@/api';
import { useAuth } from '@/contexts/AuthContext';
import { useToast } from '@/contexts/ToastContext';

function CoursesPage() {
    const { user, hasRole } = useAuth();
    const { showSuccess, showError } = useToast();
    const [courses, setCourses] = useState([]);
    const [loading, setLoading] = useState(true);
    const [pagination, setPagination] = useState({});

    useEffect(() => {
        loadCourses();
    }, []);

    const loadCourses = async (page = 1) => {
        try {
            setLoading(true);
            const params = buildQueryParams({
                page,
                perPage: 20,
                filters: { status: 'published' },
                sortBy: 'created_at',
                sortDirection: 'desc'
            });

            const response = await apiClient.get('/courses', { params });
            setCourses(response.data);
            setPagination(parsePaginationMeta(response.meta));
        } catch (error) {
            showError('Failed to load courses');
        } finally {
            setLoading(false);
        }
    };

    const handleCreateCourse = async (formData) => {
        try {
            const response = await apiClient.post('/admin/courses', formData);
            showSuccess('Course created successfully!');
            loadCourses();
        } catch (error) {
            if (isValidationError(error)) {
                const fieldErrors = extractValidationErrors(error);
                setErrors(fieldErrors);
            } else {
                showError(error.message);
            }
        }
    };

    if (loading) return <div>Loading...</div>;

    return (
        <div>
            <h1>Courses</h1>
            {hasRole('admin') && (
                <button onClick={() => handleCreateCourse()}>
                    Create Course
                </button>
            )}
            {courses.map(course => (
                <div key={course.id}>{course.title}</div>
            ))}
        </div>
    );
}
```

---

## Provider Setup

Wrap your app with the necessary providers in the correct order:

```javascript
// resources/js/app.jsx or main entry point

import React from 'react';
import ReactDOM from 'react-dom/client';
import { AuthProvider } from '@/contexts/AuthContext';
import { ToastProvider } from '@/contexts/ToastContext';
import { ThemeProvider } from '@/contexts/ThemeContext';

function App({ initialUser, organizationBranding }) {
    return (
        <ThemeProvider organizationBranding={organizationBranding}>
            <AuthProvider initialUser={initialUser}>
                <ToastProvider>
                    {/* Your app routes/components */}
                </ToastProvider>
            </AuthProvider>
        </ThemeProvider>
    );
}

const root = ReactDOM.createRoot(document.getElementById('app'));
root.render(
    <App 
        initialUser={window.initialUser} 
        organizationBranding={window.organizationBranding}
    />
);
```

---

## Environment Variables

Add to `.env`:

```env
# API Base URL (defaults to /api/v1 if not set)
VITE_API_BASE_URL=/api/v1
```

---

## Next Steps (Phase 2)

With Phase 1 complete, you can now proceed to Phase 2:

1. **Convert Auth Pages** (`/login`, `/register`, `/reset-password`)
   - Replace Inertia form submissions with `AuthContext` methods
   - Use `ToastContext` for success/error feedback
   
2. **Convert Public Pages** (home, services, articles, etc.)
   - Fetch data from `/api/v1/public/*` endpoints
   - Use `buildQueryParams` for list pages
   
3. **Build Reusable Components**
   - Pagination component using `parsePaginationMeta`
   - Form error display using `extractValidationErrors`
   - Loading states

---

## Testing Recommendations

### Unit Tests
- Test API helper functions (pagination, filtering, sorting)
- Test error extraction logic
- Test AuthContext methods

### Integration Tests
- Test token refresh flow
- Test API error handling with ToastContext
- Test protected routes with AuthContext

### Manual Testing Checklist
- [ ] Login/logout flow works
- [ ] Token automatically refreshes on 401
- [ ] Toasts display for success/error
- [ ] Pagination helpers build correct params
- [ ] File uploads work with progress tracking
- [ ] Validation errors display correctly

---

## Migration Notes

### Breaking Changes from Inertia

| Inertia Pattern | API Pattern |
|-----------------|-------------|
| `Inertia.visit('/route')` | `const data = await apiClient.get('/route')` |
| `Inertia.post('/route', data)` | `await apiClient.post('/route', data)` |
| Server-side props | Fetch from API in `useEffect` |
| Form helper errors | `extractValidationErrors(error)` |
| Flash messages | `useToast()` |

### Backward Compatibility

Phase 1 is **non-breaking**. The existing Inertia frontend continues to work. These new utilities are available for new components or when converting existing pages.

---

## Known Issues / Future Enhancements

1. **Token Refresh Endpoint**: Ensure `/api/v1/auth/refresh` exists on the backend
2. **Cart State**: Cart is still session-based; needs stateless cart API for full API independence
3. **WebSocket Auth**: Echo/WebSocket connections may need token-based auth updates
4. **TypeScript**: Consider adding TypeScript definitions for better DX

---

## Deliverable Checklist

- [x] Centralized API client with envelope handling
- [x] Token refresh mechanism
- [x] Pagination, filtering, sorting helpers
- [x] File upload utilities with progress tracking
- [x] Error extraction and validation helpers
- [x] AuthContext for React-based auth state
- [x] ToastContext for global notifications
- [x] All helpers exported from `resources/js/api/index.js`
- [x] Documentation complete

---

## Contributors

- AI Assistant (Phase 1 implementation)

## References

- [FRONTEND_API_CONVERSION_PLAN.md](./FRONTEND_API_CONVERSION_PLAN.md)
- [FRONTEND_API_PAGE_MAP.md](./FRONTEND_API_PAGE_MAP.md)
- [API_CONVERSION_PLAN.md](./API_CONVERSION_PLAN.md)