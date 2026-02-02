# API Conversion Plan (Backend -> API First)

## Goal
Convert all features into API calls so multiple front ends can attach to this backend, or the backend and frontend can be split into separate Laravel projects. This plan inventories the current features, checks scalability, proposes improvements, and defines API conversion steps per feature.

## Inventory sources reviewed
- routes/public.php
- routes/admin.php
- routes/teacher.php
- routes/parent.php
- routes/superadmin.php
- routes/api.php
- docs/* (feature design docs)
- app/Http/Controllers/* (feature controllers)

## Global foundations (do before or in parallel with feature migration)
These are cross cutting requirements so the API can serve multiple front ends reliably.

- API versioning
  - Establish /api/v1 as the canonical base for all endpoints.
  - Keep internal or legacy endpoints under /api/internal or /api/v0 during migration.
- API routing + middleware
  - Register api routes in bootstrap/app.php and enforce stateless middleware.
  - Add request-id and org-context middleware for all /api requests.
- JSON response envelope
  - Standardize: { data, meta, errors } for all API responses.
  - Add API error wrapper for validation/exception responses.
- API helpers
  - Provide ApiController + ApiResponse trait for consistent success/paginated responses.
  - Provide ApiPagination + ApiQuery helpers for per_page, filter, and sort handling.
  - Provide ApiResource base for response shaping.
- Auth and session strategy
  - Use Laravel Sanctum (token based) for web, mobile, and third party clients.
  - Preserve session auth for the existing monolith during migration, but isolate API controllers to stateless guards.
- Standard response format
  - Adopt a consistent JSON envelope: { data, meta, errors }.
  - Use JSON:API style pagination and include links.
- Validation and error handling
  - Centralize validation in FormRequest classes.
  - Return standardized error codes and messages for frontend portability.
- Permissions and multi tenancy
  - Enforce organization scoping in queries (organization_id) and policies.
  - Consolidate role checks (admin, teacher, parent, guest_parent, super_admin) into policies or gates.
  - For API: org scope derives from X-Organization-Id header or ?organization_id (super admin only).
- Feature flags
  - Unify feature flags for api usage (feature:parent.ai.chatbot, etc.) to be enforced in middleware for API and web.
- Performance and scalability
  - Add pagination, filtering, sorting to all list endpoints.
  - Add indexes for high volume fields (user_id, organization_id, service_id, course_id, lesson_id, assessment_id, status, created_at).
  - Cache read heavy content (courses, lessons, catalog, public content).
- Rate limiting
  - Define api rate limits (per-minute) and apply throttle middleware for /api routes.
- Observability
  - Add request id, structured logs (request_id, organization_id, user_id), and API request metrics.
  - Log and alert on errors, long running requests, and webhook failures.
- Files and uploads
  - Move file uploads to pre signed URLs (S3 or compatible), store metadata in DB.
  - Add virus scanning and size/type checks.
- Testing
  - Add API feature tests for core domains and auth flows.

## Feature inventory and conversion plan

Each section includes: features, scalability check and improvements, and API conversion steps.

### 1) Identity, authentication, and onboarding
Features
- Login, logout, registration, password reset, email verification.
- Teacher registration with OTP.
- Guest onboarding to upgrade guest_parent to parent.
- Profile management.

Scalability check and improvements
- Current flows are session based; external clients need tokens.
- Add rate limits and lockouts for auth endpoints.
- Add email queueing for verification and reset links.
- Ensure unique email indexes and case insensitive lookup.

API conversion
- Create /api/v1/auth endpoints:
  - POST /auth/login
  - POST /auth/logout
  - POST /auth/register
  - POST /auth/password/forgot
  - POST /auth/password/reset
  - GET /auth/email/verify
  - POST /auth/email/resend
- Teacher OTP flow:
  - POST /auth/teacher/send-otp
  - POST /auth/teacher/verify-otp
  - POST /auth/teacher/register
- Guest onboarding:
  - POST /auth/guest/create
  - POST /auth/guest/verify-code
  - POST /auth/guest/complete-profile
- Profile:
  - GET /me
  - PATCH /me
  - DELETE /me

### 2) Users, roles, organizations, and access control
Features
- Organization CRUD, switch org, manage org users, update roles, org feature flags.
- Admin access management.
- Super admin platform user management (impersonate, bulk actions).

Scalability check and improvements
- Ensure every domain query is scoped by organization_id for non super admins.
- Add audit logging for role/permission changes.
- Cache user permissions and feature flags per org.

API conversion
- /api/v1/organizations (CRUD, switch, users, features)
- /api/v1/admin/access (role and permission updates)
- /api/v1/superadmin/users (platform wide)
- /api/v1/superadmin/organizations (platform wide)

### 3) Public content and marketing
Features
- Home, about, contact pages; public articles, FAQs, alerts, slides, testimonials.
- Ape Academy public pages.

Scalability check and improvements
- Add published flags and soft deletes.
- Cache public content with ETags and CDN.
- Support locale and multi org theming.

API conversion
- /api/v1/public/pages (home, about, contact)
- /api/v1/public/articles
- /api/v1/public/faqs
- /api/v1/public/alerts
- /api/v1/public/slides
- /api/v1/public/testimonials
- /api/v1/public/ape-academy

### 4) Services, products, and catalog
Features
- Services catalog, service detail, portal service detail.
- Products catalog and detail.
- Flexible service selection and limits (live sessions and assessments).
- Subscription catalog and course previews.

Scalability check and improvements
- Index on service_id, product_id, course_id, status.
- Cache catalog and service detail responses.
- Normalize selection_config validation and limits.

API conversion
- /api/v1/services (list, show)
- /api/v1/services/{id}/available-content
- /api/v1/services/{id}/selection (flexible service selection validation)
- /api/v1/products (list, show)
- /api/v1/subscription-plans (catalog)

### 5) Cart, checkout, transactions, and billing
Features
- Cart CRUD, flexible service cart additions.
- Checkout, guest checkout, create guest and verify code.
- Billing setup, pay invoice, portal, receipts, widgets.
- Transactions, autopay enablement.
- Billing webhooks and subscription system (third party).

Scalability check and improvements
- Add idempotency keys for checkout and payment endpoints.
- All payment operations should be queued or handled via webhooks.
- Verify webhook signatures and retries.
- Introduce a payment status state machine.

API conversion
- /api/v1/cart (GET, POST items, PATCH qty, DELETE item)
- /api/v1/checkout (POST)
- /api/v1/checkout/guest (POST)
- /api/v1/checkout/guest/send-code, /verify-code
- /api/v1/billing/setup
- /api/v1/billing/invoices, /payments, /portal
- /api/v1/transactions
- /api/v1/webhooks/billing (public)

### 6) Courses, modules, lessons, slides (content system)
Features
- Course management, publish/archive/duplicate.
- Module management, reorder, attach lessons and assessments.
- Content lessons and slide editor with block based content.
- Lesson slides, block CRUD, image uploads.
- Lesson uploads review and grading.

Scalability check and improvements
- Use transactions for publish/duplicate and reorders.
- Add optimistic locking for editor saves.
- Cache course and lesson structures per org.
- Use background jobs for duplication and heavy operations.

API conversion
- /api/v1/courses (CRUD, publish, archive, duplicate)
- /api/v1/courses/{id}/modules (list, create, reorder)
- /api/v1/modules/{id} (update, delete, publish, duplicate)
- /api/v1/modules/{id}/lessons (attach, detach, reorder)
- /api/v1/modules/{id}/assessments (attach, detach)
- /api/v1/content-lessons (CRUD, publish, duplicate)
- /api/v1/content-lessons/{id}/slides (list, create, reorder)
- /api/v1/lesson-slides/{id} (update, delete, duplicate)
- /api/v1/lesson-slides/{id}/blocks (create, update, delete)
- /api/v1/uploads/images (content image upload)

### 7) Assessments and question bank
Features
- Assessments CRUD, attach questions, attempt and submit.
- Submissions and grading.
- Question bank CRUD, quick create, type defaults, search, batch fetch.

Scalability check and improvements
- Add pagination and filters for questions and assessments.
- Store assessment attempt state in a dedicated table with indexing.
- Queue grading and AI analysis where needed.

API conversion
- /api/v1/assessments (CRUD)
- /api/v1/assessments/{id}/questions (list, attach, detach)
- /api/v1/assessments/{id}/attempts (start, submit)
- /api/v1/submissions (list, show, grade)
- /api/v1/questions (CRUD, search, quick-create, type-defaults, batch)

### 8) Student lesson player and progress tracking
Features
- Start lesson, view slides, interactions, confidence, progress updates, complete lesson.
- Question responses, retries, lesson uploads.
- Whiteboard save and load.

Scalability check and improvements
- Batch slide interaction events (client side buffering).
- Store progress in append only event tables for analytics.
- Use async processing for interaction analytics.

API conversion
- /api/v1/lesson-player/{lesson}/start
- /api/v1/lesson-player/{lesson}/slides/{slide}
- /api/v1/lesson-player/{lesson}/slides/{slide}/view
- /api/v1/lesson-player/{lesson}/slides/{slide}/interaction
- /api/v1/lesson-player/{lesson}/slides/{slide}/confidence
- /api/v1/lesson-player/{lesson}/progress
- /api/v1/lesson-player/{lesson}/complete
- /api/v1/lesson-player/{lesson}/questions/submit
- /api/v1/lesson-player/{lesson}/questions/{response}/retry
- /api/v1/lesson-player/{lesson}/uploads
- /api/v1/lesson-player/slides/{slide}/whiteboard (save, load)

### 9) Live lessons (teacher and student)
Features
- Live session CRUD, start session, teacher control panel.
- Slide control, navigation lock, annotations, reactions, messaging.
- Student join, leave, raise hand.
- LiveKit token generation.

Scalability check and improvements
- Move real time interactions to WebSockets (Laravel Echo or external).
- Store participation and chat logs in dedicated tables with TTL or archiving.
- Use rate limiting for reactions and chat.

API conversion
- /api/v1/live-sessions (CRUD, start)
- /api/v1/live-sessions/{id}/teach (metadata for teacher panel)
- /api/v1/live-sessions/{id}/participants (list, mute, lower-hand, kick)
- /api/v1/live-sessions/{id}/slides (change-slide, change-state)
- /api/v1/live-sessions/{id}/annotations (send, clear)
- /api/v1/live-sessions/{id}/messages (send, list, answer)
- /api/v1/live-sessions/{id}/reactions (send)
- /api/v1/live-sessions/{id}/token

### 10) Parent portal, calendar, schedule, tracker, journey
Features
- Portal dashboard, schedule, deadlines, calendar feed.
- Progress tracker and journey overview.
- Notifications and tasks.

Scalability check and improvements
- Precompute dashboards and summary metrics nightly or on event triggers.
- Cache portal data per child for fast loads.

API conversion
- /api/v1/portal/dashboard
- /api/v1/portal/schedule
- /api/v1/portal/deadlines
- /api/v1/portal/calendar-feed
- /api/v1/portal/tracker
- /api/v1/journeys (overview, categories)
- /api/v1/notifications (list, read, read-all)
- /api/v1/tasks (CRUD)

### 11) Homework and submissions
Features
- Homework assignments (create, update, delete).
- Homework submissions by parents/students.
- Admin and teacher review.

Scalability check and improvements
- Move file uploads to object storage with background virus scanning.
- Index homework by due date and class/course.

API conversion
- /api/v1/homework (CRUD)
- /api/v1/homework/{id}/submissions (create, list)
- /api/v1/homework/submissions/{id} (show, update, delete)

### 12) Attendance
Features
- Attendance overview, lesson sheet, bulk mark, approve.

Scalability check and improvements
- Add attendance status index and per lesson aggregates.
- Use batch endpoints for bulk updates.

API conversion
- /api/v1/attendance (overview, per lesson sheet)
- /api/v1/attendance/lessons/{lesson}/mark-all
- /api/v1/attendance/lessons/{lesson}/approve-all

### 13) AI features and content creation
Features
- AI chat and hint loop for parents.
- AI agent endpoints for tutoring, grading review, progress analysis, recommendations, learning paths.
- AI upload (bulk content creation) sessions, proposals, approval and upload.
- AI grading flags and resolution.

Scalability check and improvements
- Queue AI calls with retries and timeouts.
- Cache AI responses per prompt where appropriate.
- Add strict rate limits and usage quotas per org.

API conversion
- /api/v1/ai/chat (ask, history)
- /api/v1/ai/hints (generate)
- /api/v1/ai/agents/* (tutor, grading, progress, recommendations, learning paths)
- /api/v1/ai/uploads (sessions, proposals, approve/reject, upload)
- /api/v1/ai/flags (list, resolve)

### 14) Teacher features
Features
- Teacher dashboard, student list, assignments, revenue dashboard.
- Teacher tasks, year group management (scoped).

Scalability check and improvements
- Precompute teacher dashboards and revenue aggregates.
- Cache student lists and assignment counts.

API conversion
- /api/v1/teacher/dashboard
- /api/v1/teacher/students
- /api/v1/teacher/assignments
- /api/v1/teacher/revenue
- /api/v1/teacher/tasks
- /api/v1/teacher/year-groups

### 15) Admin features
Features
- Admin dashboard, access management.
- Subscriptions management, user subscriptions.
- Teacher applications, assignments, admin tasks.
- Content management for articles, FAQs, slides, alerts, testimonials, milestones.
- Lesson uploads review and grading.

Scalability check and improvements
- Convert heavy admin lists to server side pagination with filters.
- Cache admin dashboards and counts.

API conversion
- /api/v1/admin/dashboard
- /api/v1/admin/access
- /api/v1/admin/subscriptions
- /api/v1/admin/user-subscriptions
- /api/v1/admin/teacher-applications
- /api/v1/admin/admin-tasks
- /api/v1/admin/content/* (articles, faqs, slides, alerts, testimonials, milestones)
- /api/v1/admin/lesson-uploads

### 16) Super admin platform management
Features
- Platform user management, organization management, branding.
- Content moderation, system settings, billing analytics, logs.

Scalability check and improvements
- Ensure all platform endpoints are isolated from tenant scoped policies.
- Add audit trail for system changes.

API conversion
- /api/v1/superadmin/users
- /api/v1/superadmin/organizations
- /api/v1/superadmin/branding
- /api/v1/superadmin/content
- /api/v1/superadmin/system (settings, features, integrations, api keys)
- /api/v1/superadmin/billing
- /api/v1/superadmin/analytics
- /api/v1/superadmin/logs

### 17) Year groups and subscriptions
Features
- Year group bulk management, naming conventions, year group subscriptions.

Scalability check and improvements
- Normalize year group naming and enforce validation.
- Add indexes for year_group_id and student_id.

API conversion
- /api/v1/year-groups
- /api/v1/year-groups/bulk-update
- /api/v1/year-groups/subscriptions

### 18) Notifications and messaging
Features
- Notifications list, mark read, read all.
- Live session messages.

Scalability check and improvements
- Use notification table with indexed user_id and read_at.
- Batch mark read and use cursor pagination.

API conversion
- /api/v1/notifications
- /api/v1/notifications/{id}/read
- /api/v1/notifications/read-all

### 19) Feedback, testimonials, applications
Features
- Parent feedback forms, admin management.
- Testimonials management.
- Teacher application workflow and verification.

Scalability check and improvements
- Queue email notifications for feedback and applications.
- Add status indexes for filtering.

API conversion
- /api/v1/feedback (create, list, update)
- /api/v1/testimonials (public + admin)
- /api/v1/applications (create, verify, review)

## Migration sequence (recommended)

Phase 1: Foundations and auth
- Add API versioning, token auth, response format, policies, pagination, error handling.
- Convert identity and profile endpoints.

Phase 2: Catalog and content
- Convert public content, services, products, courses, modules, lessons, and assessments.
- Provide read only endpoints first, then CRUD.

Phase 3: Commerce and subscriptions
- Convert cart, checkout, billing, transactions, and subscriptions.
- Add webhooks and idempotency.

Phase 4: Student and parent experiences
- Convert lesson player, assessments attempt, progress, portal, tracker.

Phase 5: Live lessons and real time
- Convert live sessions and integrate WebSocket channel APIs.

Phase 6: Admin and teacher tools
- Convert admin and teacher dashboards and management features.

Phase 7: Super admin and platform ops
- Convert platform management, analytics, logs, branding.

## Current API Coverage Snapshot (Jan 28, 2026)

This snapshot reflects the live `/api/v1` surface that exists in the codebase now.

### Implemented (high level)
- Auth + profile: `/api/v1/auth/*`, `/api/v1/me`
- Public content: `/api/v1/public/*`, `/api/v1/public/pages`, `/api/v1/public/ape-academy`
- Catalog: `/api/v1/services`, `/api/v1/products`, `/api/v1/subscription-plans`
- Content system: `/api/v1/courses`, `/api/v1/modules`, `/api/v1/content-lessons`, `/api/v1/lesson-slides`
- Assessments + questions: `/api/v1/assessments`, `/api/v1/questions`, `/api/v1/submissions`
- Lesson player: `/api/v1/lesson-player/*`, `/api/v1/lesson-slides/{id}/whiteboard/*`
- Live sessions: `/api/v1/live-sessions/*`
- Portal + journeys + notifications + tasks: `/api/v1/portal/*`, `/api/v1/journeys`, `/api/v1/notifications`, `/api/v1/tasks`
- Homework + attendance: `/api/v1/homework/*`, `/api/v1/attendance/*` (aliases)
- AI: `/api/v1/ai/*` (chat, agents, uploads, flags, hint generation)
- Teacher: `/api/v1/teacher/*` (dashboard, students, attendance, lesson uploads, tasks, revenue, year groups)
- Admin: `/api/v1/admin/*` (dashboard, tasks, content, subscriptions, applications, flags, uploads)
- Superadmin: `/api/v1/superadmin/*` (users, orgs, content, system, billing, analytics, logs)
- Commerce: `/api/v1/cart/*`, `/api/v1/checkout`, `/api/v1/checkout/guest/*`, `/api/v1/billing/*`, `/api/v1/transactions`, `/api/v1/webhooks/billing`
- Uploads: `/api/v1/uploads/images`
- Year groups: `/api/v1/year-groups` + `/api/v1/year-groups/bulk-update` (admin aliases)
- Testimonials alias: `/api/v1/testimonials`

### Deviations from the original plan
- AI endpoints use explicit paths (e.g., `/ai/hints/generate`, `/ai/tutor/chat`) rather than generic `/ai/agents/*` and `/ai/hints`.
- Live session actions are singular (`/annotation`, `/reaction`, `/slide`) vs planned plural endpoints.
- Lesson player question submit is slide-scoped (`/lesson-player/{lesson}/slides/{slide}/questions/submit`).
- Admin tasks are exposed as `/api/v1/admin/tasks` (not `/admin/admin-tasks`).

### Remaining gaps / follow‑ups
- Ape Academy API currently returns placeholder payloads (needs real content source).
- Cart is session-backed (not fully stateless); consider token-based cart storage.

## Definition of done per feature
- API endpoint(s) exist under /api/v1 and return JSON resources.
- Feature works with at least one external frontend without session cookies.
- All endpoints have tests for auth, validation, and core business rules.
- Policies and org scoping enforced for all data access.
- Observability and logging in place.
