<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\ClientHealthService;
use Illuminate\Console\Command;

class ComputeClientHealthScores extends Command
{
    protected $signature = 'clients:compute-health-scores {--org= : Specific organization ID}';
    protected $description = 'Compute client health scores for all parents across organizations';

    public function handle(ClientHealthService $service): int
    {
        $orgId = $this->option('org');

        if ($orgId) {
            $org = Organization::find($orgId);
            if (!$org) {
                $this->error("Organization {$orgId} not found.");
                return self::FAILURE;
            }
            $count = $service->computeForOrganization($org->id);
            $this->info("Computed health scores for {$count} parent(s) in {$org->name}.");
            return self::SUCCESS;
        }

        $organizations = Organization::all();
        $totalCount = 0;

        foreach ($organizations as $org) {
            $count = $service->computeForOrganization($org->id);
            if ($count > 0) {
                $this->line("  {$org->name}: {$count} parent(s)");
            }
            $totalCount += $count;
        }

        $this->info("Done. Computed health scores for {$totalCount} parent(s) across {$organizations->count()} organization(s).");

        return self::SUCCESS;
    }
}
