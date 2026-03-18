<?php

namespace App\Console\Commands;

use App\Models\TeacherProfile;
use App\Services\ScheduleAllocationService;
use Illuminate\Console\Command;

class GenerateScheduledLessons extends Command
{
    protected $signature = 'schedule:generate-lessons {--weeks=1 : Number of weeks to generate ahead}';
    protected $description = 'Generate lessons from fixed schedule allocations for the upcoming week(s)';

    public function handle(ScheduleAllocationService $service): int
    {
        $weeks = (int) $this->option('weeks');
        $this->info("Generating lessons for the next {$weeks} week(s)...");

        $profiles = TeacherProfile::whereHas('allocations', fn ($q) => $q->where('allocation_type', 'fixed'))
            ->get();

        $totalGenerated = 0;

        foreach ($profiles as $profile) {
            $count = $service->extendLessonsForTeacher($profile, $weeks);
            if ($count > 0) {
                $this->line("  {$profile->display_name}: {$count} lesson(s) generated");
                $totalGenerated += $count;
            }
        }

        $this->info("Done. {$totalGenerated} total lesson(s) generated across {$profiles->count()} teacher(s).");

        return self::SUCCESS;
    }
}
