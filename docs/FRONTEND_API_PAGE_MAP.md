# Frontend → API Page Map (Phase 0)

Purpose: inventory all frontend pages by role and map each feature area to the `/api/v1` endpoints it should call.

## Public
- Main pages: `public/Pages/Main/*` (Home, Landing, Welcome, AboutUs, ContactUs, Dashboard)
  - API: `GET /api/v1/public/home`, `GET /api/v1/public/about`, `GET /api/v1/public/contact`, `GET /api/v1/public/pages` (aggregated)
- Services: `public/Pages/Services/*`
  - API: `GET /api/v1/services`, `GET /api/v1/services/{service}`, `GET /api/v1/services/{service}/available-content`, `POST /api/v1/services/{service}/selection`
- Articles: `public/Pages/Articles/*`
  - API: `GET /api/v1/public/articles`, `GET /api/v1/public/articles/{article}`
- FAQs/Alerts/Slides/Testimonials (used on marketing pages)
  - API: `GET /api/v1/public/faqs`, `GET /api/v1/public/alerts`, `GET /api/v1/public/slides`, `GET /api/v1/public/testimonials`
- Subscriptions catalog: `public/Pages/Subscriptions/Catalog.jsx`
  - API: `GET /api/v1/subscription-plans`
- Applications: `public/Pages/Applications/*`
  - API: `POST /api/v1/applications`, `GET /api/v1/applications/verify/{token}`, `POST /api/v1/applications/resend-verification`
- Checkout + cart: `public/Pages/Checkout/Index.jsx`
  - API: `GET/POST/PATCH/DELETE /api/v1/cart/*`, `POST /api/v1/checkout`, `POST /api/v1/checkout/guest/*`
- Billing + receipts: `public/Pages/Billing/*`, `public/Pages/Transactions/*`, `public/Pages/Payments/*`
  - API: `GET /api/v1/billing`, `GET /api/v1/billing/invoices`, `GET /api/v1/billing/payments`, `POST /api/v1/billing/portal`, `GET /api/v1/transactions`, `GET /api/v1/transactions/{transaction}`, `POST /api/v1/transactions/{transaction}/autopay`
- Teacher register: `public/Pages/Teacher/Register.jsx`
  - API: `POST /api/v1/auth/teacher/*`

## Auth (Public)
- Login/Register/Verify/Reset: `public/Pages/Auth/*`
  - API: `POST /api/v1/auth/login`, `POST /api/v1/auth/register`, `POST /api/v1/auth/password/forgot`, `POST /api/v1/auth/password/reset`, `GET /api/v1/auth/email/verify/{id}/{hash}`, `POST /api/v1/auth/email/resend`
- Guest onboarding: `public/Pages/Auth/GuestComplete.jsx`
  - API: `GET/POST /api/v1/auth/guest/onboarding`

## Parent Portal
- Dashboard & overview: `parent/Pages/Main/*`
  - API: `GET /api/v1/portal/dashboard`, `GET /api/v1/portal/overview`
- Notifications: `parent/Pages/Notifications/Index.jsx`
  - API: `GET /api/v1/notifications`, `PATCH /api/v1/notifications/{id}/read`, `PATCH /api/v1/notifications/read-all`
- Courses/Modules: `parent/Pages/Courses/*`
  - API: `GET /api/v1/courses`, `GET /api/v1/courses/{course}`, `GET /api/v1/courses/{course}/modules`, `GET /api/v1/modules/{module}`, `GET /api/v1/modules/{module}/lessons`
- Lessons + player: `parent/Pages/Lessons/*`, `parent/Pages/ContentLessons/*`
  - API: `GET /api/v1/content-lessons`, `GET /api/v1/content-lessons/{lesson}`, `GET /api/v1/content-lessons/{lesson}/slides`
  - API (player): `/api/v1/lesson-player/*` (start/progress/complete/slides/uploads/summary)
- Assessments + submissions: `parent/Pages/Assessments/*`, `parent/Pages/Submissions/Show.jsx`
  - API: `GET /api/v1/assessments`, `GET /api/v1/assessments/{assessment}`, `GET /api/v1/assessments/{assessment}/questions`, `POST /api/v1/assessments/{assessment}/attempts`, `POST /api/v1/assessments/{assessment}/attempts/submit`
  - API: `GET /api/v1/submissions`, `GET /api/v1/submissions/{submission}`, `PATCH /api/v1/submissions/{submission}`
- Journeys: `parent/Pages/Journeys/Overview.jsx`
  - API: `GET /api/v1/journeys`
- Live sessions: `parent/Pages/LiveSessions/*`
  - API: `GET /api/v1/live-sessions/browse`, `GET /api/v1/live-sessions/my`, `POST /api/v1/live-sessions/{session}/join`, `POST /api/v1/live-sessions/{session}/leave`, `GET /api/v1/live-sessions/{session}`
- Schedule + tracker: `parent/Pages/Schedule/*`, `parent/Pages/Main/ProgressTracker*.jsx`
  - API: `GET /api/v1/portal/schedule`, `GET /api/v1/portal/deadlines`, `GET /api/v1/portal/tracker`, `GET /api/v1/portal/calendar-feed`
- Parent feedback: `parent/Pages/ParentFeedback/*`
  - API: `GET /api/v1/feedback`, `POST /api/v1/feedback`
- Profile: `parent/Pages/Profile/*`
  - API: `GET /api/v1/me`, `PATCH /api/v1/me`, `DELETE /api/v1/me`
