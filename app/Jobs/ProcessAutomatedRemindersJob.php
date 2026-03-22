<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Services\Communications\AutomatedReminderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAutomatedRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function handle(AutomatedReminderService $reminderService): void
    {
        $organizations = Organization::active()
            ->whereRaw("JSON_EXTRACT(settings, '$.communications.reminders_enabled') = true")
            ->get();

        // If no orgs have explicitly enabled, process all active orgs
        if ($organizations->isEmpty()) {
            $organizations = Organization::active()->get();
        }

        foreach ($organizations as $org) {
            try {
                $reminderService->processLessonReminders($org);
                $reminderService->processPaymentReminders($org);
                $reminderService->processHomeworkReminders($org);
            } catch (\Throwable $e) {
                Log::error("[AutomatedReminders] Failed for org {$org->id}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
