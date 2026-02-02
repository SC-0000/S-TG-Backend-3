# Flexible Service System Implementation Plan

## Overview

This document outlines the implementation of a flexible service system that allows users to select specific content (live sessions and assessments) when purchasing a service, with per-service enrollment limits.

### Key Features

- **User Selection**: Users choose N items from M available items during purchase
- **Service-Specific Enrollment Limits**: Same content can have different limits in different services
- **Pivot Table Limits**: Enrollment limits stored in pivot tables (not base tables)
- **Backward Compatible**: Existing course and fixed services continue to work

## System Architecture

### Service Types

The system will support **3 service types**:

1. **Course Service** (`course_id` is set)
   - Grants access to entire course with all modules, lessons, and assessments
   - No user selection needed
   - Existing behavior maintained

2. **Fixed Service** (`selection_config` is null or `type='fixed'`)
   - Admin pre-selects specific lessons/assessments
   - User gets exactly what's configured
   - Existing behavior maintained

3. **Flexible Service** (`selection_config.type='flexible'`) **[NEW]**
   - User selects N items from M available items
   - Custom access per purchase
   - Enrollment limits per service-content combination

## Database Changes

### Migration 1: Add Enrollment Tracking to `lesson_service` Pivot

**File**: `database/migrations/YYYY_MM_DD_HHMMSS_add_enrollment_tracking_to_lesson_service.php`

```sql
ALTER TABLE lesson_service 
ADD COLUMN enrollment_limit INT UNSIGNED NULL COMMENT 'Max students for this session in this service',
ADD COLUMN current_enrollments INT UNSIGNED DEFAULT 0 COMMENT 'Current number of enrolled students';
```

**Purpose**: Track enrollment limits and current counts for each live session within a specific service.

### Migration 2: Add Enrollment Tracking to `assessment_service` Pivot

**File**: `database/migrations/YYYY_MM_DD_HHMMSS_add_enrollment_tracking_to_assessment_service.php`

```sql
ALTER TABLE assessment_service 
ADD COLUMN enrollment_limit INT UNSIGNED NULL COMMENT 'Max students for this assessment in this service',
ADD COLUMN current_enrollments INT UNSIGNED DEFAULT 0 COMMENT 'Current number of enrolled students';
```

**Purpose**: Track enrollment limits and current counts for each assessment within a specific service.

### Migration 3: Add Selection Config to `services` Table

**File**: `database/migrations/YYYY_MM_DD_HHMMSS_add_selection_config_to_services.php`

```sql
ALTER TABLE services 
ADD COLUMN selection_config JSON NULL COMMENT 'Configuration for flexible service selections';
```

**Purpose**: Store flexible service configuration including selection requirements.

### Selection Config JSON Structure

```json
{
  "type": "flexible",
  "live_sessions": {
    "selection_required": 5,
    "selection_optional": false
  },
  "assessments": {
    "selection_required": 3,
    "selection_optional": false
  }
}
```

**Fields**:
- `type`: "flexible" or "fixed"
- `live_sessions.selection_required`: Number of live sessions user must select
- `live_sessions.selection_optional`: If true, user can select 0 to N sessions
- `assessments.selection_required`: Number of assessments user must select
- `assessments.selection_optional`: If true, user can select 0 to N assessments

## Model Updates

### Service Model (`app/Models/Service.php`)

#### Add to Fillable
```php
protected $fillable = [
    // ... existing fields
    'selection_config',
];
```

#### Add Cast
```php
protected $casts = [
    // ... existing casts
    'selection_config' => 'array',
];
```

#### New Helper Methods

