# Frontend API Conversion - Phase 2 Next Steps

**Date:** February 2, 2026  
**Status:** Phase 2 actively in progress (10% complete)

---

## 🎉 What's Been Accomplished

### Phase 1: Complete Foundation ✅
All infrastructure is in place and ready to use:
- ✅ API client with automatic token refresh
- ✅ Pagination, filtering, sorting helpers  
- ✅ File upload utilities
- ✅ AuthContext for authentication state
- ✅ ToastContext for notifications
- ✅ Comprehensive documentation

### Phase 2: Started Implementation ✅
- ✅ **Provider Integration**: AuthProvider and ToastProvider added to app.jsx
- ✅ **Login Page Converted**: Using AuthContext and ToastContext
- ✅ **Documentation**: Progress tracking and patterns documented

---

## 📝 Available Auth Pages (Current State)

Based on the directory listing, these auth pages exist:
1. ✅ **Login.jsx** - CONVERTED (using AuthContext + ToastContext)
2. ❓ **ForgotPassword.jsx** - Needs conversion
3. ❓ **ResetPassword.jsx** - Needs conversion
4. ❓ **VerifyEmail.jsx** - Needs conversion
5. ❓ **GuestComplete.jsx** - Needs conversion
6. ❓ **PreLogin.jsx** - Needs review

**Note:** Register.jsx doesn't exist - may need to be created or is handled elsewhere.

---

## 🚀 Next Immediate Steps

### Option 1: Continue Converting Auth Pages
Convert the remaining auth pages following the Login.jsx pattern:

#### ForgotPassword.jsx Conversion
```jsx
import { useState } from 'react';
import { apiClient } from '@/api';
import { useToast } from '@/contexts/ToastContext';
import { extractValidationErrors } from '@/api';

export default function ForgotPassword() {
  const { showSuccess, showError } = useToast();
  const [email, setEmail] = useState('');
  const [loading, setLoading] = useState(false);
  const [errors, setErrors] = useState({});

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    setErrors({});

    try {
      await apiClient.post('/auth/password/forgot', { email }, {
        useToken: false
      });
      showSuccess('Password reset link sent! Check your email.');
    } catch (error) {
      const fieldErrors = extractValidationErrors(error);
      setErrors(fieldErrors);
      showError(error.message || 'Failed to send reset link');
    } finally {
      setLoading(false);
    }
  };

  return (
    <form onSubmit={handleSubmit}>
      <input
        type="email"
        value={email}
        onChange={(e) => setEmail(e.target.value)}
        disabled={loading}
      />
      {errors.email && <span className="error">{errors.email}</span>}
      <button type="submit" disabled={loading}>
        {loading ? 'Sending...' : 'Send Reset Link'}
      </button>
    </form>
  );
}
```

#### ResetPassword.jsx Conversion
```jsx
import { useState } from 'react';
import { apiClient } from '@/api';
import { useToast } from '@/contexts/ToastContext';
import { extractValidationErrors } from '@/api';

export default function ResetPassword({ token, email }) {
  const { showSuccess, showError } = useToast();
  const [form, setForm] = useState({
    password: '',
    password_confirmation: ''
  });
  const [loading, setLoading] = useState(false);
  const [errors, setErrors] = useState({});

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    setErrors({});

    try {
      await apiClient.post('/auth/password/reset', {
        token,
        email,
        ...form
      }, { useToken: false });
      
      showSuccess('Password reset successful! You can now login.');
      setTimeout(() => {
        window.location.href = '/login';
      }, 1500);
    } catch (error) {
      const fieldErrors = extractValidationErrors(error);
      setErrors(fieldErrors);
      showError(error.message || 'Failed to reset password');
    } finally {
      setLoading(false);
    }
  };

  return (
    <form onSubmit={handleSubmit}>
      <input
        type="password"
        value={form.password}
        onChange={(e) => setForm({ ...form, password: e.target.value })}
      />
      {errors.password && <span>{errors.password}</span>}
      
      <input
        type="password"
        value={form.password_confirmation}
        onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })}
      />
      
      <button type="submit" disabled={loading}>
        {loading ? 'Resetting...' : 'Reset Password'}
      </button>
    </form>
  );
}
```

### Option 2: Create Reusable Form Components
Build shared components to accelerate future conversions:

#### TextInput Component
```jsx
// resources/js/components/Form/TextInput.jsx
export default function TextInput({ 
  label, 
  error, 
  className = '',
  ...props 
}) {
  return (
    <div className={className}>
      {label && (
        <label className="block text-sm font-medium mb-1">
          {label}
        </label>
      )}
      <input
        className={`w-full px-4 py-3 rounded-lg border ${
          error ? 'border-red-500' : 'border-gray-300'
        } focus:outline-none focus:ring-2 focus:ring-blue-400`}
        {...props}
      />
      {error && (
        <span className="text-red-500 text-xs mt-1 block">{error}</span>
      )}
    </div>
  );
}
```

