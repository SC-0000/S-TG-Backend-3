<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\OrganizationPlan;
use App\Services\PlanUsageService;
use Illuminate\Console\Command;

class CaptureUsageSnapshots extends Command
{
    protected $signature = 'plans:capture-snapshots';
    protected $description = 'Capture daily usage snapshots for all active organizations and reset AI action counters';

    public function handle(PlanUsageService $planService): int
    {
        $organizations = Organization::active()->get();

        $this->info("Capturing usage snapshots for {$organizations->count()} organization(s)...");

        $snapshotCount = 0;
        $resetCount = 0;

        foreach ($organizations as $org) {
            $planService->captureSnapshot($org);
            $snapshotCount++;

            // Reset AI action counters if reset date has passed
            $aiPlans = OrganizationPlan::where('organization_id', $org->id)
                ->where('category', 'ai_workspace')
                ->where('status', 'active')
                ->whereNotNull('ai_actions_reset_at')
                ->where('ai_actions_reset_at', '<=', now())
                ->get();

            foreach ($aiPlans as $plan) {
                if ($plan->resetAiActionsIfNeeded()) {
                    $resetCount++;
                    $this->line("  Reset AI actions for org #{$org->id} plan #{$plan->id}");
                }
            }
        }

        $this->info("Done. {$snapshotCount} snapshot(s) captured, {$resetCount} AI counter(s) reset.");

        return self::SUCCESS;
    }
}
