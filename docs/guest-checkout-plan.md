Goal
-----
Enable a 2–3 click public checkout so a public user can buy a Service (including assessments), get immediate access to attempt the purchased assessments, but be prevented from opening the full parent portal until they complete onboarding. Use a new role "guest_parent" for these users.

Observations (from codebase review)
----------------------------------
- Checkout flow (app/Http/Controllers/CheckoutController.php) assumes Auth::user() exists and creates Transaction + Invoice and grants access records (app/access table) for child_id when transaction items include services.
- Access model (app/Models/Access.php) stores child_id, assessment_id(s), lesson_id(s), invoice_id, purchase_date, access boolean and payment_status. This is already used by AssessmentController to determine which children have access to which assessments.
- routes/public.php exposes assessment attempt routes under public route group: /assessments/{assessment}/attempt and Submission routes. Those are not protected by RoleMiddleware.
- RoleMiddleware (app/Http/Middleware/RoleMiddleware.php) strictly blocks guests and checks exact role string values. Parent portal routes use role:parent; a new role value will be excluded automatically.
- User model (app/Models/User.php) defines role constants for admin, parent, basic. No guest_parent constant yet.
- Child model and relationships can be created easily; Child.user_id is supported.

Recommended approach (why)
--------------------------
- Create users as role "guest_parent" when they go through quick public checkout. Collect minimal data: parent name, parent email (required), child name (if buying assessment), and optionally child DOB / year group.
- Create user + child(s) in DB before payment, create billing customer with BillingService, proceed with payment (one-time or create payment method).
- On successful payment/invoice, create Transaction + Transaction items + Access rows (same as current flow). Grant access to the child so they can immediately attempt assessments.
- Do NOT give guest_parent access to parent portal because RoleMiddleware will block them (portal routes use role:parent). Instead provide public-facing pages/components (under public area) to show "My purchases" (assessments/lessons) and allow attempt pages, visible to logged-in guest_parent users.
- Encourage conversion: show an in-app banner prompting to "Complete profile to unlock parent portal & more", send onboarding email with magic-link or set-password flow.

Benefits
--------
- Minimal UX friction: buyer enters email/name/child and pays.
- Data model remains largely intact (User + Child + Access + Transaction).
- Immediate access for assessments (good UX).
- Parent portal remains protected by role semantics: guest_parent won't pass role:parent checks.
- Easy upgrade path: when user completes onboarding, change role to parent and allow portal access.

Concrete implementation plan (steps)
------------------------------------
1) DB / Model changes
   - Add new role constant in User model:
     - User::ROLE_GUEST_PARENT = 'guest_parent'
     - (No DB enum changes needed because role is string)
   - (Optional) Add boolean column `onboarding_complete` or `is_temporary` for analytics / cleanup:
     - migration: add boolean `onboarding_complete` default false and `temporary_at` timestamp nullable. This helps cleanup and reporting.
   - Task: create migration file to add columns (if accepted).

2) CheckoutController changes (backend)
   - Add a guest-checkout path in CheckoutController or extend POST /checkout to accept guest payload when not authenticated.
   - Validate minimal fields: email|required|email, name|nullable|string, child_name|required_if any service in cart has assessments
   - In DB transaction:
     - If user with email exists:
         - If role === parent: prompt login / send magic link OR reuse if loginless allowed. (Edge case handling)
         - If role === guest_parent: reuse existing
     - Else create new User with:
         - name, email, role = 'guest_parent', password = random hashed value, email_verified_at = null (optional)
         - set onboarding_complete = false
     - Create Child record(s) with user_id = created user's id
     - Ensure BillingService.createCustomer($user) is called to set billing_customer_id if missing
     - Create Transaction, TransactionItems similar to existing flow
     - Create Invoice via BillingService
     - On invoice/payment success:
         - set transaction.status = completed and save
         - create Access rows (same as current flow) for newly created child(s) and mark access = true, payment_status = 'paid'
   - After success, keep the user logged in for the current session (Auth::login($user)) so they can access the assessment attempt pages immediately.

3) Route / Access policy
   - Keep parent portal routes protected using role:parent (unchanged).
   - Public assessment attempt routes already accessible from public route group. Ensure AssessmentController.attempt & attemptSubmit do not forcibly check role === parent; update any logic that denies non-parent users. Key: allow attempt for users who are authenticated as guest_parent if Access exists.
   - For pages that were previously only shown in parent portal (portal/assessments, portal/lessons), create public equivalents or make components that can render for guest_parent on public side:
     - E.g., /my-purchases (public route) — return list of assessments/lessons from Access for Auth::user()->children
   - Add a middleware or small helper to restrict portal UI links from appearing for guest_parent. RoleMiddleware already does the job if the page route requires role:parent.