```php
/**
 * Check if this service uses flexible selection
 */
public function isFlexibleService(): bool
{
    return ($this->selection_config['type'] ?? 'fixed') === 'flexible';
}

/**
 * Get available live sessions with enrollment status
 */
public function getAvailableLiveSessions()
{
    return $this->lessons()
        ->withPivot('enrollment_limit', 'current_enrollments')
        ->get()
        ->map(function($session) {
            $session->is_available = $session->pivot->enrollment_limit === null 
                || $session->pivot->current_enrollments < $session->pivot->enrollment_limit;
            $session->spots_remaining = $session->pivot->enrollment_limit 
                ? ($session->pivot->enrollment_limit - $session->pivot->current_enrollments)
                : null;
            $session->enrollment_status = $this->getEnrollmentStatus($session);
            return $session;
        });
}

/**
 * Get available assessments with enrollment status
 */
public function getAvailableAssessments()
{
    return $this->assessments()
        ->withPivot('enrollment_limit', 'current_enrollments')
        ->get()
        ->map(function($assessment) {
            $assessment->is_available = $assessment->pivot->enrollment_limit === null 
                || $assessment->pivot->current_enrollments < $assessment->pivot->enrollment_limit;
            $assessment->spots_remaining = $assessment->pivot->enrollment_limit 
                ? ($assessment->pivot->enrollment_limit - $assessment->pivot->current_enrollments)
                : null;
            $assessment->enrollment_status = $this->getEnrollmentStatus($assessment);
            return $assessment;
        });
}

/**
 * Get selection requirements for flexible services
 */
public function getRequiredSelections(): array
{
    if (!$this->isFlexibleService()) {
        return ['live_sessions' => 0, 'assessments' => 0];
    }
    
    return [
        'live_sessions' => $this->selection_config['live_sessions']['selection_required'] ?? 0,
        'assessments' => $this->selection_config['assessments']['selection_required'] ?? 0,
    ];
}

/**
 * Get enrollment status text
 */
private function getEnrollmentStatus($item): string
{
    if ($item->pivot->enrollment_limit === null) {
        return 'unlimited';
    }
    
    $current = $item->pivot->current_enrollments;
    $limit = $item->pivot->enrollment_limit;
    
    if ($current >= $limit) {
        return 'full';
    }
    
    return 'available';
}
```

### CartItem Model (`app/Models/CartItem.php`)

#### Add to Fillable
```php
protected $fillable = [
    // ... existing fields
    'metadata',
];
```

#### Add Cast
```php
protected $casts = [
    // ... existing casts
    'metadata' => 'array',
];
```

#### New Validation Method

```php
/**
 * Validate user selections for flexible services
 */
public function validateSelections(Service $service): bool
{
    if (!$service->isFlexibleService()) {
        return true; // No validation needed for non-flexible services
    }
    
    $required = $service->getRequiredSelections();
    $selected = $this->metadata ?? [];
    
    // Check live sessions count
    $selectedSessions = $selected['selected_live_sessions'] ?? [];
    if (count($selectedSessions) !== $required['live_sessions']) {
        throw new \Exception("Must select exactly {$required['live_sessions']} live sessions");
    }
    
    // Check assessments count
    $selectedAssessments = $selected['selected_assessments'] ?? [];
    if (count($selectedAssessments) !== $required['assessments']) {
        throw new \Exception("Must select exactly {$required['assessments']} assessments");
    }
    
    // Validate availability of selected live sessions
    $availableSessions = $service->getAvailableLiveSessions()
        ->filter(fn($s) => $s->is_available)
        ->pluck('id')
        ->toArray();
        
    foreach ($selectedSessions as $sessionId) {
        if (!in_array($sessionId, $availableSessions)) {
            throw new \Exception("Selected live session $sessionId is no longer available");
        }
    }
    
    // Validate availability of selected assessments
    $availableAssessments = $service->getAvailableAssessments()
        ->filter(fn($a) => $a->is_available)
        ->pluck('id')
        ->toArray();
        
    foreach ($selectedAssessments as $assessmentId) {
        if (!in_array($assessmentId, $availableAssessments)) {
            throw new \Exception("Selected assessment $assessmentId is no longer available");
        }
    }
    
    return true;
}

/**
 * Get selected content IDs
 */
public function getSelectedContent(): array
{
    return [
        'live_sessions' => $this->metadata['selected_live_sessions'] ?? [],
        'assessments' => $this->metadata['selected_assessments'] ?? [],
    ];
}
```

## Backend Implementation

### Service: FlexibleServiceAccessService

**File**: `app/Services/FlexibleServiceAccessService.php`

