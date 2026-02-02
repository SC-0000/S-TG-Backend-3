# Frontend API Conversion - Phase 3 Parent Portal Inventory

**Created:** February 2, 2026  
**Status:** Complete Inventory

---

## 📊 Parent Portal Pages Inventory

**Total Pages:** 45+ components/pages

---

## 📁 Categorized Page Structure

### 1. Dashboard & Home (Priority: HIGH)
| Page | Path | Type | Complexity | API Endpoint |
|------|------|------|------------|--------------|
| Home Dashboard | Main/Home.jsx | Dashboard | Medium | `/api/v1/parent/dashboard` |
| Home v2 | Main/Home2.jsx | Dashboard | Medium | `/api/v1/parent/dashboard` |
| Progress Tracker | Main/ProgressTracker.jsx | Dashboard | High | `/api/v1/parent/progress` |
| Progress Tracker v2 | Main/ProgressTracker2.jsx | Dashboard | High | `/api/v1/parent/progress` |

**Priority:** Start here - main entry points for users

---

### 2. Courses & Content (Priority: HIGH)
| Page | Path | Type | Complexity | API Endpoint |
|------|------|------|------------|--------------|
| Browse Courses | Courses/Browse.jsx | List | Medium | `/api/v1/parent/courses` |
| My Courses | Courses/MyCourses.jsx | List | Medium | `/api/v1/parent/courses/enrolled` |
| Course Details | Courses/Show.jsx | Detail | Medium | `/api/v1/parent/courses/{id}` |
| Browse Lessons | Lessons/Browse.jsx | List | Medium | `/api/v1/parent/lessons` |
| Lessons Index | Lessons/Index.jsx | List | Medium | `/api/v1/parent/lessons` |
| Lesson Details | Lessons/Show.jsx | Detail | Medium | `/api/v1/parent/lessons/{id}` |

**Priority:** Core functionality - users browse and access courses

---

### 3. Lesson Player (Priority: HIGH)
| Page | Path | Type | Complexity | API Endpoint |
|------|------|------|------------|--------------|
| Content Lesson Player | ContentLessons/Player.jsx | Interactive | High | `/api/v1/parent/lessons/{id}/play` |
| Live Lesson Player | ContentLessons/LivePlayer.jsx | Interactive | Very High | `/api/v1/parent/live-sessions/{id}/join` |
| Content Browse | ContentLessons/Browse.jsx | List | Medium | `/api/v1/parent/content` |
| Lesson Summary | ContentLessons/Summary.jsx | Summary | Low | `/api/v1/parent/lessons/{id}/summary` |

**Priority:** Critical feature - complex state management needed

---

### 4. Assessments (Priority: HIGH)
| Page | Path | Type | Complexity | API Endpoint |
|------|------|------|------------|--------------|
| Browse Assessments | Assessments/Browse.jsx | List | Medium | `/api/v1/parent/assessments` |
| Assessment Index | Assessments/Index.jsx | List | Medium | `/api/v1/parent/assessments` |
| Assessment Attempt | Assessments/Attempt.jsx | Interactive | High | `/api/v1/parent/assessments/{id}/attempt` |
| My Submissions | Assessments/MySubmissions.jsx | List | Medium | `/api/v1/parent/submissions` |
| Submission Detail | Submissions/Show.jsx | Detail | Medium | `/api/v1/parent/submissions/{id}` |

**Priority:** Core learning feature - timer and auto-save needed

---

### 5. Live Sessions (Priority: MEDIUM)
| Page | Path | Type | Complexity | API Endpoint |
|------|------|------|------------|--------------|
| Browse Sessions | LiveSessions/Browse.jsx | List | Medium | `/api/v1/parent/live-sessions` |
| My Sessions | LiveSessions/MySessions.jsx | List | Medium | `/api/v1/parent/live-sessions/mine` |
| Session Details | LiveSessions/SessionDetails.jsx | Detail | Medium | `/api/v1/parent/live-sessions/{id}` |

**Priority:** Important but may use existing infrastructure

---

### 6. Journeys & Progress (Priority: MEDIUM)
| Page | Path | Type | Complexity | API Endpoint |
|------|------|------|------------|--------------|
| Journey Overview | Journeys/Overview.jsx | Dashboard | Medium | `/api/v1/parent/journeys` |

