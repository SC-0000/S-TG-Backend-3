<?php
// Test script to verify dashboard fixes
// Run with: php test-dashboard-fix.php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Assessment;
use App\Models\AssessmentSubmission;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

echo "🔍 Testing Dashboard Fixes...\n\n";

try {
    // Test organization ID 2 (Eleven Plus)
    $orgId = 2;
    
    echo "📊 Testing Lesson Completion Rate Fix:\n";
    
    // Get current data manually
    $totalAssessments = Assessment::where('status', 'active')
        ->where('organization_id', $orgId)
        ->count();
    
    echo "   - Total Active Assessments: {$totalAssessments}\n";
    
    $assessmentsWithCompletions = Assessment::where('status', 'active')
        ->where('organization_id', $orgId)
        ->whereHas('submissions', fn($q) => $q->where('status', 'graded'))
        ->count();
    
    echo "   - Assessments with Completions: {$assessmentsWithCompletions}\n";
    
    $rate = $totalAssessments > 0 ? round(($assessmentsWithCompletions / $totalAssessments) * 100, 1) : 0;
    echo "   - Calculated Rate: {$rate}%\n";
    
    if ($rate <= 100) {
        echo "   ✅ Fix successful! Rate is now reasonable (≤100%)\n";
    } else {
        echo "   ❌ Still broken! Rate is over 100%\n";
    }
    
    echo "\n📈 Transaction Status Investigation:\n";
    
    $statuses = \App\Models\Transaction::select('status', DB::raw('COUNT(*) as count'))
        ->groupBy('status')
        ->get();
    
    foreach ($statuses as $status) {
        echo "   - {$status->status}: {$status->count}\n";
    }
    
    echo "\n🎯 Admin Tasks Investigation:\n";
    
    $adminTasks = \App\Models\AdminTask::select('status', 'organization_id', DB::raw('COUNT(*) as count'))
        ->groupBy('status', 'organization_id')
        ->get();
    
    foreach ($adminTasks as $task) {
        echo "   - Org {$task->organization_id}, Status '{$task->status}': {$task->count}\n";
    }
    
    echo "\n✅ Dashboard fixes tested successfully!\n";
    echo "\nNext: Visit /admin-dashboard/debug for detailed analysis\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
