<thinking>
I will produce a single-file, comprehensive summary of the work: user goals, decisions, files changed, exact fixes for the guest-checkout -> checkout-from-basket problem, email idempotency measures, routes added, and the remaining recommended tasks. This document is written so any developer can pick up where we left off.
</thinking>

Guest / Quick-Checkout — Final summary (state as of last changes)
================================================================

1) Primary request and intent
- Enable a 2–3 click flow that allows a public user to buy a Service (including assessment services) with minimal friction, while:
  - Creating only a minimal "guest_parent" account up-front (email + child) so billing and access can be granted immediately.
  - Preventing guest accounts from gaining full parent-portal access until onboarding is completed.
  - Ensuring access, invoice, transaction and child associations are consistent and durable.
- The user reported a runtime bug: the checkout page assumed a logged-in user with children when a guest had clicked "Checkout Now" from the basket. The user asked whether the account should be created at that point or elsewhere.

2) Key technical concepts, frameworks and patterns used
- Backend: Laravel (Controllers, Middleware, Migrations, Eloquent)
- Frontend: Inertia.js + React (Inertia pages and components)
- Billing: external BillingService (HTTP API), uses Idempotency-Key for createCustomer/createInvoice
- Durability: "Transaction-first" snapshot — persist Transaction + TransactionItems server-side before completing payment
- Access model: Access table rows tie child -> lesson/assessment + transaction/invoice + access/payment_status
- Guest account pattern:
  - New role: ROLE_GUEST_PARENT
  - User fields: onboarding_complete:boolean, temporary_at:timestamp
- Email idempotency pattern:
  - Transaction flags: email_sent_receipt, email_sent_access
  - DB-level atomic updates (update where flag is false) to avoid race conditions when sending emails from both sync (CheckoutController) and async (webhook) paths
- Anti-abuse: throttle middleware applied to guest pre-check endpoint

3) Files changed, created, and why (top-level, with rationale)
- Controllers / Services
  - app/Services/GuestCheckoutService.php (new)
    - Purpose: encapsulate creating/finding guest_parent users and creating child records
    - Key functions: findOrCreateGuestUser(array), createChildForUser(User, array)
  - app/Http/Controllers/CheckoutController.php (modified)
    - Added createGuest(Request) endpoint (pre-check): validates minimal info, creates guest user + child, Auth::login, redirects to /checkout.
    - store(Request): snapshots cart, creates Transaction and TransactionItems, calls BillingService->createInvoice, marks Transaction status, creates Access rows, queues emails with DB-level atomic flag updates.
    - show(): safe when no user exists (returns empty children).
  - app/Http/Controllers/BillingWebhookController.php (new)
    - handleInvoice(Request): receives billing webhook, finds Transaction by invoice_id, marks completed, creates Access rows, queues emails with idempotent guard (DB updates).
  - app/Http/Controllers/AssessmentController.php (modified)
    - Permit guest_parent attempts when the Access rows exist; expose onboarding flags to front-end so UI can show gating messages.
- Models / Migrations
  - app/Models/User.php (modified)
    - Added ROLE_GUEST_PARENT constant and prepared fillable/casts; migration adds columns.
  - database/migrations/2025_08_29_235200_add_onboarding_fields_to_users_table.php (new)
    - Adds onboarding_complete (boolean) and temporary_at (timestamp)
  - database/migrations/2025_08_30_002900_add_email_flags_to_transactions_table.php (new)
    - Adds email_sent_receipt and email_sent_access boolean fields to transactions (idempotency)
- Mailing
  - app/Mail/ReceiptAccessMail.php (new)
  - resources/views/emails/receipt_access.blade.php (HTML)
  - resources/views/emails/receipt_access_plain.blade.php (plain text)
  - Rationale: single mailable reused for both receipt and access notification.
- Frontend (Inertia + React)
  - resources/js/public/Pages/Services/ShowPublic.jsx (modified)
    - Quick-Buy modal — adds item to cart, then submits to /checkout/create-guest (pre-check) to create guest user+child and redirect to /checkout.
  - resources/js/public/components/BasketSidebar.jsx (modified)
    - When user clicks "Checkout Now" and is not authenticated: opens a pre-check modal to collect email + child and POST to /checkout/create-guest; route is throttled.
  - resources/js/public/Pages/Checkout/Index.jsx (modified)
    - Checkout page is null-safe for guest users (no children) and supports:
      - Per-service child select mapping
      - Inline add-child form (POST /children) so users can create additional children from checkout
