<?php

namespace App\Jobs;

use App\Models\Service;
use App\Models\Transaction;
use App\Models\CartItem;
use App\Services\CourseAccessService;
use App\Services\FlexibleServiceAccessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GrantAccessForTransactionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $transactionId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $transactionId)
    {
        $this->transactionId = $transactionId;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        Log::info('🚀 GrantAccessForTransactionJob: Starting', [
            'transaction_id' => $this->transactionId,
        ]);
        
        $tx = Transaction::with('items')->find($this->transactionId);

        if (! $tx) {
            Log::error('❌ GrantAccessForTransactionJob: Transaction not found', [
                'transaction_id' => $this->transactionId,
            ]);
            return;
        }

        Log::info('✅ GrantAccessForTransactionJob: Transaction loaded', [
            'transaction_id' => $tx->id,
            'status' => $tx->status,
            'user_id' => $tx->user_id,
            'item_count' => $tx->items->count(),
        ]);

        // Only proceed when transaction is paid (or completed)
        if (! in_array($tx->status, ['paid', 'completed'], true)) {
            Log::warning('⚠️ GrantAccessForTransactionJob: Transaction not paid yet, skipping', [
                'transaction_id' => $tx->id,
                'status' => $tx->status,
                'expected_statuses' => ['paid', 'completed'],
            ]);
            return;
        }

        // Load mapping from transaction.meta if present (service_id -> child_id)
        $meta = $tx->meta ?? [];
        $serviceChildren = $meta['serviceChildren'] ?? [];
        
        Log::info('🔍 GrantAccessForTransactionJob: Loaded serviceChildren mapping', [
            'transaction_id' => $tx->id,
            'serviceChildren' => $serviceChildren,
            'has_mapping' => !empty($serviceChildren),
        ]);

        // Iterate service items and grant access to mapped child
        foreach ($tx->items as $item) {
            // Only services need access
            if ($item->item_type !== \App\Models\Service::class) {
                Log::debug('GrantAccessForTransactionJob: Skipping non-service item', [
                    'transaction_id' => $tx->id,
                    'item_type' => $item->item_type,
                ]);
                continue;
            }

            $serviceId = $item->item_id;
            $childId = $serviceChildren[$serviceId] ?? null;

            Log::info('🔍 GrantAccessForTransactionJob: Processing service item', [
                'transaction_id' => $tx->id,
                'service_id' => $serviceId,
                'child_id' => $childId,
                'has_child_mapping' => $childId !== null,
            ]);

            if (! $childId) {
                Log::warning('⚠️ GrantAccessForTransactionJob: No child mapping for service', [
                    'transaction_id' => $tx->id,
                    'service_id' => $serviceId,
                    'available_mappings' => $serviceChildren,
                ]);
                continue;
            }

            // Idempotent: check if an access row already exists for this transaction + child + service
            $existing = DB::table('access')
                ->where('transaction_id', $tx->id)
                ->where('child_id', $childId)
                ->where('invoice_id', $tx->invoice_id)
                ->exists();

            if ($existing) {
                Log::info('GrantAccessForTransactionJob: access already exists, skipping', [
                    'transaction_id' => $tx->id,
                    'child_id' => $childId,
                    'service_id' => $serviceId,
                ]);
                continue;
            }

            // Load the service to check if it's a course service
            $service = Service::find($serviceId);
            
            if (!$service) {
                Log::error('❌ GrantAccessForTransactionJob: Service not found', [
                    'service_id' => $serviceId,
                    'transaction_id' => $tx->id,
                ]);
                continue;
            }

            Log::info('✅ GrantAccessForTransactionJob: Service loaded', [
                'transaction_id' => $tx->id,
                'service_id' => $serviceId,
                'service_name' => $service->service_name,
                'is_course_service' => $service->isCourseService(),
                'is_flexible_service' => $service->isFlexibleService(),
                'course_id' => $service->course_id ?? null,
            ]);

            // Check if this is a course service
            if ($service->isCourseService()) {
                Log::info('📚 GrantAccessForTransactionJob: Detected COURSE service, granting course access', [
                    'transaction_id' => $tx->id,
                    'service_id' => $serviceId,
                    'course_id' => $service->course_id,
                    'child_id' => $childId,
                ]);
                
                // Grant access to entire course content (lessons, live sessions, assessments)
                $courseAccessService = app(CourseAccessService::class);
                $result = $courseAccessService->grantCourseAccess(
                    childId: $childId,
                    courseId: $service->course_id,
                    transactionId: $tx->id,
                    invoiceId: $tx->invoice_id
                );

                Log::info('✅ GrantAccessForTransactionJob: Course access granted successfully', [
                    'transaction_id' => $tx->id,
                    'child_id' => $childId,
                    'service_id' => $serviceId,
                    'course_id' => $service->course_id,
                    'invoice_id' => $tx->invoice_id,
                    'access_stats' => $result,
                ]);
            } elseif ($service->isFlexibleService()) {
                // Handle flexible services with user selections
                Log::info('GrantAccessForTransactionJob: Processing flexible service', [
                    'transaction_id' => $tx->id,
                    'service_id' => $serviceId,
                    'service_name' => $service->service_name,
                ]);
                
                // Get selections from transaction meta (cart items are already deleted by this point)
                $metadata = $meta['flexibleSelections'][$serviceId] ?? null;
                
                if (!$metadata) {
                    Log::error('GrantAccessForTransactionJob: no flexible selections found in transaction meta', [
                        'transaction_id' => $tx->id,
                        'service_id' => $serviceId,
                        'meta' => $meta,
                    ]);
                    continue;
                }
                
                Log::info('GrantAccessForTransactionJob: Found flexible selections in transaction meta', [
                    'service_id' => $serviceId,
                    'raw_metadata' => $metadata,
                ]);
                
                // Parse selections (handle both formats: 'selected_lessons' and 'selected_live_sessions')
                $lessons = $metadata['selected_lessons'] 
                    ?? $metadata['selected_live_sessions'] 
                    ?? [];
                    
                $assessments = $metadata['selected_assessments'] ?? [];
                
                $selections = [
                    'live_sessions' => $lessons,
                    'assessments' => $assessments,
                ];
                
                Log::info('GrantAccessForTransactionJob: Parsed selections', [
                    'selections' => $selections,
                    'lessons_count' => count($selections['live_sessions'] ?? []),
                    'assessments_count' => count($selections['assessments'] ?? []),
                ]);
                
                // Final validation before granting access
                $flexibleService = app(FlexibleServiceAccessService::class);
                
                if (!$flexibleService->validateSelectionsAvailability($service, $selections)) {
                    Log::error('GrantAccessForTransactionJob: selections no longer available during checkout', [
                        'service_id' => $serviceId,
                        'selections' => $selections,
                    ]);
                    throw new \Exception("Some selected content is no longer available");
                }
                
                Log::info('GrantAccessForTransactionJob: Selections validated, granting access...', [
                    'child_id' => $childId,
                    'selections' => $selections,
                ]);
                
                // Grant access
                $granted = $flexibleService->grantFlexibleAccess(
                    $childId,
                    $service,
                    $selections,
                    $tx->id,
                    $tx->invoice_id
                );
                
                Log::info('GrantAccessForTransactionJob: ✅ Flexible service access granted successfully', [
                    'service_id' => $serviceId,
                    'child_id' => $childId,
                    'granted' => $granted,
                    'granted_lessons_count' => count($granted['live_sessions'] ?? []),
                    'granted_assessments_count' => count($granted['assessments'] ?? []),
                ]);
            } else {
                // Handle regular/fixed services (lessons/assessments)
                $lessonIds = DB::table('lesson_service')->where('service_id', $serviceId)->pluck('lesson_id')->toArray();
                $assessmentIds = DB::table('assessment_service')->where('service_id', $serviceId)->pluck('assessment_id')->toArray();

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
                    'service_id' => $serviceId,
                    'service_name' => $service->service_name ?? null,
                    'qty' => $item->qty,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->line_total,
                ];

                // Add LiveLessonSession IDs to metadata if found
                if (!empty($liveSessionIds)) {
                    $metadata['live_lesson_session_ids'] = $liveSessionIds;
                    Log::info('GrantAccessForTransactionJob: found linked LiveLessonSession IDs', [
                        'transaction_id' => $tx->id,
                        'service_id' => $serviceId,
                        'live_lesson_session_ids' => $liveSessionIds,
                    ]);
                }

                $accessData = [
                    'child_id'        => $childId,
                    'lesson_id'       => !empty($lessonIds) ? $lessonIds[0] : null,
                    'assessment_id'   => !empty($assessmentIds) ? $assessmentIds[0] : null,
                    'lesson_ids'      => json_encode($lessonIds),
                    'assessment_ids'  => json_encode($assessmentIds),
                    'transaction_id'  => $tx->id,
                    'invoice_id'      => $tx->invoice_id,
                    'purchase_date'   => now(),
                    'due_date'        => null,
                    'access'          => true,
                    'payment_status'  => 'paid',
                    'refund_id'       => null,
                    'metadata'        => json_encode($metadata),
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ];

                DB::table('access')->insert($accessData);

                Log::info('GrantAccessForTransactionJob: inserted access record', [
                    'transaction_id' => $tx->id,
                    'child_id' => $childId,
                    'service_id' => $serviceId,
                    'has_live_lesson_session_ids' => !empty($liveSessionIds),
                ]);
            }
        }

        Log::info('✅ GrantAccessForTransactionJob: Finished processing all items', [
            'transaction_id' => $tx->id,
        ]);
        
        // Optionally mark transaction completed if not already
        if ($tx->status !== 'completed') {
            $tx->status = 'completed';
            $tx->save();
            Log::info('✅ GrantAccessForTransactionJob: Marked transaction as completed', [
                'transaction_id' => $tx->id,
            ]);
        }
    }
}
