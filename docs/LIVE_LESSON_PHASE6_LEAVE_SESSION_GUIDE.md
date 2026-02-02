# Live Lesson Phase 6: Leave Session Integration Guide

**Date:** 2025-10-19  
**Status:** Backend complete, frontend integration required

---

## ✅ **Backend Complete**

### **Endpoint Created:**
- **Method:** `POST`
- **Route:** `/live-sessions/{sessionId}/leave`
- **Controller:** `LiveLessonController::studentLeave()`
- **Route Name:** `parent.live-sessions.leave`

### **What It Does:**
1. Finds the student's participant record
2. Updates `status` from `'joined'` to `'left'`
3. Sets `left_at` timestamp
4. Teacher panel automatically removes them from participant list (already filters for `status === 'joined'`)

---

## 🔧 **Frontend Integration (LivePlayer.jsx)**

### **Step 1: Add Cleanup on Component Unmount**

When the student navigates away or closes the page, we need to call the leave endpoint.

```jsx
import { useEffect } from 'react';
import axios from 'axios';

// Inside LivePlayer component
useEffect(() => {
    // Cleanup function runs when component unmounts
    return () => {
        // Call leave endpoint when student leaves
        axios.post(`/live-sessions/${session.id}/leave`)
            .catch(error => {
                // Silently fail - user is already leaving
                console.log('Leave session error:', error);
            });
    };
}, [session.id]);
```

### **Step 2: Handle Browser/Tab Close**

When the user closes the browser tab, we need to use `beforeunload` event and `sendBeacon` for reliability:

```jsx
useEffect(() => {
    const handleBeforeUnload = (e) => {
        // Use navigator.sendBeacon for reliable delivery
        // This works even when the page is being closed
        const url = `/live-sessions/${session.id}/leave`;
        const data = new Blob([JSON.stringify({})], { type: 'application/json' });
        
        navigator.sendBeacon(url, data);
        
        // Optional: Show confirmation dialog
        // e.preventDefault();
        // e.returnValue = '';
    };
    
    window.addEventListener('beforeunload', handleBeforeUnload);
    
    return () => {
        window.removeEventListener('beforeunload', handleBeforeUnload);
    };
}, [session.id]);
```

### **Step 3: Optional - Manual Leave Button**

You can also add an explicit "Leave Session" button:

```jsx
const handleLeaveSession = async () => {
    try {
        await axios.post(`/live-sessions/${session.id}/leave`);
        
        // Redirect to sessions browse page
        window.location.href = route('parent.live-sessions.index');
    } catch (error) {
        console.error('Failed to leave session:', error);
        // Still redirect even if API fails
        window.location.href = route('parent.live-sessions.index');
    }
};

// In your JSX:
<button
    onClick={handleLeaveSession}
    className="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded"
>
    Leave Session
</button>
```

---

## 📝 **Complete Implementation Example**

Here's the complete code for `LivePlayer.jsx`:

```jsx
import { useEffect } from 'react';
import axios from 'axios';

export default function LivePlayer({ session, lesson, progress }) {
    // ... other code ...
    
    // Cleanup when component unmounts (navigation, back button, etc.)
    useEffect(() => {
        return () => {
            axios.post(`/live-sessions/${session.id}/leave`)
                .catch(console.log);
        };
    }, [session.id]);
    
    // Handle browser/tab close
    useEffect(() => {
        const handleBeforeUnload = (e) => {
            const url = `/live-sessions/${session.id}/leave`;
            const data = new Blob([JSON.stringify({})], { type: 'application/json' });
            navigator.sendBeacon(url, data);
        };
        
        window.addEventListener('beforeunload', handleBeforeUnload);
        return () => window.removeEventListener('beforeunload', handleBeforeUnload);
    }, [session.id]);
    
    // Manual leave handler
    const handleLeaveSession = async () => {
        try {
            await axios.post(`/live-sessions/${session.id}/leave`);
        } catch (error) {
            console.error('Leave error:', error);
        } finally {
            window.location.href = route('parent.live-sessions.index');
        }
    };
    
    return (
        <div>
            {/* Header with leave button */}
            <div className="flex justify-between items-center p-4 bg-gray-100">
                <h1>{lesson.title}</h1>
                <button
                    onClick={handleLeaveSession}
                    className="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded"
                >
                    Leave Session
                </button>
            </div>
            
            {/* ... rest of your component ... */}
        </div>
    );
}
```

---

## 🧪 **Testing the Integration**

### **Test 1: Component Unmount (Navigation)**
1. Join a session
2. Check teacher panel - you should see the student
3. Navigate to another page (e.g., click browser back button)
4. Check teacher panel - student should be gone

### **Test 2: Browser/Tab Close**
1. Join a session
2. Check teacher panel - you should see the student
3. Close the browser tab
4. Check teacher panel - student should be gone

### **Test 3: Manual Leave Button**
1. Join a session
2. Click "Leave Session" button
3. Should redirect to browse page
4. Check teacher panel - student should be gone

### **Test 4: Database Verification**
```sql
SELECT * FROM live_session_participants 
WHERE live_lesson_session_id = [session_id] 
ORDER BY left_at DESC;
```

Should show:
- `status` = `'left'`
- `left_at` timestamp populated

---

## ⚠️ **Important Notes**

### **1. CSRF Token Required**

Since this is a POST request, ensure CSRF token is included:

```jsx
// Axios should automatically include it if properly configured in bootstrap.js
// Verify your bootstrap.js has:
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
let token = document.head.querySelector('meta[name="csrf-token"]');
if (token) {
    axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
}
```

### **2. sendBeacon Limitations**

`navigator.sendBeacon()` has some limitations:
- Only sends POST requests
- Limited payload size (64KB)
- Cannot read response
- Best for simple tracking requests

For our use case, it's perfect because we just need to notify the backend the student left.

### **3. Network Failures**

The cleanup code uses `.catch(console.log)` to silently fail if the network request fails. This is intentional because:
- User is already leaving - no point showing errors
- Teacher panel will eventually update when websocket connection drops
- Database record will still show last `joined_at` time for analytics

### **4. Inertia Link Navigation**

If using Inertia links for navigation, the `useEffect` cleanup will handle it automatically:

```jsx
<Link href={route('parent.live-sessions.index')}>
    Back to Sessions
</Link>
```

The component will unmount and trigger the cleanup function.

---

## 📊 **Teacher Panel Behavior**

Once a student leaves:

1. **Backend:** `status` changes to `'left'`, `left_at` is set
2. **Teacher Panel:** Participant disappears (filtered by `status === 'joined'`)
3. **Analytics:** Full session duration tracked (`joined_at` → `left_at`)

No additional frontend work needed in TeacherPanel - the filter is already in place!

---

## 🎯 **Success Criteria**

- [x] Backend endpoint created
- [x] Route configured
- [ ] useEffect cleanup added to LivePlayer
- [ ] beforeunload handler added
- [ ] Manual leave button added (optional)
- [ ] CSRF token configured
- [ ] Tested navigation away
- [ ] Tested browser close
- [ ] Teacher panel updates in real-time

---

## 🚀 **Estimated Implementation Time**

- **10 minutes** - Add useEffect hooks
- **5 minutes** - Add leave button (optional)
- **10 minutes** - Testing

**Total: ~25 minutes**

---

**All backend work is complete!** Just add the frontend hooks and you're done! 🎉
