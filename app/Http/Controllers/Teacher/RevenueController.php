<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Access;
use App\Models\Transaction;
use App\Models\Course;
use App\Models\ContentLesson;
use App\Models\Assessment;
use App\Models\Lesson;
use App\Models\Service;
use App\Models\TransactionItem;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class RevenueController extends Controller
{
    public function index()
    {
        $teacher = Auth::user();

        // Children assigned to this teacher
        $childIds = $teacher->assignedStudents()->pluck('children.id');

        // Access records tied to those children with eager loading
        $accesses = Access::with([
                'child',
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

        $transactions = Transaction::with('user')
            ->whereIn('id', $accesses->pluck('transaction_id')->unique())
            ->where('status', 'completed')
            ->latest()
            ->get();

        // Map transaction items (services/courses/products) by transaction_id
        $txItemsByTx = TransactionItem::with('item')
            ->whereIn('transaction_id', $transactions->pluck('id'))
            ->get()
            ->groupBy('transaction_id');

        $revenue = $transactions->sum('total');

        // Group purchases per child for display with item details
        $childPurchases = $accesses->groupBy('child_id')->map(function ($group) use ($txItemsByTx) {
            $child = $group->first()->child?->only(['id', 'child_name']);

            $items = $group->flatMap(function ($access) {
                $entries = [];
                $date = $access->purchase_date;
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

            // Add items from transaction_items (services/courses/products)
            $itemsFromTx = $txItemsByTx->get($group->first()->transaction_id) ?? collect();
            $items = $items->concat(
                $itemsFromTx->map(function ($ti) {
                    $typeLabel = match ($ti->item_type) {
                        Service::class => 'Service',
                        Course::class => 'Course',
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
                'items' => $items,
            ];
        })->values();

        return Inertia::render('@admin/Teacher/Revenue/Index', [
            'transactions' => $transactions->map(function ($tx) {
                return [
                    'id' => $tx->id,
                    'user_name' => $tx->user?->name,
                    'user_email' => $tx->user_email,
                    'total' => $tx->total,
                    'status' => $tx->status,
                    'created_at' => $tx->created_at,
                ];
            }),
            'revenue' => $revenue,
            'childPurchases' => $childPurchases,
        ]);
    }

    /**
     * Get detailed information about purchased content
     */
    private function getPurchasedContentDetails($access)
    {
        $content = [
            'courses' => [],
            'lessons' => [],
            'content_lessons' => [],
            'assessments' => [],
        ];

        // Get course details
        if (!empty($access->course_ids)) {
            $content['courses'] = Course::whereIn('id', $access->course_ids)
                ->select('id', 'title', 'uid', 'description', 'thumbnail')
                ->get()
                ->map(function ($course) {
                    return [
                        'id' => $course->id,
                        'title' => $course->title,
                        'uid' => $course->uid,
                        'description' => $course->description,
                        'thumbnail' => $course->thumbnail,
                    ];
                });
        }

        // Get lesson details
        if (!empty($access->lesson_ids)) {
            $content['lessons'] = Lesson::whereIn('id', $access->lesson_ids)
                ->select('id', 'title', 'description')
                ->get()
                ->map(function ($lesson) {
                    return [
                        'id' => $lesson->id,
                        'title' => $lesson->title,
                        'description' => $lesson->description,
                    ];
                });
        }

        // Get content lesson details
        if (!empty($access->content_lesson_id)) {
            $contentLesson = ContentLesson::find($access->content_lesson_id);
            if ($contentLesson) {
                $content['content_lessons'] = [[
                    'id' => $contentLesson->id,
                    'title' => $contentLesson->title,
                    'description' => $contentLesson->description,
                ]];
            }
        }

        // Get assessment details
        if (!empty($access->assessment_ids)) {
            $content['assessments'] = Assessment::whereIn('id', $access->assessment_ids)
                ->select('id', 'title', 'type', 'description')
                ->get()
                ->map(function ($assessment) {
                    return [
                        'id' => $assessment->id,
                        'title' => $assessment->title,
                        'type' => $assessment->type,
                        'description' => $assessment->description,
                    ];
                });
        }

        // Get single assessment if exists
        if (!empty($access->assessment_id)) {
            $assessment = Assessment::find($access->assessment_id);
            if ($assessment) {
                $content['assessments'][] = [
                    'id' => $assessment->id,
                    'title' => $assessment->title,
                    'type' => $assessment->type,
                    'description' => $assessment->description,
                ];
            }
        }

        return $content;
    }
}