- AI chat: `parent/Pages/ChatAI/Chat.jsx`, `parent/Pages/AI/*`
  - API: `/api/v1/ai/chat`, `/api/v1/ai/chat/history`, `/api/v1/ai/chat/open`, `/api/v1/ai/hint-loop`, `/api/v1/ai/learning-paths/*`, `/api/v1/ai/recommendations/*`

## Admin Portal
- Dashboard + analytics: `admin/Pages/Dashboard/*`, `admin/Pages/ContentManagement/Dashboards/Analytics.jsx`
  - API: `GET /api/v1/admin/dashboard`
- Content management (courses/modules/lessons): `admin/Pages/ContentManagement/*`
  - API: `/api/v1/admin/courses/*`, `/api/v1/admin/modules/*`, `/api/v1/admin/content-lessons/*`, `/api/v1/admin/lesson-slides/*`
- Question bank: `admin/Pages/Questions/*`
  - API: `/api/v1/questions/*`
- Assessments: `admin/Pages/Assessments/*`
  - API: `/api/v1/assessments/*`, `/api/v1/assessments/{assessment}/questions/*`
- Homework + submissions: `admin/Pages/Homework/*`, `admin/Pages/HomeworkSubmissions/*`, `admin/Pages/Submissions/*`
  - API: `/api/v1/homework/*`, `/api/v1/homework/{homework}/submissions`, `/api/v1/submissions/*`
- Live sessions (admin view): `admin/Pages/LiveSessions/*`
  - API: `/api/v1/live-sessions/*`
- Attendance: `admin/Pages/Attendance/*`
  - API: `/api/v1/admin/attendance/*`
- Notifications: `admin/Pages/Notifications/*`
  - API: `/api/v1/notifications/*`
- Tasks (admin): `admin/Pages/Tasks/*`, `admin/Pages/AdminTasks/*`
  - API: `/api/v1/admin/tasks/*`
- Feedback + portal feedback: `admin/Pages/Feedback/*`, `admin/Pages/PortalFeedback/*`
  - API: `/api/v1/feedback/*`, `/api/v1/admin/portal-feedbacks/*`
- Articles/FAQs/Alerts/Slides/Testimonials/Milestones: `admin/Pages/Articles/*`, `admin/Pages/Faqs/*`, `admin/Pages/Alerts/*`, `admin/Pages/Slides/*`, `admin/Pages/Testimonials/*`, `admin/Pages/Milestones/*`
  - API: `/api/v1/admin/content/*`
- Services + Products + Subscriptions + Transactions: `admin/Pages/Services/*`, `admin/Pages/Products/*`, `admin/Pages/Subscriptions/*`, `admin/Pages/Transactions/*`
  - API: `/api/v1/services/*`, `/api/v1/admin/subscriptions/*`, `/api/v1/admin/user-subscriptions/*`, `/api/v1/transactions/*`, `/api/v1/billing/*`
- Applications: `admin/Pages/Applications/*`
  - API: `/api/v1/admin/applications/*`
- Teacher applications + assignments: `admin/Pages/TeacherApplications/*`, `admin/Pages/TeacherStudentAssignments/*`
  - API: `/api/v1/admin/teacher-applications/*`, `/api/v1/admin/teacher-student-assignments/*`
- Teachers + students: `admin/Pages/Teacher/*`, `admin/Pages/Teacher/Students/*`
  - API: `/api/v1/teacher/*` (teacher role), `/api/v1/admin/teacher-student-assignments/*` (admin management)
- AI upload: `admin/Pages/AIUpload/*`
  - API: `/api/v1/admin/ai-upload/*`
- Organizations + users: `admin/Pages/Organizations/*`
  - API: `/api/v1/organizations/*`
- Access / flags: `admin/Pages/access/Index.jsx`
  - API: `/api/v1/admin/access`, `/api/v1/admin/flags/*`

## Superadmin Portal
- Dashboard + analytics: `superadmin/Pages/Dashboard/*`, `superadmin/Pages/Analytics/*`
  - API: `/api/v1/superadmin/dashboard`, `/api/v1/superadmin/analytics/*`
- Organizations + branding + users: `superadmin/Pages/Organizations/*`
  - API: `/api/v1/superadmin/organizations/*`, `/api/v1/superadmin/organizations/{org}/branding/*`, `/api/v1/superadmin/organizations/{org}/users/*`
- System settings + feature flags + API keys: `superadmin/Pages/Settings/Index.jsx`, `superadmin/Pages/SiteAdmin/Index.jsx`
  - API: `/api/v1/superadmin/system/*`
- Logs: `superadmin/Pages/Logs/System.jsx`
  - API: `/api/v1/superadmin/logs/*`
- Content overviews: `superadmin/Pages/Content/*`
  - API: `/api/v1/superadmin/content/*`
- Users: `superadmin/Pages/Users/*`
  - API: `/api/v1/superadmin/users/*`

## Shared (Errors)
- `resources/js/Pages/Errors/*`: no API calls (pure UI)

## Known Gaps / TODO
- Ape Academy endpoints are placeholder (public page integration pending).
- Cart is still session-backed; needs stateless token-based cart before decoupling frontend.
- Admin teacher CRUD endpoints not explicit in `api_v1_routes.txt` (may need mapping to users/organizations or new endpoints).

## Next Phase (Phase 1)
- Build shared API client + auth store
- Define frontend envelope/error handling
- Start swapping public/auth pages first