```php
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
        ?int $transactionId = null
    ): array {
        $granted = [
            'live_sessions' => [],
            'assessments' => [],
        ];
        
        DB::transaction(function() use ($childId, $service, $selections, $transactionId, &$granted) {
            // Grant access to selected live sessions
            foreach ($selections['selected_live_sessions'] ?? [] as $sessionId) {
                // Create access record
                Access::create([
                    'child_id' => $childId,
                    'lesson_id' => $sessionId,
                    'transaction_id' => $transactionId,
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
            foreach ($selections['selected_assessments'] ?? [] as $assessmentId) {
                // Create access record
                Access::create([
                    'child_id' => $childId,
                    'assessment_id' => $assessmentId,
                    'transaction_id' => $transactionId,
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
            foreach ($selections['selected_live_sessions'] ?? [] as $sessionId) {
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
            foreach ($selections['selected_assessments'] ?? [] as $assessmentId) {
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
        foreach ($selections['selected_live_sessions'] ?? [] as $sessionId) {
            if (!in_array($sessionId, $availableSessions)) {
                return false;
            }
        }
        
        // Check all selected assessments are still available
        foreach ($selections['selected_assessments'] ?? [] as $assessmentId) {
            if (!in_array($assessmentId, $availableAssessments)) {
                return false;
            }
        }
        
        return true;
    }
}
```

### Update: GrantAccessForTransactionJob

**File**: `app/Jobs/GrantAccessForTransactionJob.php`

Add handling for flexible services:

```php
use App\Services\FlexibleServiceAccessService;

public function handle()
{
    // ... existing code ...
    
    foreach ($this->transaction->items as $item) {
        $service = Service::find($item->service_id);
        
        if (!$service) {
            continue;
        }
        
        // Handle course services (existing)
        if ($service->isCourseService()) {
            $this->handleCourseService($service, $childId);
            continue;
        }
        
        // Handle flexible services (NEW)
        if ($service->isFlexibleService()) {
            $this->handleFlexibleService($service, $childId, $item);
            continue;
        }
        
        // Handle fixed services (existing)
        $this->handleFixedService($service, $childId);
    }
}

private function handleFlexibleService(Service $service, int $childId, $item): void
{
    $cartItem = CartItem::find($item->cart_item_id);
    
    if (!$cartItem || !$cartItem->metadata) {
        \Log::warning("No selections found for flexible service", [
            'service_id' => $service->id,
            'cart_item_id' => $item->cart_item_id,
        ]);
        return;
    }
    
    $selections = $cartItem->getSelectedContent();
    
    // Final validation before granting access
    $flexibleService = app(FlexibleServiceAccessService::class);
    
    if (!$flexibleService->validateSelectionsAvailability($service, $selections)) {
        \Log::error("Selections no longer available during checkout", [
            'service_id' => $service->id,
            'selections' => $selections,
        ]);
        throw new \Exception("Some selected content is no longer available");
    }
    
    // Grant access
    $granted = $flexibleService->grantFlexibleAccess(
        $childId,
        $service,
        $selections,
        $this->transaction->id
    );
    
    \Log::info("Flexible service access granted", [
        'service_id' => $service->id,
        'child_id' => $childId,
        'granted' => $granted,
    ]);
}
```

## Frontend Implementation

### Admin Interface

#### 1. Service Type Selection Component

**File**: `resources/js/admin/components/Services/ServiceTypeSelector.jsx`

```jsx
import React from 'react';
import { RadioGroup } from '@headlessui/react';

export default function ServiceTypeSelector({ value, onChange }) {
    const types = [
        { id: 'course', name: 'Full Course', description: 'Grant access to entire course' },
        { id: 'fixed', name: 'Fixed Bundle', description: 'Pre-selected content' },
        { id: 'flexible', name: 'Flexible Selection', description: 'User selects content' },
    ];
    
    return (
        <RadioGroup value={value} onChange={onChange}>
            <RadioGroup.Label className="text-sm font-medium text-gray-700">
                Service Type
            </RadioGroup.Label>
            <div className="mt-2 space-y-2">
                {types.map((type) => (
                    <RadioGroup.Option
                        key={type.id}
                        value={type.id}
                        className={({ checked }) =>
                            `${checked ? 'bg-indigo-50 border-indigo-600' : 'border-gray-300'}
                            relative block cursor-pointer rounded-lg border bg-white px-6 py-4 shadow-sm focus:outline-none`
                        }
                    >
                        {({ checked }) => (
                            <>
                                <div className="flex items-center justify-between">
                                    <div>
                                        <RadioGroup.Label className="block text-sm font-medium text-gray-900">
                                            {type.name}
                                        </RadioGroup.Label>
                                        <RadioGroup.Description className="mt-1 text-sm text-gray-500">
                                            {type.description}
                                        </RadioGroup.Description>
                                    </div>
                                    {checked && (
                                        <svg className="h-5 w-5 text-indigo-600" viewBox="0 0 20 20" fill="currentColor">
                                            <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                                        </svg>
                                    )}
                                </div>
                            </>
                        )}
                    </RadioGroup.Option>
                ))}
            </div>
        </RadioGroup>
    );
}
```