- Routes
  - routes/public.php (modified)
    - Route POST /checkout/create-guest added and protected by throttle:6,1 (6 reqs/min).
    - Route POST /webhooks/billing added for webhook handling.

4) Problems solved and troubleshooting
- Null-user children error:
  - Root cause: checkout.show previously assumed Auth::user() exists; calling code reading $user->children on null caused exception.
  - Fix: CheckoutController::show returns children = [] when no user; frontend adjusted so checkout page doesn't crash.
- Checkout-from-basket flow (guest):
  - Root cause: basket->Checkout Now redirected to /checkout even for guest; checkout expected child(s) which didn't exist.
  - Fix: BasketSidebar now shows a pre-check modal for unauthenticated users that POSTs to /checkout/create-guest (creates guest user + child and logs the session in); then redirect to /checkout.
- Duplicate email risk (sync + webhook):
  - Root cause: immediate invoice creation in CheckoutController might queue emails; webhook may also queue.
  - Fix: Added transaction flags (email_sent_receipt, email_sent_access) and DB-level atomic updates (update where flag = false) when queueing emails in both CheckoutController and BillingWebhookController.
- Mapping service -> child:
  - Implemented per-service child selection in checkout UI and inline add-child POST route so user can select or create children as needed.

5) Pending tasks (explicit)
- Apply DB-level atomic flag updates in BillingWebhookController (we updated both controllers; verify complete).
- Create scheduled cleanup command (artisan) to expire or notify abandoned guest_parent accounts after N days (e.g., 30 days).
- Add better anti-abuse controls if needed: CAPTCHA + server-side validation on /checkout/create-guest (throttle is in place).
- Add tests (unit and integration) for:
  - Guest creation
  - Checkout guest POST
  - Webhook reconciliation & idempotent emails
- QA & staging deployment:
  - Test synchronous payment widget flow (in-page) and async webhook flow (redirect + webhook).
- Small UX improvements:
  - Convert guest to parent after onboarding completion; provide CTA on receipt email and dashboard.
  - Confirm wording and copy in emails and modals.

6) Current work (what I was doing just before this summary)
- We implemented pre-check guest creation and in-checkout inline add-child flows. The immediate technical task the user selected next was to implement DB-level atomic guards for email flags to avoid duplicate emails — I implemented DB-level conditional updates in CheckoutController and updated the webhook controller similarly.
- Files updated most recently:
  - app/Http/Controllers/CheckoutController.php — atomic email flag updates using DB::table(...)->where(...)->update(...)
  - app/Http/Controllers/BillingWebhookController.php — idempotent email queuing with DB flag update (already applied)
  - resources/js/public/components/BasketSidebar.jsx — pre-check modal to create guest user for checkout
  - resources/js/public/Pages/Checkout/Index.jsx — per-service assignment and inline child creation
  - migrations for onboarding and transaction email flags
  - email templates and mailable

7) Optional next step (directly aligned)
- The user requested DB-level atomic guards first; that has been implemented. Next recommended step is the scheduled cleanup job for abandoned guest accounts (30 days) or adding automated tests. The user explicitly selected DB-level guard earlier: "No — do DB-level atomic guards for email flags first to fully prevent duplicate emails" — that is implemented.
- Suggested next step (my recommendation): "Create scheduled cleanup for abandoned guest accounts (30 days)". This directly follows the guest account pattern and is aligned to the user's security/housekeeping concerns.

Example verbatim reference from user (most recent critical input)
- "The checkout page expects the guest user has already been created, but it is not when the checkout button is clicked from the basketsidebar component, should be make a user there by collecting the information?"

Status checklist (latest)
- [x] Add GuestCheckoutService
- [x] Add pre-check endpoint /checkout/create-guest and UI in service + basket
- [x] Snapshot cart -> Transaction, createInvoice calls
- [x] Webhook handling for invoice paid and Access creation
- [x] Receipt + access emails with idempotency flags + DB atomic updates
- [x] Throttle (rate-limit) applied to /checkout/create-guest
- [ ] Scheduled cleanup for guest accounts (next recommended)
- [ ] Tests & QA

If you want me to implement the scheduled cleanup job now, I will:
- Add an artisan command (e.g., php artisan guests:cleanup) to:
  - Find users with role guest_parent with temporary_at older than configured days and onboarding_complete = false
  - Either delete or anonymize them (or email reminder before deletion)
- Register a scheduler in app/Console/Kernel.php to run daily.
Confirm and I will implement the cleanup command now; otherwise confirm another next step (tests, CAPTCHA, UI copy, etc).