**Priority:** Nice to have - enhances user experience

---

### 7. Profile & Settings (Priority: MEDIUM)
| Page | Path | Type | Complexity | API Endpoint |
|------|------|------|------------|--------------|
| Profile Edit | Profile/Edit.jsx | Form | Medium | `/api/v1/me` |
| Account Section | Profile/components/AccountSection.jsx | Component | Low | N/A |
| Profile Section | Profile/components/ProfileSection.jsx | Component | Low | N/A |
| Security Section | Profile/components/SecuritySection.jsx | Component | Medium | `/api/v1/me/security` |
| Portal Section | Profile/components/PortalSection.jsx | Component | Low | N/A |
| Feedback Section | Profile/components/FeedbackSection.jsx | Component | Low | N/A |
| Tab Switcher | Profile/components/TabSwitcher.jsx | Component | Low | N/A |
| Breadcrumbs | Profile/components/Breadcrumbs.jsx | Component | Low | N/A |
| Delete User Form | Profile/Partials/DeleteUserForm.jsx | Form | Low | `/api/v1/me` |
| Update Password | Profile/Partials/UpdatePasswordForm.jsx | Form | Low | `/api/v1/me/password` |
| Update Profile Info | Profile/Partials/UpdateProfileInformationForm.jsx | Form | Low | `/api/v1/me` |

**Priority:** Standard functionality - use Phase 2 patterns

---

### 8. Schedule & Calendar (Priority: MEDIUM)
| Page | Path | Type | Complexity | API Endpoint |
|------|------|------|------------|--------------|
| Calendar View | Schedule/Calender.jsx | Calendar | High | `/api/v1/parent/schedule` |
| Deadlines | Schedule/Deadlines.jsx | List | Low | `/api/v1/parent/deadlines` |
| Schedule View | Schedule/Schedule.jsx | List | Medium | `/api/v1/parent/schedule` |

**Priority:** Useful but not critical path

---

### 9. AI Features (Priority: LOW-MEDIUM)
| Page | Path | Type | Complexity | API Endpoint |
|------|------|------|------------|--------------|
| AI Console | AI/AIConsolePage.jsx | Interactive | High | `/api/v1/parent/ai/console` |
| AI Hub Demo | AI/AIHubDemo.jsx | Demo | Medium | `/api/v1/parent/ai/hub` |
| AI Chat | ChatAI/Chat.jsx | Interactive | High | `/api/v1/parent/ai/chat` |

**Priority:** Advanced feature - may need WebSocket

---

### 10. Services & Products (Priority: LOW)
| Page | Path | Type | Complexity | API Endpoint |
|------|------|------|------------|--------------|
| Services | Main/Services.jsx | List | Low | `/api/v1/parent/services` |
| Service Details | Services/Show.jsx | Detail | Low | `/api/v1/parent/services/{id}` |
| Products | Main/Products.jsx | List | Low | `/api/v1/parent/products` |
| Products Hub | Main/ProductsHub.jsx | Hub | Medium | `/api/v1/parent/products` |
| Products Index | Products/Index.jsx | List | Low | `/api/v1/parent/products` |

**Priority:** Marketing/catalog - not critical path

---

### 11. Notifications & Feedback (Priority: LOW)
| Page | Path | Type | Complexity | API Endpoint |
|------|------|------|------------|--------------|
| Notifications | Notifications/Index.jsx | List | Low | `/api/v1/parent/notifications` |
| FAQ | Main/Faq.jsx | Static | Low | `/api/v1/public/faqs` |
| Create Feedback | ParentFeedback/Create.jsx | Form | Low | `/api/v1/parent/feedback` |
| Feedback Form | ParentFeedback/FeedbackForm.jsx | Form | Low | `/api/v1/parent/feedback` |

**Priority:** Nice to have

---

### 12. Auth (Priority: LOW)
| Page | Path | Type | Complexity | API Endpoint |
|------|------|------|------------|--------------|
| Confirm Password | Auth/ConfirmPassword.jsx | Form | Low | `/api/v1/auth/password/confirm` |