#### 2. Flexible Service Configuration Component

**File**: `resources/js/admin/components/Services/FlexibleServiceConfig.jsx`

```jsx
import React from 'react';
import LiveSessionSelector from './LiveSessionSelector';
import AssessmentSelector from './AssessmentSelector';

export default function FlexibleServiceConfig({ 
    service, 
    selectionConfig, 
    onConfigChange,
    onLiveSessionsChange,
    onAssessmentsChange 
}) {
    return (
        <div className="space-y-6">
            {/* Live Sessions Configuration */}
            <div className="bg-white shadow rounded-lg p-6">
                <h3 className="text-lg font-medium text-gray-900 mb-4">
                    Live Sessions Pool
                </h3>
                
                <LiveSessionSelector
                    service={service}
                    onChange={onLiveSessionsChange}
                />
                
                <div className="mt-4">
                    <label className="block text-sm font-medium text-gray-700">
                        User must select
                    </label>
                    <div className="mt-1 flex items-center space-x-2">
                        <input
                            type="number"
                            min="0"
                            max={service.lessons?.length || 0}
                            value={selectionConfig.live_sessions?.selection_required || 0}
                            onChange={(e) => onConfigChange('live_sessions', 'selection_required', parseInt(e.target.value))}
                            className="block w-24 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        <span className="text-sm text-gray-500">
                            from {service.lessons?.length || 0} available live sessions
                        </span>
                    </div>
                </div>
            </div>
            
            {/* Assessments Configuration */}
            <div className="bg-white shadow rounded-lg p-6">
                <h3 className="text-lg font-medium text-gray-900 mb-4">
                    Assessments Pool
                </h3>
                
                <AssessmentSelector
                    service={service}
                    onChange={onAssessmentsChange}
                />
                
                <div className="mt-4">
                    <label className="block text-sm font-medium text-gray-700">
                        User must select
                    </label>
                    <div className="mt-1 flex items-center space-x-2">
                        <input
                            type="number"
                            min="0"
                            max={service.assessments?.length || 0}
                            value={selectionConfig.assessments?.selection_required || 0}
                            onChange={(e) => onConfigChange('assessments', 'selection_required', parseInt(e.target.value))}
                            className="block w-24 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        <span className="text-sm text-gray-500">
                            from {service.assessments?.length || 0} available assessments
                        </span>
                    </div>
                </div>
            </div>
        </div>
    );
}
```

### User Interface

#### 1. User Content Selection Component

**File**: `resources/js/parent/components/Services/FlexibleContentSelector.jsx`

