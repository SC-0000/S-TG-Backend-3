<?php

namespace App\Services;

use App\Models\Service;
use App\Models\Access;
use Illuminate\Support\Facades\DB;

class FlexibleServiceAccessService
{
    /**
     * Grant access based on user selections for flexible service
     */
    public function grantFlexibleAccess(
        int $childId,
        Service $service,
        array $selections,
        ?int $transactionId = null,
        ?string $invoiceId = null
    ): array {
        $granted = [
            'live_sessions' => [],
            'assessments' => [],
        ];
        
        DB::transaction(function() use ($childId, $service, $selections, $transactionId, $invoiceId, &$granted) {
            // Grant access to selected live sessions
            foreach ($selections['live_sessions'] ?? [] as $sessionId) {
                // Create access record
                Access::create([
                    'child_id' => $childId,
                    'lesson_id' => $sessionId,
                    'transaction_id' => $transactionId,
                    'invoice_id' => $invoiceId,
                    'purchase_date' => now(),
                    'payment_status' => 'paid',
                    'access' => true,
                    'metadata' => [
                        'service_id' => $service->id,
                        'selection_type' => 'flexible',
                    ],
                ]);
                
                // Increment enrollment count in pivot
                DB::table('lesson_service')
                    ->where('service_id', $service->id)
                    ->where('lesson_id', $sessionId)
                    ->increment('current_enrollments');
                
                $granted['live_sessions'][] = $sessionId;
            }
            
            // Grant access to selected assessments
            foreach ($selections['assessments'] ?? [] as $assessmentId) {
                // Create access record
                Access::create([
                    'child_id' => $childId,
                    'assessment_id' => $assessmentId,
                    'transaction_id' => $transactionId,
                    'invoice_id' => $invoiceId,
                    'purchase_date' => now(),
                    'payment_status' => 'paid',
                    'access' => true,
                    'metadata' => [
                        'service_id' => $service->id,
                        'selection_type' => 'flexible',
                    ],
                ]);
                
                // Increment enrollment count in pivot
                DB::table('assessment_service')
                    ->where('service_id', $service->id)
                    ->where('assessment_id', $assessmentId)
                    ->increment('current_enrollments');
                
                $granted['assessments'][] = $assessmentId;
            }
        });
        
        return $granted;
    }
    
    /**
     * Revoke flexible service access (for refunds)
     */
    public function revokeFlexibleAccess(
        int $childId,
        Service $service,
        array $selections
    ): void {
        DB::transaction(function() use ($childId, $service, $selections) {
            // Revoke live session access
            foreach ($selections['live_sessions'] ?? [] as $sessionId) {
                Access::where('child_id', $childId)
                    ->where('lesson_id', $sessionId)
                    ->delete();
                
                // Decrement enrollment count
                DB::table('lesson_service')
                    ->where('service_id', $service->id)
                    ->where('lesson_id', $sessionId)
                    ->decrement('current_enrollments');
            }
            
            // Revoke assessment access
            foreach ($selections['assessments'] ?? [] as $assessmentId) {
                Access::where('child_id', $childId)
                    ->where('assessment_id', $assessmentId)
                    ->delete();
                
                // Decrement enrollment count
                DB::table('assessment_service')
                    ->where('service_id', $service->id)
                    ->where('assessment_id', $assessmentId)
                    ->decrement('current_enrollments');
            }
        });
    }
    
    /**
     * Validate user selections for flexible service
     * Returns ['valid' => bool, 'message' => string]
     */
    public function validateSelections(
        Service $service,
        array $selectedLessons,
        array $selectedAssessments
    ): array {
        // Get required counts from service configuration
        $required = $service->selection_config ?? [];
        $requiredLessons = $required['live_sessions'] ?? 0;
        $requiredAssessments = $required['assessments'] ?? 0;
        
        // Validate lesson count
        if (count($selectedLessons) !== $requiredLessons) {
            return [
                'valid' => false,
                'message' => "Please select exactly {$requiredLessons} lesson(s). You selected " . count($selectedLessons) . "."
            ];
        }
        
        // Validate assessment count
        if (count($selectedAssessments) !== $requiredAssessments) {
            return [
                'valid' => false,
                'message' => "Please select exactly {$requiredAssessments} assessment(s). You selected " . count($selectedAssessments) . "."
            ];
        }
        
        // Check if all selected items are still available
        $available = $this->validateSelectionsAvailability($service, [
            'live_sessions' => $selectedLessons,
            'assessments' => $selectedAssessments
        ]);
        
        if (!$available) {
            return [
                'valid' => false,
                'message' => 'One or more selected items are no longer available. Please refresh and try again.'
            ];
        }
        
        return [
            'valid' => true,
            'message' => ''
        ];
    }
    
    /**
     * Check if selections are still available (prevent race conditions)
     */
    public function validateSelectionsAvailability(
        Service $service,
        array $selections
    ): bool {
        // Re-fetch fresh data
        $availableSessions = $service->fresh()->getAvailableLiveSessions()
            ->filter(fn($s) => $s->is_available)
            ->pluck('id')
            ->toArray();
            
        $availableAssessments = $service->fresh()->getAvailableAssessments()
            ->filter(fn($a) => $a->is_available)
            ->pluck('id')
            ->toArray();
        
        // Check all selected sessions are still available
        foreach ($selections['live_sessions'] ?? [] as $sessionId) {
            if (!in_array($sessionId, $availableSessions)) {
                return false;
            }
        }
        
        // Check all selected assessments are still available
        foreach ($selections['assessments'] ?? [] as $assessmentId) {
            if (!in_array($assessmentId, $availableAssessments)) {
                return false;
            }
        }
        
        return true;
    }
}