4) Assessment attempt behavior & limits
   - Allow immediate attempts for purchased assessments if Access exists (access.access === true).
   - Optionally withhold official PDF/report or downloadable certificate until onboarding complete:
     - Add rule: if user->role === guest_parent and onboarding_complete === false => allow attempt & immediate scoring, but mark generated report as "provisional" and only auto-email final report once onboarding_complete === true (configurable).
   - Keep attempt submission flow identical; AssessmentSubmission.user_id and child_id will be set as existing code does.

5) Frontend changes
   - Add a quick-checkout modal/UI on public service page to collect minimal info and trigger /checkout POST.
   - Add "My Purchases" public page under public routes:
     - Shows list of purchased assessments/lessons for logged-in guest_parent/parent.
     - Show actions: Attempt (if Access exists) and a banner CTA: "Complete profile to unlock parent portal".
   - On assessment attempt page, if user is guest_parent, show top-alert: "You're using a quick account. Complete your profile to access full parent portal features."
   - Add "Complete profile" flow which updates onboarding_complete and upgrades role to parent (and optionally sets email_verified_at and invites to setup password).

6) Emails & Onboarding
   - After creating guest_parent user, send an email:
       - Welcome + set password link OR magic-link for passwordless onboarding.
       - Transaction receipt + link to "My purchases" and "Complete your profile".
   - If payment fails, show error and don't create Access until paid.

7) Security & housekeeping
   - Prevent duplicate accounts: if existing parent user exists for the email, prefer asking them to login; don't create guest_parent duplicate.
   - Rate-limit guest checkouts from same IP/email.
   - Create a scheduled job to clean / convert abandoned guest_parent accounts older than X days (e.g. 30 days). Optionally anonymize or remove temp accounts.
   - Audit transactional operations and idempotency keys for payment provider calls to avoid duplicate invoices/charges.

8) Small code changes & sample pseudocode
   - Add to app/Models/User.php:
       public const ROLE_GUEST_PARENT = 'guest_parent';
   - CheckoutController snippet (pseudocode):
       if (!Auth::user()) {
         $validated = $request->validate([...]);
         $user = User::firstOrCreate(['email' => $validated['email']], [
             'name' => $validated['name'] ?? 'Parent',
             'role' => User::ROLE_GUEST_PARENT,
             'password' => Hash::make(Str::random(40)),
             'onboarding_complete' => false
         ]);
         // create child if required
         Child::create([... 'user_id' => $user->id ...]);
       } else {
         $user = Auth::user();
       }
       Auth::login($user); // for immediate session
       // proceed to create billing customer, transaction, invoice, grant access...

9) Tests & QA
   - Test flows:
     - Existing authenticated parent checkout (must remain unchanged).
     - New guest checkout buying assessment => should create guest_parent, child, billing customer, invoice, transaction, access, and allow attempt immediately.
     - Attempt page should work for guest_parent.
     - Guest_parent should not be able to access routes guarded by role:parent (parent portal).
     - Onboarding upgrade: user completes profile => role upgraded to parent and portal access appears.

Checklist / task_progress
-------------------------
- [x] Analyze requirements and inspect CheckoutController
- [x] Inspect RoleMiddleware and auth patterns
- [x] Inspect assessment-related models and Access model
- [x] Inspect User and Child models
- [ ] Create migration: add `onboarding_complete` boolean + `temporary_at` timestamp (optional)
- [ ] Add User::ROLE_GUEST_PARENT constant
- [ ] Update CheckoutController: guest create/find user, child creation, billing customer creation, transaction & invoice flow, Auth::login($user)
- [ ] Ensure BillingService idempotency and handles temporary users' customers
- [ ] Update AssessmentController attempt logic to allow guest_parent if Access exists and show proper banner
- [ ] Add public "My Purchases" page + UI components + quick-checkout modal
- [ ] Add email flows: welcome / set-password or magic-link + receipt
- [ ] Add scheduled cleanup for abandoned guest_parent accounts
- [ ] Add rate-limiting / captcha to guest checkout to limit abuse
- [ ] QA and staging deployment

Risks and edge-cases
--------------------
- Duplicate email (existing parent) — handle by prompting login / magic link rather than creating a new user.
- Fraud / chargebacks — guest creation + immediate access increases risk. Use fraud checks, amount thresholds, and/or 3DS depending on payment provider.
- Payment provider idempotency — use idempotency-keys.
- Credential/verification: if using Auth::login($user) after creation, ensure you do not inadvertently allow guest_parent to use parent-only features. RoleMiddleware protects server routes, but be mindful of any client-side links or components that assume parent role.

Next step I can implement now (pick one)
---------------------------------------
Choose one and I will implement it:
- Create migration and add User::ROLE_GUEST_PARENT + onboarding_complete column (backend only).
- Implement CheckoutController changes for guest checkout (backend) so public purchase works end-to-end.
- Create public "My Purchases" endpoint + minimal frontend page that lists Access items and links to attempt pages.
- Produce PR-ready patches for the above (migration + controller + tests).

If you want me to start coding now, tell me which of the next steps above to implement first and I will create the migration and controller changes accordingly.