```jsx
import React, { useState } from 'react';
import { CheckCircleIcon, XCircleIcon } from '@heroicons/react/24/solid';

export default function FlexibleContentSelector({ 
    service, 
    onSelectionChange 
}) {
    const [selectedSessions, setSelectedSessions] = useState([]);
    const [selectedAssessments, setSelectedAssessments] = useState([]);
    
    const required = service.selection_requirements;
    
    const handleSessionToggle = (sessionId) => {
        const newSelection = selectedSessions.includes(sessionId)
            ? selectedSessions.filter(id => id !== sessionId)
            : [...selectedSessions, sessionId];
            
        if (newSelection.length <= required.live_sessions) {
            setSelectedSessions(newSelection);
            onSelectionChange({
                selected_live_sessions: newSelection,
                selected_assessments: selectedAssessments,
            });
        }
    };
    
    const handleAssessmentToggle = (assessmentId) => {
        const newSelection = selectedAssessments.includes(assessmentId)
            ? selectedAssessments.filter(id => id !== assessmentId)
            : [...selectedAssessments, assessmentId];
            
        if (newSelection.length <= required.assessments) {
            setSelectedAssessments(newSelection);
            onSelectionChange({
                selected_live_sessions: selectedSessions,
                selected_assessments: newSelection,
            });
        }
    };
    
    return (
        <div className="space-y-8">
            {/* Live Sessions Selection */}
            <div>
                <h3 className="text-lg font-medium text-gray-900 mb-4">
                    Select {required.live_sessions} Live Sessions
                </h3>
                <p className="text-sm text-gray-600 mb-4">
                    Selected: {selectedSessions.length} / {required.live_sessions}
                </p>
                
                <div className="grid grid-cols-1 gap-4">
                    {service.available_live_sessions.map((session) => (
                        <div
                            key={session.id}
                            className={`relative flex items-start p-4 border rounded-lg cursor-pointer ${
                                !session.is_available ? 'opacity-50 cursor-not-allowed' :
                                selectedSessions.includes(session.id) ? 'border-indigo-600 bg-indigo-50' : 'border-gray-300'
                            }`}
                            onClick={() => session.is_available && handleSessionToggle(session.id)}
                        >
                            <div className="flex-1">
                                <h4 className="text-sm font-medium text-gray-900">{session.title}</h4>
                                <p className="text-sm text-gray-500">{session.description}</p>
                                <div className="mt-2 flex items-center space-x-4 text-xs text-gray-500">
                                    <span>{new Date(session.scheduled_start_time).toLocaleString()}</span>
                                    {session.enrollment_status === 'available' && session.spots_remaining !== null && (
                                        <span className="text-green-600">{session.spots_remaining} spots remaining</span>
                                    )}
                                    {session.enrollment_status === 'full' && (
                                        <span className="text-red-600 flex items-center">
                                            <XCircleIcon className="h-4 w-4 mr-1" />
                                            Full
                                        </span>
                                    )}
                                    {session.enrollment_status === 'unlimited' && (
                                        <span className="text-blue-600">Unlimited spots</span>
                                    )}
                                </div>
                            </div>
                            {selectedSessions.includes(session.id) && (
                                <CheckCircleIcon className="h-6 w-6 text-indigo-600" />
                            )}
                        </div>
                    ))}
                </div>
            </div>
            
            {/* Assessments Selection */}
            <div>
                <h3 className="text-lg font-medium text-gray-900 mb-4">
                    Select {required.assessments} Assessments
                </h3>
                <p className="text-sm text-gray-600 mb-4">
                    Selected: {selectedAssessments.length} / {required.assessments}
                </p>
                
                <div className="grid grid-cols-1 gap-4">
                    {service.available_assessments.map((assessment) => (
                        <div
                            key={assessment.id}
                            className={`relative flex items-start p-4 border rounded-lg cursor-pointer ${
                                !assessment.is_available ? 'opacity-50 cursor-not-allowed' :
                                selectedAssessments.includes(assessment.id) ? 'border-indigo-600 bg-indigo-50' : 'border-gray-300'
                            }`}
                            onClick={() => assessment.is_available && handleAssessmentToggle(assessment.id)}
                        >
                            <div className="flex-1">
                                <h4 className="text-sm font-medium text-gray-900">{assessment.title}</h4>
                                <p className="text-sm text-gray-500">{assessment.description}</p>
                                <div className="mt-2 flex items-center space-x-4 text-xs text-gray-500">
                                    <span>{assessment.type}</span>
                                    {assessment.enrollment_status === 'available' && assessment.spots_remaining !== null && (
                                        <span className="text-green-600">{assessment.spots_remaining} spots remaining</span>
                                    )}
                                    {assessment.enrollment_status === 'full' && (
                                        <span className="text-red-600 flex items-center">
                                            <XCircleIcon className="h-4 w-4 mr-1" />
                                            Full
                                        </span>
                                    )}
                                    {assessment.enrollment_status === 'unlimited' && (
                                        <span className="text-blue-600">Unlimited spots</span>
                                    )}
                                </div>
                            </div>
                            {selectedAssessments.includes(assessment.id) && (
                                <CheckCircleIcon className="h-6 w-6 text-indigo-600" />
                            )}
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}
```

## Testing Strategy

### Unit Tests

1. **Service Model Tests**
   - Test `isFlexibleService()` detection
   - Test `getAvailableLiveSessions()` with various enrollment states
   - Test `getAvailableAssessments()` with various enrollment states
   - Test `getRequiredSelections()` for different configurations

2. **CartItem Validation Tests**
   - Test selection count validation
   - Test availability validation
   - Test edge cases (empty selections, over-selection)

3. **FlexibleServiceAccessService Tests**
   - Test access granting
   - Test enrollment increment
   - Test access revocation
   - Test enrollment decrement
   - Test race condition handling

