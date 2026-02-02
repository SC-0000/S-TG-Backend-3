# Frontend API Conversion Plan

## Goals
- Replace Inertia page navigation + form posts with direct API calls to `/api/v1`.
- Keep UI behavior the same while making the frontend independent of the Laravel web layer.
- Support multiple frontend clients (web, mobile, admin portal) with shared API usage patterns.
- Preserve auth/role/org scoping and existing business logic.

## Current State (quick snapshot)
- Frontend is Inertia + React under `resources/js/*` (public, parent, admin, superadmin).
- Pages call server-side routes and rely on Inertia props and Laravel sessions.
- API endpoints exist under `/api/v1` with consistent JSON envelope.

## API Basics to Standardize in Frontend
- Base URL: `/api/v1`
- Envelope: `{ data, meta, errors }` (handled globally)
- Auth: token-based (Sanctum tokens)
- Org scoping: `organization_id` enforced by backend; frontend must pass correct auth token
- Pagination: server returns pagination meta (use for list views)

## Recommended Strategy
Convert incrementally with minimal risk:
1) Keep existing Inertia frontend working while new API-based frontend is built.
2) Migrate feature-by-feature (public → auth → parent → admin → superadmin).
3) When a feature is fully API-based and stable, swap routing to API frontend.
4) Finally remove Inertia-specific routes and server-rendered props.

This avoids a "big bang" rewrite and keeps the product usable.

## Phase 0 — Inventory & Mapping (1–2 days)
- Create a page inventory by role:
  - Public: landing, services, articles, testimonials, contact, etc.
  - Auth: login, register, password reset, verification
  - Parent: dashboard, courses, lessons, assessments, chat, payments, profile
  - Admin: content management, users, subscriptions, reports, tasks, attendance
  - Superadmin: orgs, settings, logs, feature flags
- Map each page to API endpoints (from `docs/api_v1_routes.txt`).
- Identify gaps where API coverage is incomplete (e.g., Ape Academy placeholder, stateless cart).
- Define the minimal UI that will be migrated first (recommended: public pages + auth).

Deliverable:
- `docs/FRONTEND_API_PAGE_MAP.md` (page → endpoint list)

## Phase 1 — Frontend API Foundations (2–4 days)
- Add a centralized API client (axios or fetch wrapper):
  - Base URL `/api/v1`
  - Attach token from storage
  - Handle envelope and normalize errors
- Define typed helpers for:
  - Pagination + filters
  - Uploads (multipart)
  - Auth token storage/refresh
- Add global error handling UI (toasts or inline field errors)
- Define a standard API response parser
- Prepare an auth state store (React context, Zustand, or Redux)

Deliverable:
- `resources/js/api/*` (client, auth, error helpers)
- `resources/js/stores/*` (auth/session)

## Phase 2 — Auth + Public Pages (3–6 days)
- Convert login/register/reset/verify flows to API calls.
- Replace Inertia form posts with API calls.
- Convert public pages and shared components to use API data.

Notes:
- Registration uses `/api/v1/register` (and possibly `/api/v1/auth/*`).
- Some public pages can remain static if no API data is required.

Deliverable:
- Public UI fully API-driven
- Auth handled by tokens

## Phase 3 — Parent Portal (5–10 days)
Convert parent pages to API calls in this order:
1) Dashboard + notifications
2) Courses and lessons (browse, show, progress)
3) Assessments + submissions
4) Chat AI and support
5) Profile + settings
6) Payments and billing

Key considerations:
- Progress tracking depends on pagination + filtering.
- Lesson player needs stateless data fetch + progress posting.

Deliverable:
- Parent portal fully API-driven

## Phase 4 — Admin Portal (5–12 days)
Convert admin management pages:
1) Content management (courses, lessons, questions, slides)
2) Users + subscriptions
3) Tasks, homework, assessments
4) Attendance, notifications, AI uploads
5) Reports and dashboards

Deliverable:
- Admin portal fully API-driven

## Phase 5 — Superadmin Portal (3–6 days)
- Convert org management, system settings, logs, feature flags.
- Validate org scoping rules.

Deliverable:
- Superadmin portal fully API-driven

## Phase 6 — Remove Inertia + Web Routes (after full parity)
- Remove Inertia setup and server-rendered props.
- Remove unused `routes/*.php` web routes.
- Replace with a standalone frontend deployment.

Deliverable:
- Backend is API-only

## Technical Risks / Gaps
- Cart is currently session-backed: needs token-based cart for stateless frontend.
- Ape Academy API is placeholder (must be finalized).
- Some uploads depend on Inertia form data: update to use multipart API calls.

## Testing & Verification
- Use API tests (already in `tests/Feature/Api/*`).
- Add frontend integration tests (Cypress/Playwright recommended).
- Contract tests for API responses (schema validation).

## Next Steps (Immediate)
1) Create `docs/FRONTEND_API_PAGE_MAP.md` from current Inertia pages.
2) Implement API client + auth store in frontend.
3) Start Phase 2 with login/register + public pages.

