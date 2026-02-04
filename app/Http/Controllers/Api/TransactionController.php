<?php

namespace App\Http\Controllers\Api;

use App\Models\Service;
use App\Models\Transaction;
use App\Services\BillingService;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends ApiController
{
    protected BillingService $billing;

    public function __construct(BillingService $billing)
    {
        $this->billing = $billing;
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id');

        $query = Transaction::query()->withCount('items');

        if ($user->isSuperAdmin()) {
            if ($orgId) {
                $query->whereHas('user', fn ($q) => $q->where('current_organization_id', $orgId));
            }
        } elseif ($user->isAdmin()) {
            if ($orgId) {
                $query->whereHas('user', fn ($q) => $q->where('current_organization_id', $orgId));
            } else {
                $query->whereHas('user', fn ($q) => $q->where('current_organization_id', $user->current_organization_id));
            }
        } else {
            $query->where('user_id', $user->id);
        }

        ApiQuery::applyFilters($query, $request, [
            'status' => true,
            'type' => true,
        ]);

        ApiQuery::applySort($query, $request, ['created_at', 'total', 'status'], '-created_at');

        $transactions = $query->paginate(ApiPagination::perPage($request, 20));

        $data = $transactions->getCollection()->map(function (Transaction $tx) {
            return [
                'id' => $tx->id,
                'user_id' => $tx->user_id,
                'user_email' => $tx->user_email,
                'type' => $tx->type,
                'status' => $tx->status,
                'payment_method' => $tx->payment_method,
                'subtotal' => $tx->subtotal,
                'total' => $tx->total,
                'invoice_id' => $tx->invoice_id,
                'items_count' => $tx->items_count,
                'created_at' => $tx->created_at?->toISOString(),
                'paid_at' => $tx->paid_at?->toISOString(),
            ];
        })->all();

        return $this->paginated($transactions, $data);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        if (! $user->isAdmin() && ! $user->isSuperAdmin()) {
            return $this->error('Forbidden.', [], 403);
        }

        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'amount' => 'required|numeric',
            'currency' => 'nullable|string',
            'payment_method' => 'required|in:credit_card,paypal,bank_transfer',
            'status' => 'required|in:pending,completed,failed,refunded',
        ]);

        $transaction = Transaction::create($validated);

        return $this->success([
            'transaction' => [
                'id' => $transaction->id,
                'user_id' => $transaction->user_id,
                'user_email' => $transaction->user_email,
                'type' => $transaction->type,
                'status' => $transaction->status,
                'payment_method' => $transaction->payment_method,
                'subtotal' => $transaction->subtotal,
                'total' => $transaction->total,
                'invoice_id' => $transaction->invoice_id,
                'created_at' => $transaction->created_at?->toISOString(),
            ],
            'message' => 'Transaction created successfully.',
        ], [], 201);
    }

    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        $authError = $this->authorizeTransaction($request, $transaction);
        if ($authError) {
            return $authError;
        }

        $transaction->loadMissing(['items.item', 'invoice', 'logs', 'refunds', 'user']);

        $dueDate = $this->calculateDueDate($transaction);

        $data = [
            'id' => $transaction->id,
            'user_id' => $transaction->user_id,
            'user_email' => $transaction->user_email,
            'type' => $transaction->type,
            'status' => $transaction->status,
            'payment_method' => $transaction->payment_method,
            'subtotal' => $transaction->subtotal,
            'discount' => $transaction->discount,
            'tax' => $transaction->tax,
            'total' => $transaction->total,
            'invoice_id' => $transaction->invoice_id,
            'comment' => $transaction->comment,
            'created_at' => $transaction->created_at?->toISOString(),
            'paid_at' => $transaction->paid_at?->toISOString(),
            'items' => $transaction->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'description' => $item->description,
                    'qty' => $item->qty,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->line_total,
                ];
            })->all(),
        ];

        return $this->success([
            'transaction' => $data,
            'due_date' => $dueDate,
        ]);
    }

    public function autopay(Request $request, Transaction $transaction): JsonResponse
    {
        $authError = $this->authorizeTransaction($request, $transaction);
        if ($authError) {
            return $authError;
        }

        if (! $transaction->invoice_id) {
            return $this->error('No invoice found for this transaction.', [], 422);
        }

        $result = $this->billing->enableAutopay($transaction->invoice_id);

        if ($result['success']) {
            return $this->success([
                'success' => true,
                'status' => $result['status'],
                'body' => $result['body'],
                'json' => $result['json'],
            ]);
        }

        return $this->error(
            $result['json']['message'] ?? 'Autopay failed.',
            [[
                'message' => $result['json']['message'] ?? 'Autopay failed.',
                'details' => $result['json'] ?? $result['body'],
            ]],
            400
        );
    }

    protected function authorizeTransaction(Request $request, Transaction $transaction): ?JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $transaction->loadMissing('user');
        $orgId = $request->attributes->get('organization_id');
        $transactionOrgId = $transaction->user?->current_organization_id;

        if ($user->isSuperAdmin()) {
            if ($orgId && $transactionOrgId !== $orgId) {
                return $this->error('Transaction not found.', [], 404);
            }
            return null;
        }

        if ($user->isAdmin()) {
            $expectedOrg = $orgId ?: $user->current_organization_id;
            if ($expectedOrg && $transactionOrgId !== $expectedOrg) {
                return $this->error('Transaction not found.', [], 404);
            }
            return null;
        }

        if ($transaction->user_id !== $user->id) {
            return $this->error('Forbidden.', [], 403);
        }

        return null;
    }

    protected function calculateDueDate(Transaction $transaction): ?string
    {
        if (! $transaction->relationLoaded('items')) {
            $transaction->loadMissing('items.item');
        }

        $serviceStartTimes = $transaction->items
            ->filter(fn ($item) => $item->item_type === Service::class)
            ->map(fn ($item) => $item->item?->start_datetime)
            ->filter()
            ->map(fn ($dt) => Carbon::parse($dt));

        if ($serviceStartTimes->isEmpty()) {
            return null;
        }

        return $serviceStartTimes->min()->copy()->subDay()->format('Y-m-d');
    }
}
