<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Api\ApiController;
use App\Models\Access;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RevenueController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $teacher = $request->user();
        if (!$teacher) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id') ?: $teacher->current_organization_id;

        $childIds = $teacher->assignedStudents()
            ->when($orgId, fn ($q) => $q->wherePivot('organization_id', $orgId))
            ->pluck('children.id');

        if ($childIds->isEmpty()) {
            return $this->success([
                'transactions' => [],
                'revenue' => 0,
                'child_purchases' => [],
            ]);
        }

        $accesses = Access::with([
                'child:id,child_name',
                'transaction',
                'service',
                'course',
                'contentLesson',
                'lesson',
                'assessment',
            ])
            ->whereIn('child_id', $childIds)
            ->whereNotNull('transaction_id')
            ->get();

        $transactionIds = $accesses->pluck('transaction_id')->unique()->filter()->values();

        $transactions = Transaction::with('user:id,name,email')
            ->whereIn('id', $transactionIds)
            ->where('status', 'completed')
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->latest()
            ->get();

        $txIds = $transactions->pluck('id')->all();

        $txItemsByTx = TransactionItem::with('item')
            ->whereIn('transaction_id', $txIds)
            ->get()
            ->groupBy('transaction_id');

        $revenue = $transactions->sum('total');

        $childPurchases = $accesses
            ->whereIn('transaction_id', $txIds)
            ->groupBy('child_id')
            ->map(function ($group) use ($txItemsByTx) {
                $child = $group->first()->child?->only(['id', 'child_name']);

                $items = $group->flatMap(function ($access) {
                    $entries = [];
                    $date = $access->purchase_date?->toISOString();
                    $txId = $access->transaction_id;

                    if ($access->service) {
                        $entries[] = [
                            'type' => 'Service',
                            'name' => $access->service->service_name ?? $access->service->name ?? 'Service',
                            'transaction_id' => $txId,
                            'purchase_date' => $date,
                        ];
                    }

                    if ($access->course) {
                        $entries[] = [
                            'type' => 'Course',
                            'name' => $access->course->title ?? 'Course',
                            'transaction_id' => $txId,
                            'purchase_date' => $date,
                        ];
                    }

                    if ($access->contentLesson) {
                        $entries[] = [
                            'type' => 'Lesson',
                            'name' => $access->contentLesson->title ?? 'Lesson',
                            'transaction_id' => $txId,
                            'purchase_date' => $date,
                        ];
                    }

                    if ($access->lesson) {
                        $entries[] = [
                            'type' => 'Live Session',
                            'name' => $access->lesson->title ?? 'Live Session',
                            'transaction_id' => $txId,
                            'purchase_date' => $date,
                        ];
                    }

                    if ($access->assessment) {
                        $entries[] = [
                            'type' => 'Assessment',
                            'name' => $access->assessment->title ?? 'Assessment',
                            'transaction_id' => $txId,
                            'purchase_date' => $date,
                        ];
                    }

                    return $entries;
                })->values();

                $itemsFromTx = $txItemsByTx->get($group->first()->transaction_id) ?? collect();
                $items = $items->concat(
                    $itemsFromTx->map(function ($ti) {
                        $typeLabel = match ($ti->item_type) {
                            Service::class => 'Service',
                            default => 'Product',
                        };

                        return [
                            'type' => $typeLabel,
                            'name' => $ti->item?->service_name ?? $ti->item?->title ?? $ti->description ?? $typeLabel,
                            'transaction_id' => $ti->transaction_id,
                            'purchase_date' => null,
                        ];
                    })
                );

                return [
                    'child' => $child,
                    'items' => $items->values(),
                ];
            })
            ->values();

        return $this->success([
            'transactions' => $transactions->map(function ($tx) {
                return [
                    'id' => $tx->id,
                    'user_name' => $tx->user?->name,
                    'user_email' => $tx->user_email ?? $tx->user?->email,
                    'total' => $tx->total,
                    'status' => $tx->status,
                    'created_at' => $tx->created_at?->toISOString(),
                ];
            }),
            'revenue' => $revenue,
            'child_purchases' => $childPurchases,
        ]);
    }
}
