# Frontend API Conversion - Phase 2 Progress

**Status:** 🔄 In Progress  
**Started:** February 2, 2026

## Phase 2: Auth + Public Pages Conversion

### Overview
Converting authentication flows and public pages from Inertia-based to pure API-driven architecture using the Phase 1 infrastructure (AuthContext, ToastContext, API client).

---

## ✅ Completed

### 1. Provider Integration (`resources/js/app.jsx`)
- [x] Integrated `AuthProvider` into app root
- [x] Integrated `ToastProvider` for global notifications
- [x] Configured provider hierarchy: `ThemeProvider` → `AuthProvider` → `ToastProvider`
- [x] Pass `initialUser` from Inertia props to AuthContext

**Provider Hierarchy:**
```jsx
<ThemeProvider organizationBranding={branding}>
  <AuthProvider initialUser={initialUser}>
    <ToastProvider>
      <App {...props} />
    </ToastProvider>
  </AuthProvider>
</ThemeProvider>
```

### 2. Login Page Conversion (`resources/js/public/Pages/Auth/Login.jsx`)
- [x] Replaced old `authStore` with `useAuth()` hook
- [x] Integrated `useToast()` for success/error notifications
- [x] Simplified form submission using `AuthContext.login()`
- [x] Added smooth redirect with success message
- [x] Improved error handling with `extractValidationErrors()`

**Key Changes:**
```jsx
// Before (old authStore)
import { setAuthToken, setCurrentUser } from '@/stores/authStore';
const response = await apiClient.post('/auth/login', credentials);
setAuthToken(response.data.token);
setCurrentUser(response.data.user);

// After (AuthContext)
const { login } = useAuth();
const { showSuccess, showError } = useToast();
const result = await login(credentials);
if (result.success) {
  showSuccess('Login successful!');
  // redirect
} else {
  showError(result.error.message);
}
```

---

## 🔄 In Progress

### 3. Testing Login Flow
- [ ] Test login with valid credentials
- [ ] Test login with invalid credentials
- [ ] Verify toast notifications appear
- [ ] Verify token is stored correctly
- [ ] Verify redirect works for all user roles
- [ ] Test "Remember me" functionality

---

## 📋 Remaining Tasks

### Auth Pages (High Priority)
- [ ] **Register Page** (`resources/js/public/Pages/Auth/Register.jsx`)
  - Convert to use `AuthContext.register()`
  - Add toast notifications
  - Handle email verification flow
  
- [ ] **Forgot Password** (`resources/js/public/Pages/Auth/ForgotPassword.jsx`)
  - Convert to API call
  - Add success/error toasts
  
- [ ] **Reset Password** (`resources/js/public/Pages/Auth/ResetPassword.jsx`)
  - Convert to API call
  - Handle password reset token validation
  
- [ ] **Verify Email** (`resources/js/public/Pages/Auth/VerifyEmail.jsx`)
  - Convert to API call
  - Add email resend functionality
  
- [ ] **Guest Complete** (`resources/js/public/Pages/Auth/GuestComplete.jsx`)
  - Convert to API call
  - Handle guest-to-parent upgrade flow

### Public Pages (Medium Priority)
- [ ] **Home Page** (`resources/js/public/Pages/Main/Home.jsx`)
  - Already makes API calls, needs cleanup
  - Remove dependency on Inertia initial props
  - Add loading states
  
- [ ] **About Us** (`resources/js/public/Pages/Main/AboutUs.jsx`)
- [ ] **Contact Us** (`resources/js/public/Pages/Main/ContactUs.jsx`)
- [ ] **Services** (Various service pages)
- [ ] **Articles** (Article list and detail pages)

### Shared Components
- [ ] Create reusable form components
  - TextInput with validation display
  - Button with loading state
  - Form wrapper with error handling
- [ ] Create reusable loading components
  - Spinner
  - Skeleton loaders
  - Page loading indicator

---

## Technical Approach