**Priority:** May already be handled in Phase 2

---

## 📈 Conversion Priority Matrix

### Phase 3.1: Foundation (Week 1) - HIGH PRIORITY
**Goal:** Get basic navigation and viewing working

1. **Home Dashboard** (Main/Home.jsx)
   - Entry point
   - Sets tone for rest of portal
   - Complexity: Medium

2. **Course Browsing** (Courses/Browse.jsx)
   - Core functionality
   - List pattern
   - Complexity: Medium

3. **My Courses** (Courses/MyCourses.jsx)
   - Personalized view
   - Similar to Browse
   - Complexity: Medium

4. **Course Details** (Courses/Show.jsx)
   - Detail page pattern
   - Complexity: Medium

5. **Profile Edit** (Profile/Edit.jsx)
   - User management
   - Form pattern from Phase 2
   - Complexity: Medium

---

### Phase 3.2: Core Learning (Week 2) - HIGH PRIORITY
**Goal:** Enable actual learning activities

6. **Lesson Browser** (Lessons/Browse.jsx)
7. **Lesson Details** (Lessons/Show.jsx)
8. **Basic Lesson Player** (ContentLessons/Player.jsx)
   - Most complex
   - Video/audio playback
   - Progress tracking
9. **Assessment Browser** (Assessments/Browse.jsx)
10. **Assessment Attempt** (Assessments/Attempt.jsx)
    - Timer functionality
    - Auto-save
11. **My Submissions** (Assessments/MySubmissions.jsx)

---

### Phase 3.3: Enhanced Features (Week 3) - MEDIUM PRIORITY
**Goal:** Complete user experience

12. **Progress Tracker** (Main/ProgressTracker.jsx)
13. **Journey Overview** (Journeys/Overview.jsx)
14. **Live Sessions** (LiveSessions/Browse.jsx, MySessions.jsx)
15. **Schedule/Calendar** (Schedule/)
16. **Profile Components** (All profile sections)
17. **Notifications** (Notifications/Index.jsx)

---

### Phase 3.4: Advanced & Polish (Week 4) - LOW-MEDIUM PRIORITY
**Goal:** Complete all features

18. **AI Features** (AI/, ChatAI/)
19. **Live Lesson Player** (ContentLessons/LivePlayer.jsx)
20. **Services & Products** (Services/, Products/)
21. **Feedback** (ParentFeedback/)
22. **FAQ** (Main/Faq.jsx)

---

## 🎨 Shared Components Needed

### Essential (Create First)
1. **Card** - For course cards, lesson cards, etc.
2. **ProgressBar** - For progress tracking
3. **EmptyState** - When no data
4. **PageHeader** - Consistent page headers

### Important (Create Second)
5. **DataTable** - For lists with pagination
6. **Modal** - For dialogs and confirmations
7. **Tabs** - For tabbed interfaces
8. **Breadcrumbs** - For navigation

### Nice to Have (Create Later)
9. **Calendar** - For schedule
10. **Timer** - For assessments
11. **VideoPlayer** - For lessons
12. **ChatBubble** - For AI chat

---

## 📊 Statistics

**Total Components:** 45+ pages/components  
**High Priority:** 15 pages  
**Medium Priority:** 20 pages  
**Low Priority:** 10 pages

**Estimated Conversion Time:**
- High Priority: 6-8 days
- Medium Priority: 4-6 days
- Low Priority: 2-3 days
- **Total:** 12-17 days

---

## 🎯 Success Metrics

**Completion Criteria:**
- [ ] All pages use API calls
- [ ] No Inertia props remaining
- [ ] Consistent loading states
- [ ] Consistent error handling
- [ ] Toast notifications throughout
- [ ] Shared components in use
- [ ] All critical paths tested

**Quality Targets:**
- Code reduction: 50%+
- Load time: < 2s
- Error handling: 100%
- User feedback: Consistent

---

## 🚀 Ready to Convert!

**Next Steps:**
1. Create shared components (Card, ProgressBar, EmptyState)
2. Convert Home.jsx (dashboard)
3. Convert Courses/Browse.jsx
4. Continue with priority list

---

*Last Updated: February 2, 2026*  
*Status: Ready for Implementation*