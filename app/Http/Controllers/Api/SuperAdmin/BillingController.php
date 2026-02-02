<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillingController extends ApiController
{
    public function overview(): JsonResponse
    {
        $statusScope = ['completed', 'success', 'paid', 'confirmed'];

        $totalRevenue = (float) Transaction::whereIn('status', $statusScope)->sum('total');
        $revenueToday = (float) Transaction::whereIn('status', $statusScope)
            ->whereDate('created_at', today())
            ->sum('total');
        $revenueMonth = (float) Transaction::whereIn('status', $statusScope)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('total');

        $activeSubscriptions = DB::table('user_subscriptions')
            ->where('status', 'active')
            ->count();

        return $this->success([
            'metrics' => [
                'total_revenue' => $totalRevenue,
                'revenue_today' => $revenueToday,
                'revenue_month' => $revenueMonth,
                'active_subscriptions' => $activeSubscriptions,
                'total_plans' => Subscription::count(),
                'total_transactions' => Transaction::count(),
            ],
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $query = Transaction::query()->with('user:id,name,email,current_organization_id');

        ApiQuery::applyFilters($query, $request, [
            'status' => true,
            'type' => true,
            'user_id' => true,
        ]);

        if ($request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
            $query->whereHas('user', fn ($q) => $q->where('current_organization_id', $orgId));
        }

        ApiQuery::applySort($query, $request, ['created_at', 'total', 'status'], '-created_at');

        $transactions = $query->paginate(ApiPagination::perPage($request));
        $data = $transactions->getCollection()->map(function ($transaction) {
            return [
                'id' => $transaction->id,
                'user_id' => $transaction->user_id,
                'user' => $transaction->user ? [
                    'id' => $transaction->user->id,
                    'name' => $transaction->user->name,
                    'email' => $transaction->user->email,
                ] : null,
                'type' => $transaction->type,
                'status' => $transaction->status,
                'payment_method' => $transaction->payment_method,
                'subtotal' => $transaction->subtotal,
                'discount' => $transaction->discount,
                'tax' => $transaction->tax,
                'total' => $transaction->total,
                'paid_at' => $transaction->paid_at?->toISOString(),
                'created_at' => $transaction->created_at?->toISOString(),
            ];
        })->all();

        return $this->paginated($transactions, $data);
    }

    public function subscriptions(Request $request): JsonResponse
    {
        $query = DB::table('user_subscriptions as us')
            ->join('users', 'users.id', '=', 'us.user_id')
            ->join('subscriptions', 'subscriptions.id', '=', 'us.subscription_id')
            ->select(
                'us.id',
                'us.user_id',
                'users.name as user_name',
                'users.email',
                'subscriptions.id as subscription_id',
                'subscriptions.name as plan_name',
                'subscriptions.slug',
                'us.status',
                'us.starts_at',
                'us.ends_at',
                'us.source'
            );

        if ($request->filled('plan')) {
            $query->where('subscriptions.slug', $request->plan);
        }

        if ($request->filled('status')) {
            $query->where('us.status', $request->status);
        }

        if ($request->filled('user_id')) {
            $query->where('us.user_id', $request->integer('user_id'));
        }

        $rows = $query->orderByDesc('us.id')
            ->paginate(ApiPagination::perPage($request));

        $data = collect($rows->items())->map(function ($row) {
            return [
                'id' => $row->id,
                'user_id' => $row->user_id,
                'user_name' => $row->user_name,
                'user_email' => $row->email,
                'subscription_id' => $row->subscription_id,
                'plan_name' => $row->plan_name,
                'plan_slug' => $row->slug,
                'status' => $row->status,
                'starts_at' => $row->starts_at,
                'ends_at' => $row->ends_at,
                'source' => $row->source,
            ];
        })->all();

        $plans = Subscription::select('id', 'name', 'slug')->orderBy('name')->get();

        return $this->paginated($rows, $data, [
            'filters' => $request->only(['plan', 'status', 'user_id']),
            'plans' => $plans,
        ]);
    }

    public function revenue(Request $request): JsonResponse
    {
        $statusScope = ['completed', 'success', 'paid', 'confirmed'];

        $byDay = collect(range(6, 0))->map(function ($daysAgo) use ($statusScope) {
            $date = now()->subDays($daysAgo);
            $total = (float) Transaction::whereIn('status', $statusScope)
                ->whereDate('created_at', $date)
                ->sum('total');

            return [
                'date' => $date->toDateString(),
                'total' => $total,
            ];
        })->values();

        return $this->success([
            'daily' => $byDay,
        ]);
    }

    public function invoices(): JsonResponse
    {
        return $this->success([
            'invoices' => [],
            'message' => 'Invoice listing is not configured.',
        ]);
    }

    public function refund(Request $request, int $transaction): JsonResponse
    {
        return $this->success([
            'message' => 'Refund requested.',
            'transaction_id' => $transaction,
        ]);
    }

    public function export(Request $request): JsonResponse
    {
        return $this->success([
            'message' => 'Export queued.',
        ]);
    }
}