### Form Handling Pattern
```jsx
import { useAuth } from '@/contexts/AuthContext';
import { useToast } from '@/contexts/ToastContext';
import { extractValidationErrors, isValidationError } from '@/api';

function MyForm() {
  const { login, register } = useAuth();
  const { showSuccess, showError } = useToast();
  const [loading, setLoading] = useState(false);
  const [errors, setErrors] = useState({});

  const handleSubmit = async (formData) => {
    setLoading(true);
    setErrors({});

    const result = await login(formData); // or register, etc.

    setLoading(false);

    if (result.success) {
      showSuccess('Success message');
      // redirect or next action
    } else {
      if (isValidationError(result.error)) {
        setErrors(extractValidationErrors(result.error));
      }
      showError(result.error.message);
    }
  };

  return (
    <form onSubmit={handleSubmit}>
      {/* form fields */}
      {errors.email && <span className="error">{errors.email}</span>}
    </form>
  );
}
```

### Data Fetching Pattern (Public Pages)
```jsx
import { useEffect, useState } from 'react';
import { apiClient } from '@/api';
import { useToast } from '@/contexts/ToastContext';

function PublicPage() {
  const { showError } = useToast();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchData = async () => {
      try {
        setLoading(true);
        const response = await apiClient.get('/public/some-endpoint', {
          useToken: false // public endpoints don't need auth
        });
        setData(response.data);
      } catch (error) {
        showError('Failed to load data');
      } finally {
        setLoading(false);
      }
    };

    fetchData();
  }, []);

  if (loading) return <LoadingSpinner />;

  return <div>{/* render data */}</div>;
}
```

---

## Migration Notes

### Backward Compatibility
- ✅ Existing Inertia pages continue to work
- ✅ New API-based pages can coexist with Inertia pages
- ✅ Gradual migration is possible

### Testing Strategy
1. **Manual Testing**
   - Test each auth flow (login, register, reset, verify)
   - Test with different user roles
   - Test error scenarios (invalid credentials, network errors)
   - Test on different browsers

2. **Automated Testing** (Future)
   - Add Cypress/Playwright tests for auth flows
   - Add unit tests for form validation
   - Add integration tests for API calls

---

## Known Issues

### Issues to Address
1. **Token Refresh Endpoint**
   - Backend needs `/api/v1/auth/refresh` endpoint
   - Currently client has refresh logic but no backend endpoint

2. **Redirect After Login**
   - Currently uses `window.location.href` (full page reload)
   - Should eventually use client-side routing

3. **Session Management**
   - Some features still use Laravel sessions (e.g., cart)
   - Need to convert to token-based

---

## Next Steps

1. **Immediate (This Week)**
   - Test login page conversion
   - Convert register page
   - Convert password reset flow
   - Create reusable form components

2. **Short Term (Next Week)**
   - Convert all auth pages
   - Clean up Home page to be fully API-driven
   - Create loading components
   - Add error boundaries

3. **Medium Term (Next 2 Weeks)**
   - Convert remaining public pages
   - Create comprehensive testing suite
   - Document migration patterns
   - Create developer guide

---

## Progress Metrics

| Metric | Status |
|--------|--------|
| Auth Pages Converted | 1/6 (17%) |
| Public Pages Converted | 0/7 (0%) |
| Shared Components Created | 0/10 (0%) |
| Tests Written | 0/20 (0%) |
| Overall Phase 2 Progress | ~10% |

---

## Resources

- [Phase 1 Documentation](./FRONTEND_API_PHASE1_COMPLETE.md)
- [API Client Reference](./FRONTEND_API_PHASE1_COMPLETE.md#api-client)
- [AuthContext Reference](./FRONTEND_API_PHASE1_COMPLETE.md#authcontext)
- [ToastContext Reference](./FRONTEND_API_PHASE1_COMPLETE.md#toastcontext)

---

**Last Updated:** February 2, 2026  
**Next Review:** When all auth pages are converted