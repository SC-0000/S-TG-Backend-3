<?php

namespace App\Http\Controllers\Api;

use App\Helpers\CartSession;
use App\Http\Requests\Api\Checkout\CheckoutStoreRequest;
use App\Http\Requests\Api\Checkout\GuestCodeRequest;
use App\Http\Requests\Api\Checkout\GuestVerifyRequest;
use App\Jobs\GrantAccessForTransactionJob;
use App\Mail\ReceiptAccessMail;
use App\Models\Service;
use App\Models\Product;
use App\Models\Transaction;
use App\Services\BillingService;
use App\Services\CourseAccessService;
use App\Services\GuestCheckoutService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CheckoutController extends ApiController
{
    public function store(CheckoutStoreRequest $request, BillingService $billing): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $cart = CartSession::current()->load('items.service', 'items.product');
        if ($cart->items->isEmpty()) {
            return $this->error('Your basket is empty.', [], 422);
        }

        $data = $request->validated();

        $customerId = $this->ensureBillingCustomer($user, $billing);
        if (! $customerId) {
            return $this->error('Could not create billing customer.', [], 500);
        }

        $transaction = $this->createTransactionFromCart($cart, $data, $user);

        $invoiceId = $this->createInvoiceForTransaction($transaction, $customerId, $billing);
        if ($invoiceId) {
            $transaction->invoice_id = $invoiceId;
            $transaction->status = 'completed';
            $transaction->save();
        }

        $this->queueReceiptEmails($transaction);

        if ($invoiceId) {
            $this->grantAccessForTransaction($transaction, $cart, $data['serviceChildren'] ?? [], $invoiceId);
        }

        return $this->success([
            'transaction_id' => $transaction->id,
            'invoice_id' => $invoiceId,
            'status' => $transaction->status,
        ]);
    }

    public function guestStore(CheckoutStoreRequest $request, BillingService $billing): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $cart = CartSession::current()->load('items.service', 'items.product');
        if ($cart->items->isEmpty()) {
            return $this->error('Your basket is empty.', [], 422);
        }

        $data = $request->validated();

        $customerId = $this->ensureBillingCustomer($user, $billing);
        if (! $customerId) {
            return $this->error('Could not create billing customer.', [], 500);
        }

        $flexibleSelections = [];
        foreach ($cart->items as $ci) {
            if ($ci->service_id && $ci->metadata) {
                $service = Service::find($ci->service_id);
                if ($service && $service->isFlexibleService()) {
                    $flexibleSelections[$ci->service_id] = $ci->metadata;
                }
            }
        }

        $transaction = $this->createTransactionFromCart($cart, $data, $user, true);

        $serviceChildren = $data['serviceChildren'] ?? [];
        $meta = $transaction->meta ?? [];
        if (!empty($flexibleSelections)) {
            $meta['flexibleSelections'] = $flexibleSelections;
        }
        if (!empty($serviceChildren)) {
            $meta['serviceChildren'] = $serviceChildren;
        }
        $transaction->meta = $meta;
        $transaction->save();

        $invoiceId = $this->createInvoiceForTransaction($transaction, $customerId, $billing);
        if ($invoiceId) {
            $transaction->invoice_id = $invoiceId;
            $transaction->save();
        }

        return $this->success([
            'transaction_id' => $transaction->id,
            'invoice_id' => $invoiceId,
            'customer_id' => $customerId,
            'api_key' => config('services.billing.publishable_key'),
        ]);
    }

    public function sendGuestCode(GuestCodeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $email = strtolower($data['email']);

        $existingUser = \App\Models\User::where('email', $email)->first();
        if ($existingUser && in_array(($existingUser->role ?? ''), ['parent', 'admin', 'guest_parent'])) {
            return $this->error('An account with this email already exists. Please log in instead.', [], 409);
        }

        $lastKey = "guest_verification_last:{$email}";
        if (Cache::has($lastKey) && (int) Cache::get($lastKey) > time() - 60) {
            return $this->error('Please wait before requesting another code.', [], 429);
        }

        $code = random_int(100000, 999999);
        Cache::put("guest_verification:{$email}", (string) $code, now()->addMinutes(10));
        Cache::put($lastKey, time(), now()->addMinutes(10));

        try {
            Mail::raw("Your verification code is: {$code}", function ($message) use ($email) {
                $message->to($email)->subject('Your verification code');
            });
        } catch (\Throwable $e) {
            Log::warning('sendGuestCode: failed to send verification email', ['email' => $email, 'error' => $e->getMessage()]);
            return $this->error('Could not send verification email.', [], 500);
        }

        return $this->success(['message' => 'Verification code sent.']);
    }

    public function verifyGuestCode(GuestVerifyRequest $request, GuestCheckoutService $guestCheckout): JsonResponse
    {
        $payload = $request->validated();
        $email = strtolower($payload['email']);
        $codeKey = "guest_verification:{$email}";
        $cached = Cache::get($codeKey);

        if (! $cached || (string) $cached !== (string) $payload['code']) {
            return $this->error('Invalid or expired code.', [], 422);
        }

        $res = $guestCheckout->findOrCreateGuestUser([
            'email' => $email,
            'name' => $payload['guest_name'] ?? null,
            'organization_id' => $payload['organization_id'],
        ]);

        if ($res['status'] === 'invalid') {
            return $this->error('Invalid email.', [], 422);
        }
        if ($res['status'] === 'existing_parent') {
            return $this->error('An account with this email already exists. Please login.', [], 409);
        }

        $user = $res['user'];
        $createdChildren = [];

        foreach ($payload['children'] ?? [] as $index => $child) {
            try {
                $created = $guestCheckout->createChildForUser($user, [
                    'child_name' => $child['child_name'] ?? null,
                    'date_of_birth' => $child['date_of_birth'] ?? null,
                    'age' => isset($child['age']) ? (int) $child['age'] : 0,
                    'year_group' => $child['year_group'] ?? null,
                    'school_name' => $child['school_name'] ?? null,
                    'area' => $child['area'] ?? null,
                    'organization_id' => $payload['organization_id'],
                ]);
            } catch (\Throwable $e) {
                Log::error('verifyGuestCode: exception while creating child', [
                    'user_id' => $user->id ?? null,
                    'index' => $index,
                    'error' => $e->getMessage(),
                ]);
                $created = null;
            }

            if ($created) {
                $createdChildren[] = $created->id;
            }
        }

        Cache::forget($codeKey);
        Cache::forget("guest_verification_last:{$email}");

        $token = $user->createToken('guest-checkout')->plainTextToken;

        return $this->success([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'current_organization_id' => $user->current_organization_id,
            ],
            'children' => $createdChildren,
        ]);
    }

    private function ensureBillingCustomer($user, BillingService $billing): ?string
    {
        if ($user->billing_customer_id) {
            return $user->billing_customer_id;
        }

        $customerId = $billing->createCustomer($user);
        if ($customerId) {
            $user->billing_customer_id = $customerId;
            $user->save();
        }

        return $customerId;
    }

    private function createTransactionFromCart($cart, array $data, $user, bool $clearCart = true): Transaction
    {
        return DB::transaction(function () use ($cart, $data, $user, $clearCart) {
            $subtotal = $cart->items->sum(fn ($ci) => $ci->quantity * $ci->price);

            $tx = Transaction::create([
                'user_id' => $user->id,
                'user_email' => $user->email,
                'type' => $data['type'],
                'status' => 'pending',
                'payment_method' => 'manual',
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'comment' => $data['comment'] ?? null,
            ]);

            $rows = $cart->items->map(function ($ci) {
                $buyable = $ci->service ?? $ci->product;

                return [
                    'item_type' => $ci->service ? Service::class : Product::class,
                    'item_id' => $ci->service_id ?? $ci->product_id,
                    'description' => $buyable->display_name ?? ($buyable->service_name ?? $buyable->name),
                    'qty' => $ci->quantity,
                    'unit_price' => $ci->price,
                    'line_total' => $ci->quantity * $ci->price,
                ];
            })->all();

            $tx->items()->createMany($rows);

            if ($clearCart) {
                $cart->items()->delete();
            }

            return $tx;
        });
    }

    private function createInvoiceForTransaction(Transaction $transaction, string $customerId, BillingService $billing): ?string
    {
        $serviceStartTimes = $transaction->items
            ->filter(fn ($item) => $item->item_type === Service::class)
            ->map(fn ($item) => Service::find($item->item_id)->start_datetime)
            ->filter()
            ->map(fn ($dt) => Carbon::parse($dt));

        if ($serviceStartTimes->isNotEmpty()) {
            $earliest = $serviceStartTimes->min();
            $dueDate = $earliest->copy()->subDay()->toDateString();
        } else {
            $dueDate = now()->addDays(7)->toDateString();
        }

        $invoiceData = [
            'customer_id' => $customerId,
            'due_date' => $dueDate,
            'description' => 'Payment for services',
            'items' => $transaction->items->map(function ($item) {
                return [
                    'description' => $item->description,
                    'quantity' => $item->qty,
                    'unit_amount' => (int) ($item->unit_price * 100),
                    'currency' => 'usd',
                ];
            })->toArray(),
            'currency' => 'usd',
            'auto_bill' => true,
            'status' => 'open',
        ];

        return $billing->createInvoice($invoiceData);
    }

    private function queueReceiptEmails(Transaction $transaction): void
    {
        try {
            $email = $transaction->user_email ?? $transaction->user?->email;
            if (! $email) {
                return;
            }

            if (empty($transaction->email_sent_receipt)) {
                Mail::to($email)->queue(new ReceiptAccessMail($transaction, 'receipt'));
                DB::table('transactions')
                    ->where('id', $transaction->id)
                    ->where('email_sent_receipt', false)
                    ->update(['email_sent_receipt' => true, 'updated_at' => now()]);
            }

            if (empty($transaction->email_sent_access)) {
                Mail::to($email)->queue(new ReceiptAccessMail($transaction, 'access_granted'));
                DB::table('transactions')
                    ->where('id', $transaction->id)
                    ->where('email_sent_access', false)
                    ->update(['email_sent_access' => true, 'updated_at' => now()]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to queue receipt/access emails for transaction', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function grantAccessForTransaction(Transaction $transaction, $cart, array $serviceChildren, string $invoiceId): void
    {
        foreach ($transaction->items as $item) {
            if ($item->item_type !== Service::class) {
                continue;
            }

            $service = Service::find($item->item_id);
            if (! $service) {
                continue;
            }

            $childId = $serviceChildren[$item->item_id] ?? null;
            if (! $childId) {
                continue;
            }

            if ($service->isCourseService()) {
                $courseAccessService = app(CourseAccessService::class);
                $courseAccessService->grantCourseAccess(
                    childId: $childId,
                    courseId: $service->course_id,
                    transactionId: $transaction->id,
                    invoiceId: $invoiceId
                );
                continue;
            }

            if ($service->isFlexibleService()) {
                $cartItem = $cart->items->first(fn ($ci) => $ci->service_id == $service->id);

                if ($cartItem && $cartItem->metadata) {
                    $meta = $transaction->meta ?? [];
                    $meta['flexibleSelections'] = $meta['flexibleSelections'] ?? [];
                    $meta['flexibleSelections'][$service->id] = $cartItem->metadata;
                    $meta['serviceChildren'] = $meta['serviceChildren'] ?? [];
                    $meta['serviceChildren'][$service->id] = $childId;
                    $transaction->meta = $meta;
                    $transaction->save();

                    GrantAccessForTransactionJob::dispatch($transaction->id)
                        ->delay(now()->addSeconds(5));
                }
                continue;
            }

            $lessonIds = DB::table('lesson_service')->where('service_id', $service->id)->pluck('lesson_id')->toArray();
            $assessmentIds = DB::table('assessment_service')->where('service_id', $service->id)->pluck('assessment_id')->toArray();

            $liveSessionIds = [];
            if (! empty($lessonIds)) {
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

            if (! empty($liveSessionIds)) {
                $metadata['live_lesson_session_ids'] = $liveSessionIds;
            }

            DB::table('access')->insert([
                'child_id' => $childId,
                'lesson_ids' => json_encode($lessonIds),
                'assessment_ids' => json_encode($assessmentIds),
                'course_ids' => json_encode([]),
                'transaction_id' => $transaction->id,
                'invoice_id' => $invoiceId,
                'purchase_date' => now(),
                'due_date' => null,
                'access' => true,
                'payment_status' => 'paid',
                'refund_id' => null,
                'metadata' => json_encode($metadata),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