### Integration Tests

1. **Purchase Flow Test**
   - Create flexible service
   - Add to cart with selections
   - Complete checkout
   - Verify access granted
   - Verify enrollment incremented

2. **Refund Flow Test**
   - Purchase flexible service
   - Initiate refund
   - Verify access revoked
   - Verify enrollment decremented

3. **Concurrency Test**
   - Simulate multiple users selecting same content
   - Verify enrollment limits enforced
   - Verify race conditions handled

## Implementation Checklist

### Phase 1: Database & Models (Backend Foundation)
- [ ] Create migration for `lesson_service` enrollment tracking
- [ ] Create migration for `assessment_service` enrollment tracking
- [ ] Create migration for `services.selection_config`
- [ ] Run migrations
- [ ] Update Service model (fillable, casts, helper methods)
- [ ] Update CartItem model (fillable, casts, validation method)
- [ ] Test model methods with unit tests

### Phase 2: Business Logic (Backend Services)
- [ ] Create FlexibleServiceAccessService
- [ ] Implement `grantFlexibleAccess()` method
- [ ] Implement `revokeFlexibleAccess()` method
- [ ] Implement `validateSelectionsAvailability()` method
- [ ] Update GrantAccessForTransactionJob
- [ ] Add flexible service handling to job
- [ ] Test service methods with unit tests

### Phase 3: Admin Interface (Frontend)
- [ ] Create ServiceTypeSelector component
- [ ] Create FlexibleServiceConfig component
- [ ] Create LiveSessionSelector component (for attaching with limits)
- [ ] Create AssessmentSelector component (for attaching with limits)
- [ ] Update Service Create page
- [ ] Update Service Edit page
- [ ] Add enrollment limit input fields
- [ ] Display current enrollments in service management

### Phase 4: User Interface (Frontend)
- [ ] Create FlexibleContentSelector component
- [ ] Update Service detail/purchase page
- [ ] Add selection UI for live sessions
- [ ] Add selection UI for assessments
- [ ] Add enrollment status indicators
- [ ] Update cart to store selections
- [ ] Add validation before checkout
- [ ] Add re-validation at checkout

### Phase 5: Testing & QA
- [ ] Write unit tests for all new methods
- [ ] Write integration tests for purchase flow
- [ ] Write integration tests for refund flow
- [ ] Test race conditions and concurrency
- [ ] Manual testing of complete user journey
- [ ] Manual testing of admin workflows
- [ ] Performance testing with large datasets

### Phase 6: Documentation & Deployment
- [ ] Update API documentation
- [ ] Create user guide for flexible services
- [ ] Create admin guide for configuring flexible services
- [ ] Database backup before migration
- [ ] Deploy migrations to production
- [ ] Monitor for issues
- [ ] Collect feedback

## Rollback Plan

If issues arise after deployment:

1. **Immediate Actions**:
   - Disable flexible service creation in admin UI
   - Prevent new flexible service purchases
   - Allow existing purchases to complete

2. **Database Rollback** (if necessary):
   ```sql
   -- Remove selection_config
   ALTER TABLE services DROP COLUMN selection_config;
   
   -- Remove enrollment tracking from lesson_service
   ALTER TABLE lesson_service 
   DROP COLUMN enrollment_limit,
   DROP COLUMN current_enrollments;
   
   -- Remove enrollment tracking from assessment_service
   ALTER TABLE assessment_service 
   DROP COLUMN enrollment_limit,
   DROP COLUMN current_enrollments;
   ```

3. **Code Rollback**:
   - Revert GrantAccessForTransactionJob changes
   - Remove FlexibleServiceAccessService
   - Revert model changes

## Future Enhancements

Potential improvements for future iterations:

1. **Pricing Tiers**: Different prices for different content selections
2. **Dynamic Pricing**: Surge pricing based on demand
3. **Waitlists**: Allow users to join waitlist when content is full
4. **Selection Changes**: Allow users to modify selections after purchase (within limits)
5. **Recommendation Engine**: Suggest content based on user preferences
6. **Bundle Discounts**: Offer discounts for selecting complementary content
7. **Time-based Limits**: Enrollment limits that reset periodically

## Conclusion

This flexible service system provides a powerful way for administrators to offer customizable educational packages while maintaining control over enrollment and capacity. The implementation is backward compatible, scalable, and provides a foundation for future enhancements.
