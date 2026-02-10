<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
// use Illuminate\Support\Facades\Auth;
use App\Helpers\CartSession;
use App\Models\Transaction;
use App\Models\Service;
use App\Models\Product;
use Inertia\Inertia;
use App\Services\BillingService;
use App\Services\GuestCheckoutService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class CheckoutController extends Controller
{
    protected $billing;
     protected string $apiKey;
    protected $guestCheckout;

    public function __construct(BillingService $billing, GuestCheckoutService $guestCheckout)
    {
        $this->billing = $billing;
        $this->guestCheckout = $guestCheckout;
        $this->apiKey = config('services.billing.publishable_key');
    }

    /* ───────── GET /checkout ───────── */
    public function show()
    {

        $user = Auth::user();
        if ($user && !$user->billing_customer_id) {
        $custId = $this->billing->createCustomer($user);
        if ($custId) {
            $user->billing_customer_id = $custId;
            $user->saveQuietly();
            Log::info('Created billing customer ID on checkout page load', [
                'user_id' => $user->id,
                'billing_customer_id' => $custId,
            ]);
        }
        }
        if ($user) {
            $children = $user->children
                ->map(function($c){
                    return [
                        'id'         => $c->id,
                        'child_name' => $c->child_name,
                    ];
                })
                ->values()    // re-index 0,1,2…
                ->toArray();
            Log::info('Children:', $children);
        } else {
            // guest user — no children available yet
            $children = [];
            Log::info('CheckoutController: show() called by guest — no children available.');
        }
        // get cart with both relations ready
        $cart = CartSession::current()->load('items.service', 'items.product');

        // build a clean items array with a uniform “name” field
        $items = $cart->items->map(function ($ci) {
            $buyable = $ci->service ?? $ci->product;       // whichever exists

            return [
                'id'    => $ci->id,
                'type'  => $ci->service_id ? 'service' : 'product',
                'service' => $ci->service ? $ci->service->toArray() : null,
                'product' => $ci->product ? $ci->product->toArray() : null,
                'name'  => $buyable->display_name           // accessor on both models
                           ?? ($buyable->service_name ?? $buyable->name),
                'qty'   => $ci->quantity,
                'price' => $ci->price,
            ];
        })->all();

        $subtotal = array_reduce(
            $items,
            fn ($sum, $it) => $sum + $it['qty'] * $it['price'],
            0
        );

        return Inertia::render('@public/Checkout/Index', [
            'cart' => [
                'items'    => $items,
                'subtotal' => $subtotal,
            ],
            'childrens'=>$children,
        ]);
    }

    /* ───────── POST /checkout ───────── */
    /**
     * AJAX: Send a one-time verification code to the provided email before creating a guest account.
     * Request body: { email: string, guest_name?: string }
     * Response: JSON { status: 'ok' } or error.
     */
    public function sendGuestCode(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'guest_name' => 'nullable|string|max:255',
        ]);

        $email = strtolower($data['email']);

        // If the email already belongs to a full account (parent/admin/guest_parent),
        // reject and ask them to login instead of proceeding with OTP flow.
        $existingUser = \App\Models\User::where('email', $email)->first();
        if ($existingUser && in_array(($existingUser->role ?? ''), ['parent', 'admin', 'guest_parent'])) {
            Log::info('sendGuestCode: blocked send for existing account', ['email' => $email, 'role' => $existingUser->role]);
            return response()->json([
                'status' => 'error',
                'message' => 'An account with this email already exists. Please log in instead.',
            ], 409);
        }

        // Basic rate-limit: one request per 60s per email
        $lastKey = "guest_verification_last:{$email}";
        if (Cache::has($lastKey) && (int) Cache::get($lastKey) > time() - 60) {
            return response()->json(['status' => 'error', 'message' => 'Please wait before requesting another code.'], 429);
        }

        // Generate a 6-digit code
        $code = random_int(100000, 999999);

        // Persist the code in cache for 10 minutes
        $codeKey = "guest_verification:{$email}";
        Cache::put($codeKey, (string)$code, now()->addMinutes(10));
        Cache::put($lastKey, time(), now()->addMinutes(10));

        // Send branded verification email.
        try {
            Mail::to($email)->send(new \App\Mail\GuestVerificationCode((string) $code, $email));
            Log::info('sendGuestCode: verification email sent', ['email' => $email]);
            Log::info('sendGuestCode: debug-code', ['email' => $email, 'code' => (string)$code]);
        } catch (\Throwable $e) {
            Log::warning('sendGuestCode: failed to send verification email', ['email' => $email, 'error' => $e->getMessage()]);
            return response()->json(['status' => 'error', 'message' => 'Could not send verification email.'], 500);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Verify the code and create the guest account + children in a single operation.
     * Expected JSON body:
     * {
     *   email: "...",
     *   guest_name: "...",
     *   code: "123456",
     *   children: [{ child_name, date_of_birth, ... }, ...]
     * }
     *
     * On success: creates the guest user and children server-side, logs the user in and returns JSON { status:'ok', redirect: route('checkout.show') }
     */
    public function verifyGuestCode(Request $request)
    {
        Log::info('🔍 verifyGuestCode: called', ['input' => $request->all()]);
        $payload = $request->validate([
            'email' => 'required|email',
            'guest_name' => 'nullable|string|max:255',
            'code' => 'required|string',
            'children' => 'nullable|array',
            'children.*.child_name' => 'required_with:children|string|max:255',
            'children.*.date_of_birth' => 'nullable|date',
            'organization_id' => 'required|integer|exists:organizations,id',
        ]);

        $email = strtolower($payload['email']);
        $codeKey = "guest_verification:{$email}";
        $cached = Cache::get($codeKey);

        // Enhanced debugging logs
        Log::info('🔍 verifyGuestCode: Starting verification', [
            'email' => $email,
            'code_key' => $codeKey,
            'provided_code' => $payload['code'],
            'provided_code_type' => gettype($payload['code']),
            'provided_code_length' => strlen($payload['code']),
            'cached_code' => $cached,
            'cached_code_type' => gettype($cached),
            'cached_code_length' => $cached ? strlen((string)$cached) : null,
            'cache_exists' => $cached !== null,
        ]);

        if (! $cached || (string)$cached !== (string)$payload['code']) {
            Log::warning('❌ verifyGuestCode: Invalid code', [
                'email' => $email,
                'reason' => !$cached ? 'Code not found in cache (expired or never sent)' : 'Code mismatch',
                'cached_code' => $cached,
                'provided_code' => $payload['code'],
                'strict_comparison' => $cached === $payload['code'],
                'loose_comparison' => $cached == $payload['code'],
                'string_comparison' => (string)$cached === (string)$payload['code'],
            ]);
            return response()->json(['status' => 'error', 'message' => 'Invalid or expired code.'], 422);
        }

        Log::info('✅ verifyGuestCode: Code matched successfully', [
            'email' => $email,
        ]);

        // Code valid — create guest user and children (reuse existing logic)
        $res = $this->guestCheckout->findOrCreateGuestUser([
            'email' => $email,
            'name'  => $payload['guest_name'] ?? null,
            'organization_id' => $payload['organization_id'],
        ]);

        if ($res['status'] === 'invalid') {
            return response()->json(['status' => 'error', 'message' => 'Invalid email.'], 422);
        }
        if ($res['status'] === 'existing_parent') {
            return response()->json(['status' => 'error', 'message' => 'An account with this email already exists. Please login.'], 409);
        }

        $user = $res['user'];
        $createdChildren = [];

        $childrenInput = $payload['children'] ?? [];

        if (!empty($childrenInput) && is_array($childrenInput)) {
            foreach ($childrenInput as $index => $c) {
                try {
                    $child = $this->guestCheckout->createChildForUser($user, [
                        'child_name' => $c['child_name'] ?? null,
                        'date_of_birth' => $c['date_of_birth'] ?? null,
                        'age' => isset($c['age']) ? (int)$c['age'] : 0,
                        'year_group' => $c['year_group'] ?? null,
                        'school_name' => $c['school_name'] ?? null,
                        'area' => $c['area'] ?? null,
                        'organization_id' => $payload['organization_id'],
                    ]);
                } catch (\Throwable $e) {
                    Log::error('verifyGuestCode: exception while creating child', [
                        'user_id' => $user->id ?? null,
                        'index' => $index,
                        'error' => $e->getMessage(),
                    ]);
                    $child = null;
                }

                if ($child) {
                    $createdChildren[] = $child->id;
                }
            }

            try {
                session()->put('guest_created_children', $createdChildren);
            } catch (\Throwable $e) {
                Log::warning('verifyGuestCode: failed to persist created children to session', [
                    'error' => $e->getMessage(),
                    'user_id' => $user->id ?? null,
                ]);
            }
        }

        // Consume the code and last-key
        try {
            Cache::forget($codeKey);
            Cache::forget("guest_verification_last:{$email}");
        } catch (\Throwable $e) {
            Log::warning('verifyGuestCode: failed to clear cache keys', ['email' => $email, 'error' => $e->getMessage()]);
        }

        // Log user in
        Auth::login($user);

        // Return JSON for AJAX caller
        if ($request->wantsJson() || $request->isJson()) {
            return response()->json(['status' => 'ok', 'redirect' => route('checkout.show')]);
        }

        // Fallback redirect
        return redirect()->route('checkout.show');
    }

    // Pre-check endpoint: create a guest parent and optionally multiple children in one request,
    // then log them in and redirect to checkout where services can be assigned.
    public function createGuest(Request $request)
    {
        // Accept parent data and optional children array.
        // Use Validator so we can log validation failures for debugging.
        $validator = Validator::make($request->all(), [
            'guest_name' => 'nullable|string|max:255',
            'email' => 'required|email',
            'children' => 'array',
            'children.*.child_name' => 'required|string|max:255',
            'children.*.date_of_birth' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            Log::warning('createGuest validation failed', [
                'errors' => $validator->errors()->toArray(),
                'input' => $request->all(),
                'ip' => $request->ip(),
            ]);
            // Return JSON for AJAX clients, otherwise redirect back with errors
            if ($request->wantsJson() || $request->isJson()) {
                return response()->json([
                    'status' => 'error',
                    'errors' => $validator->errors()->toArray(),
                ], 422);
            }
            return back()->withErrors($validator)->withInput();
        }

        $data = $validator->validated();

        // Log payload for debugging
        Log::info('createGuest called', [
            'payload' => $data,
            'is_json' => $request->isJson(),
            'ip' => $request->ip(),
        ]);

        // Attempt to find or create a guest user
        $res = $this->guestCheckout->findOrCreateGuestUser([
            'email' => $data['email'],
            'name'  => $data['guest_name'] ?? null,
        ]);

        if ($res['status'] === 'invalid') {
            return back()->with('error', 'Please provide a valid email.');
        }

        if ($res['status'] === 'existing_parent') {
            // If a full parent account exists, we ask them to login instead of auto-attaching
            return back()->with('error', 'An account with this email already exists. Please login to continue.');
        }

        $user = $res['user'];

        // If children were provided, create them server-side and link to the new user
        $createdChildren = [];
        if (!empty($data['children']) && is_array($data['children'])) {
            foreach ($data['children'] as $index => $c) {
                // Log incoming child payload before attempting create
                Log::info('createGuest: creating child (start)', [
                    'user_id' => $user->id ?? null,
                    'index' => $index,
                    'child_payload' => $c,
                ]);

                try {
                    $child = $this->guestCheckout->createChildForUser($user, [
                        'child_name' => $c['child_name'] ?? null,
                        'date_of_birth' => $c['date_of_birth'] ?? null,
                        'age' => isset($c['age']) ? (int)$c['age'] : 0,
                        'year_group' => $c['year_group'] ?? null,
                        'school_name' => $c['school_name'] ?? null,
                        'area' => $c['area'] ?? null,
                    ]);
                } catch (\Throwable $e) {
                    Log::error('createGuest: exception while creating child', [
                        'user_id' => $user->id ?? null,
                        'index' => $index,
                        'child_payload' => $c,
                        'error' => $e->getMessage(),
                    ]);
                    $child = null;
                }

                if ($child) {
                    $createdChildren[] = $child->id;
                    Log::info('createGuest: child created successfully', [
                        'user_id' => $user->id ?? null,
                        'child_id' => $child->id,
                        'index' => $index,
                    ]);
                } else {
                    Log::warning('createGuest: child creation returned null', [
                        'user_id' => $user->id ?? null,
                        'index' => $index,
                        'child_payload' => $c,
                    ]);
                }
            }

            Log::info('createGuest: created children for guest (summary)', [
                'user_id' => $user->id,
                'children_created' => $createdChildren,
                'attempted_count' => count($data['children']),
            ]);
            // Persist created children IDs to session so subsequent guestStore requests
            // (which create the transaction/invoice) can map services to these children
            // if the client did not provide an explicit service->child mapping.
            try {
                session()->put('guest_created_children', $createdChildren);
            } catch (\Throwable $e) {
                Log::warning('createGuest: failed to persist created children to session', [
                    'error' => $e->getMessage(),
                    'user_id' => $user->id ?? null,
                ]);
            }
        }

        // Log user in for this session
        Auth::login($user);

        // Redirect to the checkout page where the newly-created children will be available
        return redirect()->route('checkout.show');
    }

    /**
     * Guest store: create transaction + invoice for guest and redirect to billing widget.
     *
     * This differs from `store()` in that:
     *  - We create the transaction and invoice, then redirect the guest to the billing widget
     *    where they complete payment.
     *  - Access will be granted only after the billing provider notifies us (webhook or API callback).
     */
    public function guestStore(Request $request)
    {
        $cart = CartSession::current()->load('items.service', 'items.product');

        if ($cart->items->isEmpty()) {
            return back()->with('error', 'Your basket is empty.');
        }

        $data = $request->validate([
            'comment' => 'nullable|string|max:500',
            'email'   => 'nullable|email',
            'type'    => 'required|in:purchase,gift',
            'serviceChildren' => 'array',
            'serviceChildren.*' => 'required|integer|exists:children,id',
        ]);

        // The guest user should have been created by createGuest() and be logged in.
        $user = Auth::user();
        if (! $user) {
            Log::warning('guestStore called without authenticated user', ['input' => $request->all()]);
            return redirect()->route('checkout.show')->with('error', 'Please create a temporary account before proceeding to payment.');
        }

        // Ensure billing_customer_id exists (create if missing)
        if (!$user->billing_customer_id) {
            $customerId = $this->billing->createCustomer($user);
            if ($customerId) {
                $user->billing_customer_id = $customerId;
                $user->save();
            } else {
                Log::warning('guestStore: could not create billing customer', ['user_id' => $user->id]);
                return back()->with('error', 'Could not create billing customer. Please try again.');
            }
        } else {
            $customerId = $user->billing_customer_id;
        }

        // Create transaction and items (do not grant access yet; wait for payment)
        // IMPORTANT: Extract flexible selections BEFORE deleting cart
        $flexibleSelections = [];
        foreach ($cart->items as $ci) {
            if ($ci->service_id && $ci->metadata) {
                $service = Service::find($ci->service_id);
                if ($service && $service->isFlexibleService()) {
                    $flexibleSelections[$ci->service_id] = $ci->metadata;
                    Log::info('guestStore: extracted flexible selections from cart', [
                        'service_id' => $ci->service_id,
                        'metadata' => $ci->metadata,
                    ]);
                }
            }
        }

        $transaction = DB::transaction(function () use ($cart, $data, $user) {
            $subtotal = $cart->items->sum(fn ($ci) => $ci->quantity * $ci->price);

            $tx = Transaction::create([
                'user_id'        => $user->id,
                'user_email'     => $user->email ?? $data['email'],
                'type'           => $data['type'],
                'status'         => 'pending',
                'payment_method' => 'manual',
                'subtotal'       => $subtotal,
                'total'          => $subtotal,
                'comment'        => $data['comment'] ?? null,
            ]);

            $rows = $cart->items->map(function ($ci) {
                $buyable = $ci->service ?? $ci->product;

                return [
                    'item_type'   => $ci->service ? Service::class : Product::class,
                    'item_id'     => $ci->service_id ?? $ci->product_id,
                    'description' => $buyable->display_name ?? ($buyable->service_name ?? $buyable->name),
                    'qty'         => $ci->quantity,
                    'unit_price'  => $ci->price,
                    'line_total'  => $ci->quantity * $ci->price,
                ];
            })->all();

            $tx->items()->createMany($rows);

            // Empty the cart
            $cart->items()->delete();

            return $tx;
        });

        // Persist serviceChildren mapping into transaction meta for later access granting
        $serviceChildren = $data['serviceChildren'] ?? [];

        Log::info('🔍 guestStore: Processing serviceChildren mapping', [
            'transaction_id' => $transaction->id,
            'user_id' => $user->id,
            'user_role' => $user->role,
            'provided_serviceChildren' => $serviceChildren,
        ]);

        // If the client did not provide an explicit mapping, attempt a safe fallback:
        // - If the guest pre-check created exactly one child, map all services to that child.
        // - If the number of created children equals the number of distinct service items, map by order.
        // - Otherwise leave mapping empty (but still persist an empty mapping to avoid null).
        if (empty($serviceChildren)) {
            $sessionChildren = session('guest_created_children', []);
            // get distinct service IDs from the transaction items
            $serviceIds = $transaction->items
                ->filter(fn($item) => $item->item_type === Service::class)
                ->pluck('item_id')
                ->unique()
                ->values()
                ->toArray();

            if (!empty($sessionChildren)) {
                if (count($sessionChildren) === 1) {
                    // map every service to the single created child
                    $mapped = [];
                    foreach ($serviceIds as $sid) {
                        $mapped[$sid] = $sessionChildren[0];
                    }
                    $serviceChildren = $mapped;
                } elseif (count($sessionChildren) === count($serviceIds)) {
                    // map by position (best-effort)
                    $mapped = [];
                    foreach ($serviceIds as $i => $sid) {
                        $mapped[$sid] = $sessionChildren[$i] ?? null;
                    }
                    $serviceChildren = $mapped;
                }
                // Clear session helper after consumption
                try {
                    session()->forget('guest_created_children');
                } catch (\Throwable $e) {
                    Log::warning('guestStore: failed to clear guest_created_children from session', [
                        'error' => $e->getMessage(),
                        'transaction_id' => $transaction->id,
                    ]);
                }
            }
        }

        // Always persist (even empty) to avoid null meta which prevents reconciler from knowing mapping intent
        $meta = $transaction->meta ?? [];
        $meta['serviceChildren'] = $serviceChildren;
        
        // Save flexible selections if any were extracted
        if (!empty($flexibleSelections)) {
            $meta['flexibleSelections'] = $flexibleSelections;
            Log::info('guestStore: saved flexible selections to transaction.meta', [
                'transaction_id' => $transaction->id,
                'flexibleSelections' => $flexibleSelections,
            ]);
        }
        
        $transaction->meta = $meta;
        $transaction->save();
        
        // Enhanced logging for debugging guest user course access
        Log::info('✅ guestStore: Transaction created successfully', [
            'transaction_id' => $transaction->id,
            'user_id' => $user->id,
            'user_role' => $user->role,
            'status' => $transaction->status,
            'serviceChildren' => $serviceChildren,
            'has_flexible_selections' => !empty($flexibleSelections),
            'service_items' => $transaction->items->filter(fn($item) => $item->item_type === Service::class)->map(function($item) {
                $service = Service::find($item->item_id);
                return [
                    'service_id' => $item->item_id,
                    'service_name' => $service->service_name ?? 'Unknown',
                    'is_course_service' => $service ? $service->isCourseService() : false,
                    'course_id' => $service->course_id ?? null,
                ];
            })->toArray(),
        ]);

        // Prepare invoice data for API (similar to store)
        $serviceStartTimes = $transaction->items
            ->filter(fn($item) => $item->item_type === Service::class)
            ->map(fn($item) => Service::find($item->item_id)->start_datetime)
            ->filter()
            ->map(fn($dt) => Carbon::parse($dt));

        if ($serviceStartTimes->isNotEmpty()) {
            $earliest = $serviceStartTimes->min();
            $dueDate = $earliest->copy()->subDay()->toDateString();
        } else {
            $dueDate = now()->addDays(7)->toDateString();
        }

        $invoiceData = [
            "customer_id" => $customerId,
            "due_date" => $dueDate,
            "description" => "Payment for services",
            "items" => $transaction->items->map(function ($item) {
                return [
                    "description" => $item->description,
                    "quantity" => $item->qty,
                    "unit_amount" => intval($item->unit_price * 100), // cents
                    "currency" => "usd"
                ];
            })->toArray(),
            "currency" => "usd",
            "auto_bill" => false, // guest will pay via widget
            "status" => "open",
        ];

        $invoiceId = $this->billing->createInvoice($invoiceData);
        if (! $invoiceId) {
            Log::error('❌ guestStore: createInvoice failed', [
                'transaction_id' => $transaction->id,
                'user_id' => $user->id,
                'invoiceData' => $invoiceData,
            ]);
            return back()->with('error', 'Could not create invoice. Please try again.');
        }

        // Persist invoice id and keep status as pending_payment
        $transaction->invoice_id = $invoiceId;
        $transaction->status = 'pending';
        $transaction->save();
        
        Log::info('📄 guestStore: Invoice created successfully', [
            'transaction_id' => $transaction->id,
            'invoice_id' => $invoiceId,
            'user_id' => $user->id,
            'status' => 'pending',
            'awaiting_payment' => true,
        ]);

        // Build billing widget URL (used for non-AJAX fallback)
        // Redirect to the React/Inertia payment widget page which renders the provider widget in an iframe.
        $billingUrl = url('/payment-widget') . '?api_key=' . urlencode($this->apiKey)
            . '&customer_id=' . urlencode($customerId)
            . '&invoice_id=' . urlencode($invoiceId)
            . '&return_to=' . urlencode(route('checkout.show'));

        Log::info('💳 guestStore: Redirecting to billing widget', [
            'transaction_id' => $transaction->id,
            'customer_id' => $customerId,
            'invoice_id' => $invoiceId,
            'billing_url' => $billingUrl,
            'next_step' => 'User will complete payment, webhook will update transaction status and grant access',
        ]);

        // If the request expects JSON (AJAX from client), return JSON with the widget URL so the client can open it.
        if ($request->wantsJson() || $request->isJson()) {
            Log::info('guestStore: Returning JSON response with billing URL');
            return response()->json([
                'status' => 'ok',
                'billing_url' => $billingUrl,
                'invoice_id' => $invoiceId,
                'customer_id' => $customerId,
            ]);
        }

        // Non-AJAX fallback: redirect the browser server-side.
        Log::info('guestStore: Redirecting browser to billing widget');
        return redirect($billingUrl);
    }

    public function store(Request $request)
    {
        $cart = CartSession::current()->load('items.service', 'items.product');

        if ($cart->items->isEmpty()) {
            return back()->with('error', 'Your basket is empty.');
        }

        $data = $request->validate([
            'comment' => 'nullable|string|max:500',
            'email'   => 'nullable|email',
            'type'    => 'required|in:purchase,gift',
            'serviceChildren' => 'array',
            'serviceChildren.*' => 'required|integer|exists:children,id',
        ]);

        $user = Auth::user();
        $guestCreatedChild = null;

        // Guest quick-checkout: if no authenticated user, create/find a guest_parent and a child
        if (! $user) {
            $guestData = $request->validate([
                'email' => 'required|email',
                'guest_name' => 'nullable|string|max:255',
                'guest_child_name' => 'required|string|max:255',
                'guest_child_dob' => 'nullable|date',
            ]);

            $res = $this->guestCheckout->findOrCreateGuestUser([
                'email' => $guestData['email'],
                'name'  => $guestData['guest_name'] ?? null,
            ]);

            if ($res['status'] === 'invalid') {
                return back()->with('error', 'Please provide a valid email.');
            }

            if ($res['status'] === 'existing_parent') {
                // If a full parent account exists, prompt the user to log in instead of auto-attaching
                return back()->with('error', 'An account with this email already exists. Please login to continue.');
            }

            $user = $res['user'];

            // create child for this guest user
            $guestCreatedChild = $this->guestCheckout->createChildForUser($user, [
                'child_name' => $guestData['guest_child_name'],
                'date_of_birth' => $guestData['guest_child_dob'] ?? null,
            ]);

            // Map service items to the newly created child if serviceChildren mapping not provided
            $serviceIds = $cart->items->filter(fn($ci) => $ci->service_id)->pluck('service_id')->unique()->toArray();
            foreach ($serviceIds as $sid) {
                $data['serviceChildren'][$sid] = $guestCreatedChild ? $guestCreatedChild->id : null;
            }

            // Log the guest checkout creation
            Log::info('Guest checkout: created/located guest user', [
                'user_id' => $user->id,
                'email' => $user->email,
                'child_id' => $guestCreatedChild ? $guestCreatedChild->id : null,
            ]);

            // Log the user in for the current session so they can continue immediately
            Auth::login($user);
        }

        // Ensure billing_customer_id exists
        if (!$user->billing_customer_id) {
            $customerId = $this->billing->createCustomer($user);
            if ($customerId) {
                $user->billing_customer_id = $customerId;
                $user->save();
            }
        } else {
            $customerId = $user->billing_customer_id;
        }

        $transaction = DB::transaction(function () use ($cart, $data, $user) {
            $subtotal = $cart->items->sum(
                fn ($ci) => $ci->quantity * $ci->price
            );

            /* header */
            $tx = Transaction::create([
                'user_id'        => $user->id,
                'user_email'     => $user->email ?? $data['email'],
                'type'           => $data['type'],
                'status'         => 'pending',
                'payment_method' => 'manual',
                'subtotal'       => $subtotal,
                'total'          => $subtotal,
                'comment'        => $data['comment'] ?? null,
            ]);

            /* detail rows */
            $rows = $cart->items->map(function ($ci) {
                $buyable = $ci->service ?? $ci->product;

                return [
                    'item_type'   => $ci->service ? Service::class : Product::class,
                    'item_id'     => $ci->service_id ?? $ci->product_id,
                    'description' => $buyable->display_name
                                     ?? ($buyable->service_name ?? $buyable->name),
                    'qty'         => $ci->quantity,
                    'unit_price'  => $ci->price,
                    'line_total'  => $ci->quantity * $ci->price,
                ];
            })->all();

            $tx->items()->createMany($rows);

            /* empty the cart */
            $cart->items()->delete();

            return $tx;
        });
        // 1) Grab all the Service start_date/times from your transaction items
        $serviceStartTimes = $transaction->items
            ->filter(fn($item) => $item->item_type === Service::class)
            ->map(fn($item) => Service::find($item->item_id)->start_datetime)
            // drop any nulls and parse as Carbon
            ->filter()
            ->map(fn($dt) => Carbon::parse($dt));

        if ($serviceStartTimes->isNotEmpty()) {
            // 2) Find the earliest one
            $earliest = $serviceStartTimes->min();
            // 3) Due date = one day before
            $dueDate = $earliest->copy()->subDay()->toDateString();
        } else {
            // fallback if no service start times in cart
            $dueDate = now()->addDays(7)->toDateString();
        }
       
        // Prepare invoice data for API
        $invoiceData = [
            "customer_id" => $customerId,
            "due_date" => $dueDate,
            "description" => "Payment for services",
            "items" => $transaction->items->map(function ($item) {
                return [
                    "description" => $item->description,
                    "quantity" => $item->qty,
                    "unit_amount" => intval($item->unit_price * 100), // cents
                    "currency" => "usd"
                ];
            })->toArray(),
            "currency" => "usd",
            "auto_bill" => true,
            "status" => "open",
        ];

        $invoiceId = $this->billing->createInvoice($invoiceData);
        if ($invoiceId) {
            $transaction->invoice_id = $invoiceId;
            $transaction->status = 'completed';
            $transaction->save();

            // Dispatch receipt + access-granted emails (queued) with DB-level idempotent updates to avoid races
                try {
                    $email = $transaction->user_email ?? $transaction->user->email;

                    // Queue receipt and atomically set flag if it wasn't set already
                    if (empty($transaction->email_sent_receipt)) {
                        Mail::to($email)->queue(new \App\Mail\ReceiptAccessMail($transaction, 'receipt'));
                        // atomic update: only set flag if still false
                        $updated = DB::table('transactions')
                            ->where('id', $transaction->id)
                            ->where('email_sent_receipt', false)
                            ->update(['email_sent_receipt' => true, 'updated_at' => now()]);
                        if ($updated) {
                            $transaction->email_sent_receipt = true;
                        }
                    }

                    // Queue access notification and atomically set flag if it wasn't set already
                    if (empty($transaction->email_sent_access)) {
                        Mail::to($email)->queue(new \App\Mail\ReceiptAccessMail($transaction, 'access_granted'));
                        $updated = DB::table('transactions')
                            ->where('id', $transaction->id)
                            ->where('email_sent_access', false)
                            ->update(['email_sent_access' => true, 'updated_at' => now()]);
                        if ($updated) {
                            $transaction->email_sent_access = true;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('Failed to queue receipt/access emails for transaction', [
                        'transaction_id' => $transaction->id,
                        'error' => $e->getMessage(),
                    ]);
                }
        }

        // Grant access to child for each service
        if ($invoiceId) {
            Log::info('Transaction items:', $transaction->items->toArray());
            Log::info('serviceChildren from request:', $data['serviceChildren'] ?? []);
            foreach ($transaction->items as $item) {
               Log::info('Checking transaction item', [
                    'item_type' => $item->item_type,
                    'item_id' => $item->item_id,
                    'service_class' => \App\Models\Service::class,
                ]);
                if ($item->item_type === \App\Models\Service::class) {
                    $service = Service::find($item->item_id);
                    $childId = $data['serviceChildren'][$item->item_id] ?? null;
                    Log::info('Service item childId lookup', [
                        'item_id' => $item->item_id,
                        'childId' => $childId,
                    ]);
                    if ($childId) {
                        // Check if this is a course service
                        if ($service->isCourseService()) {
                            // Use CourseAccessService for course services
                            Log::info('CheckoutController: granting course access via CourseAccessService', [
                                'service_id' => $service->id,
                                'course_id' => $service->course_id,
                                'child_id' => $childId,
                                'transaction_id' => $transaction->id,
                            ]);
                            
                            $courseAccessService = app(\App\Services\CourseAccessService::class);
                            $result = $courseAccessService->grantCourseAccess(
                                childId: $childId,
                                courseId: $service->course_id,
                                transactionId: $transaction->id,
                                 invoiceId: $invoiceId  
                            );
                            
                            Log::info('CheckoutController: course access saved', [
                                'source' => 'CheckoutController::store (course service)',
                                'service_id' => $service->id,
                                'course_id' => $service->course_id,
                                'child_id' => $childId,
                                'transaction_id' => $transaction->id,
                                'result' => $result,
                            ]);
                        } elseif ($service->isFlexibleService()) {
                            // Flexible service - DON'T create access here!
                            // Store selections directly in transaction meta (cart items will be deleted)
                            Log::info('CheckoutController: detected flexible service, deferring to job', [
                                'service_id' => $service->id,
                                'child_id' => $childId,
                                'transaction_id' => $transaction->id,
                            ]);
                            
                            // Find the cart item that has the selections
                            $cartItem = $cart->items->first(fn($ci) => $ci->service_id == $service->id);
                            
                            if ($cartItem && $cartItem->metadata) {
                                // Store the METADATA ITSELF in transaction meta (not just cart item ID)
                                // Because cart items will be deleted immediately after this
                                $meta = $transaction->meta ?? [];
                                $meta['flexibleSelections'] = $meta['flexibleSelections'] ?? [];
                                $meta['flexibleSelections'][$service->id] = $cartItem->metadata;
                                $meta['serviceChildren'] = $meta['serviceChildren'] ?? [];
                                $meta['serviceChildren'][$service->id] = $childId;
                                $transaction->meta = $meta;
                                $transaction->save();
                                
                                Log::info('CheckoutController: stored flexible selections in transaction meta', [
                                    'service_id' => $service->id,
                                    'child_id' => $childId,
                                    'transaction_id' => $transaction->id,
                                    'selections' => $cartItem->metadata,
                                ]);
                                
                                // Queue the job to handle access granting with selections
                                \App\Jobs\GrantAccessForTransactionJob::dispatch($transaction->id)
                                    ->delay(now()->addSeconds(5));
                                    
                                Log::info('CheckoutController: dispatched GrantAccessForTransactionJob for flexible service', [
                                    'transaction_id' => $transaction->id,
                                    'service_id' => $service->id,
                                ]);
                            } else {
                                Log::error('CheckoutController: could not find cart item or metadata for flexible service', [
                                    'service_id' => $service->id,
                                    'transaction_id' => $transaction->id,
                                    'cart_item_exists' => $cartItem ? true : false,
                                    'metadata_exists' => $cartItem && $cartItem->metadata ? true : false,
                                ]);
                            }
                        } else {
                            // Regular service - use array-based access
                            $lessonIds = DB::table('lesson_service')->where('service_id', $service->id)->pluck('lesson_id')->toArray();
                            $assessmentIds = DB::table('assessment_service')->where('service_id', $service->id)->pluck('assessment_id')->toArray();

                            // Check if these live_sessions have linked LiveLessonSession IDs
                            $liveSessionIds = [];
                            if (!empty($lessonIds)) {
                                $liveSessionIds = DB::table('live_sessions')
                                    ->whereIn('id', $lessonIds)
                                    ->whereNotNull('live_lesson_session_id')
                                    ->pluck('live_lesson_session_id')
                                    ->toArray();
                            }

                            $metadata = [
                                'service_id' => $service->id,
                                'service_name' => $service->service_name ?? $service->display_name ?? null,
                                'qty' => $item->qty,
                                'unit_price' => $item->unit_price,
                                'line_total' => $item->line_total,
                            ];

                            // Add LiveLessonSession IDs to metadata if found
                            if (!empty($liveSessionIds)) {
                                $metadata['live_lesson_session_ids'] = $liveSessionIds;
                                Log::info('CheckoutController: found linked LiveLessonSession IDs', [
                                    'service_id' => $service->id,
                                    'live_lesson_session_ids' => $liveSessionIds,
                                ]);
                            }

                            $accessData = [
                                'child_id'        => $childId,
                                'lesson_ids'      => json_encode($lessonIds),
                                'assessment_ids'  => json_encode($assessmentIds),
                                'course_ids'      => json_encode([]),
                                'transaction_id'  => $transaction->id,
                                'invoice_id'      => $invoiceId,
                                'purchase_date'   => now(),
                                'due_date'        => null,
                                'access'          => true,
                                'payment_status'  => 'paid',
                                'refund_id'       => null,
                                'metadata'        => json_encode($metadata),
                                'created_at'      => now(),
                                'updated_at'      => now(),
                            ];
                            Log::info('CheckoutController: saving regular service access', [
                                'source' => 'CheckoutController::store (regular service)',
                                'service_id' => $service->id,
                                'child_id' => $childId,
                                'transaction_id' => $transaction->id,
                                'has_live_lesson_session_ids' => !empty($liveSessionIds),
                                'data' => $accessData,
                            ]);
                            
                            DB::table('access')->insert($accessData);
                            
                            Log::info('CheckoutController: regular service access saved', [
                                'source' => 'CheckoutController::store (regular service)',
                                'service_id' => $service->id,
                                'child_id' => $childId,
                                'transaction_id' => $transaction->id,
                                'has_live_lesson_session_ids' => !empty($liveSessionIds),
                            ]);
                        }
                    }
                }
            }
        }

        return redirect()
            ->route('transactions.show', $transaction->id)
            ->with('success', 'Checkout complete — pending payment.');
    }
}