#### Button Component
```jsx
// resources/js/components/Form/Button.jsx
export default function Button({ 
  loading, 
  children, 
  className = '',
  ...props 
}) {
  return (
    <button
      className={`px-6 py-3 rounded-lg font-semibold transition-all ${
        loading ? 'opacity-50 cursor-not-allowed' : 'hover:scale-105'
      } ${className}`}
      disabled={loading}
      {...props}
    >
      {loading ? (
        <span className="flex items-center gap-2">
          <svg className="animate-spin h-5 w-5" viewBox="0 0 24 24">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
          </svg>
          Loading...
        </span>
      ) : (
        children
      )}
    </button>
  );
}
```

#### LoadingSpinner Component
```jsx
// resources/js/components/LoadingSpinner.jsx
export default function LoadingSpinner({ size = 'md', className = '' }) {
  const sizes = {
    sm: 'w-4 h-4',
    md: 'w-8 h-8',
    lg: 'w-12 h-12',
  };

  return (
    <div className={`flex justify-center items-center ${className}`}>
      <svg 
        className={`animate-spin ${sizes[size]} text-blue-500`} 
        viewBox="0 0 24 24"
      >
        <circle 
          className="opacity-25" 
          cx="12" 
          cy="12" 
          r="10" 
          stroke="currentColor" 
          strokeWidth="4" 
          fill="none" 
        />
        <path 
          className="opacity-75" 
          fill="currentColor" 
          d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" 
        />
      </svg>
    </div>
  );
}
```

### Option 3: Convert Public Pages
Start converting public pages to be fully API-driven:

#### Home.jsx Cleanup Pattern
```jsx
import { useEffect, useState } from 'react';
import { apiClient } from '@/api';
import { useToast } from '@/contexts/ToastContext';
import LoadingSpinner from '@/components/LoadingSpinner';

export default function Home() {
  const { showError } = useToast();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchData = async () => {
      try {
        setLoading(true);
        const response = await apiClient.get('/public/home', {
          useToken: false
        });
        setData(response.data);
      } catch (error) {
        showError('Failed to load page data');
      } finally {
        setLoading(false);
      }
    };

    fetchData();
  }, []);

  if (loading) return <LoadingSpinner size="lg" />;
  if (!data) return <div>No data available</div>;

  return (
    <div>
      <HeroSection testimonials={data.testimonials} />
      <Carousel3D slides={data.slides} />
      {/* ... rest of page */}
    </div>
  );
}
```

---

## 📊 Progress Tracking

### Current Status
| Component | Status | Notes |
|-----------|--------|-------|
| Login.jsx | ✅ Complete | Using AuthContext + ToastContext |
| ForgotPassword.jsx | ⏳ Todo | Pattern ready (see above) |
| ResetPassword.jsx | ⏳ Todo | Pattern ready (see above) |
| VerifyEmail.jsx | ⏳ Todo | Needs investigation |
| GuestComplete.jsx | ⏳ Todo | Needs investigation |
| Home.jsx | 🔄 Partial | Uses API but needs cleanup |
| Shared Components | ⏳ Todo | Patterns ready (see above) |

### Estimated Completion Time
- **Remaining auth pages**: 2-3 hours
- **Shared components**: 2-3 hours  
- **Public pages cleanup**: 4-6 hours
- **Testing**: 2-3 hours

**Total Phase 2 remaining**: ~10-15 hours

---

## 🎯 Recommended Next Action

### Immediate (Do This Next)
1. **Convert ForgotPassword.jsx** using the pattern above
2. **Convert ResetPassword.jsx** using the pattern above
3. **Test both flows** to ensure they work end-to-end

### After Auth Pages
1. **Create shared components** (TextInput, Button, LoadingSpinner)
2. **Update existing pages** to use shared components
3. **Clean up Home.jsx** to remove Inertia dependencies

### Testing Checklist
- [ ] Login with valid credentials
- [ ] Login with invalid credentials
- [ ] Forgot password flow
- [ ] Reset password flow
- [ ] Toast notifications appear correctly
- [ ] Form validation works
- [ ] Loading states display properly
- [ ] All redirects work correctly

---

## 📚 Resources

### Documentation
- [Phase 1 Complete Guide](./FRONTEND_API_PHASE1_COMPLETE.md)
- [Phase 2 Progress Tracker](./FRONTEND_API_PHASE2_PROGRESS.md)
- [Overall Status](./FRONTEND_API_CONVERSION_STATUS.md)

### Code Examples
- Login page: `resources/js/public/Pages/Auth/Login.jsx`
- Auth context: `resources/js/contexts/AuthContext.jsx`
- Toast context: `resources/js/contexts/ToastContext.jsx`
- API client: `resources/js/api/client.js`

### Patterns to Follow
- **Form submission**: See Login.jsx `handleSubmit`
- **Error handling**: See Login.jsx error extraction
- **Toast notifications**: See Login.jsx success/error flows
- **Data fetching**: See Home.jsx `useEffect` pattern

---

## 🐛 Known Issues to Watch For

1. **Token Storage**: Ensure tokens are being saved correctly in localStorage
2. **CSRF Token**: May still need CSRF token for some legacy endpoints
3. **Redirects**: Currently using `window.location.href` (full reload)
4. **Session State**: Some features still rely on Laravel sessions

---

**Ready to continue! Choose one of the options above and let's keep building! 🚀**