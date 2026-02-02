Summary — email timing, cart persistence and recommended flow
-------------------------------------------------------------

Questions you asked:
- When should we send emails (welcome / verify / receipt / access granted)?
- If we redirect the user to email verification (or they click the magic link), do we persist their checkout (cart / selected services)? Where is the source-of-truth: cart session or DB?

Short answer (recommended)
- Send the verification/welcome email immediately after creating the minimal guest user (background job).
- Persist the checkout server-side by creating a pending Transaction + TransactionItems before redirecting to any verification page or payment provider. Use the DB Transaction as the authoritative source-of-truth (not just session).
- Use CartSession only as the transient UI layer; snapshot it into DB early. Process payment (sync or webhook) and mark Transaction complete on success, then create Access rows and send the receipt + “Access granted” email.

Detailed sequence (safe, resilient, good UX)
1. User clicks Buy on public service page → quick modal collects: email (required), parent name (optional), child name (required for assessments).
2. POST /checkout (guest flow):
   - Validate inputs.
   - If an existing parent user with that email exists → prompt login or send magic-login to avoid duplicate accounts.
   - Else create User (role = guest_parent), create Child record(s) linked to that user.
   - Immediately send a background email:
     - Welcome + magic-link / set-password token (so they can complete onboarding later). This is informational and does not block payment.
3. Snapshot cart → create Transaction (status: pending) + TransactionItems using CartSession::current()->items:
   - This makes the purchase durable server-side even if the browser/session is lost or they verify via another device.
   - Optionally persist an idempotency key to avoid duplicate invoices if the user retries.
4. Auth::login($user) for the current session so buyer can continue seamlessly in-browser (optional but good for UX).
5. Open payment flow (widget or redirect).
   - If payment is synchronous (widget): create invoice + confirm payment in same request, set Transaction.status = completed, create Access rows, clear cart session.
   - If payment is asynchronous (external redirect / webhook): show “waiting for payment” UI; rely on webhook to mark Transaction completed and create Access rows.
6. On payment success:
   - Create invoice/attach invoice id to Transaction.
   - Set Transaction.status = completed; save.
   - Create Access rows for child/assessments/lessons and set payment_status=paid.
   - Send receipt email + “Access granted” email with direct links to My Purchases and assessment attempt pages and a CTA to complete profile.
7. If payment fails:
   - Keep Transaction.status = pending/failed, send failure email with instructions, and optionally keep cart/session intact. Retry paths should use the existing pending Transaction.
8. Later, when user completes onboarding (magic link / set password / verify email / fill profile):
   - Update user.onboarding_complete = true and optionally change role to parent.
   - If official certificates/reports were gated, send them now.

Why server-side Transaction-first is preferred
- Durable: survives session loss, device switching, and email verification processes.
- Easier to reconcile with payment webhooks.
- Avoids losing the user's purchase intent when they are sent to email verification or payment provider.
- Keeps cart UI snappy (CartSession still used), but DB Transaction is the canonical record.

CartSession usage recommendations
- Use CartSession for temporary UI state and to populate form data when user returns before Transaction is created.
- Immediately snapshot CartSession into TransactionItems when the user submits the minimal checkout form.
- You may clear the CartSession after creating the Transaction (because DB holds the snapshot). Or keep it until Transaction completed if you prefer to restore UI while waiting for final webhook.

Edge cases & rules
- Duplicate email (existing parent): do NOT create a second account. Offer:
  - Prompt to login
  - Or send magic link to that email to authenticate and attach purchase to the existing account
- Idempotency: store idempotency key for the checkout attempt to avoid creating duplicate Transactions/Invoices on retries.
- Fraud: rate-limit guest checkouts; consider 3DS or additional checks for high-value purchases.
- Abandoned pending transactions: schedule a cleanup job to expire or notify users after N days.

Technical pointers in this repo (where to change)
- CheckoutController::store — currently reads CartSession and creates Transaction. Extend it to:
  - Accept guest payload when Auth::user() is null
  - Create/find guest_parent user + child
  - Create Transaction + items immediately (status: pending)
  - Call BillingService.createCustomer($user) before invoice creation
  - If payment synchronous: createInvoice and mark completed + create Access
  - If async: rely on webhook to mark completed and create Access
- CartSession helper (app/Helpers/CartSession.php) — continue using for transient UI, but snapshot to DB as above
- BillingService — ensure idempotent customer creation for temporary users
- Mailables: SendLoginCredentials, VerifyApplicationEmail, AssessmentReportMail — reuse/extend to send magic-link & receipts

Example minimal flow in code (pseudocode)
- POST /checkout:
    $validated = $request->validate([...]);
    $cart = CartSession::current()->load('items.service', 'items.product');
    DB::transaction(function () use ($validated, $cart, $request) {
        if (!Auth::user()) {
            $user = User::firstOrCreate(['email' => $validated['email']], [
                'name' => $validated['name'] ?? 'Parent',
                'role' => User::ROLE_GUEST_PARENT,
                'password' => Hash::make(Str::random(40)),
                'onboarding_complete' => false,
                'temporary_at' => now(),
            ]);
            Child::create([... , 'user_id' => $user->id]);
            dispatch(new SendMagicLinkEmail($user)); // background
            Auth::login($user);
        } else {
            $user = Auth::user();
        }
        // Snapshot cart to Transaction + TransactionItems (status pending)
        $tx = Transaction::create([...]);
        $tx->items()->createMany([...]);
        // proceed to payment widget / return payment data to frontend
    });
    // Frontend opens payment widget; on success, server marks tx completed and creates Access.

Task progress (updated)
- [x] Analyze requirements and inspect CheckoutController
- [x] Inspect RoleMiddleware and auth patterns
- [x] Inspect assessment-related models and Access model
- [x] Inspect User and Child models
- [ ] Create migration: add `onboarding_complete` boolean + `temporary_at` timestamp (optional)
- [x] Add User::ROLE_GUEST_PARENT constant
- [ ] Update CheckoutController: guest create/find user, child creation, billing customer creation, transaction & invoice flow, Auth::login($user)
- [ ] Ensure BillingService idempotency and handles temporary users' customers
- [ ] Update AssessmentController attempt logic to allow guest_parent if Access exists and show proper banner
- [ ] Add public "My Purchases" page + UI components + quick-checkout modal
- [ ] Add email flows: welcome / set-password or magic-link + receipt
- [ ] Add scheduled cleanup for abandoned guest_parent accounts
- [ ] Add rate-limiting / captcha to guest checkout to limit abuse
- [ ] QA and staging deployment

Next action
-----------
I can implement the server-side Transaction-first guest-checkout flow now (migration + CheckoutController changes + email dispatch). Confirm if you want:
- (A) immediate implementation of backend changes (I will create migration, controller updates, and mail dispatch calls), or
- (B) produce a PR-ready patch set (so you can review before applying).

If you choose (A), I will start by creating the DB migration for onboarding fields and then update CheckoutController as described.
