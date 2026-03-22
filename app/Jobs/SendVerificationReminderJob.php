<?php

namespace App\Jobs;

use App\Mail\VerificationReminder;
use App\Models\Application;
use App\Models\ApplicationActivity;
use App\Services\ApplicationActivityService;
use App\Support\MailContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendVerificationReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // Find unverified applications submitted 24-72h ago that haven't received a reminder
        $applications = Application::query()
            ->whereNull('verified_at')
            ->where('application_status', 'Pending')
            ->where('created_at', '<', now()->subHours(24))
            ->where('created_at', '>', now()->subHours(72))
            ->whereDoesntHave('activities', function ($q) {
                $q->where('activity_type', ApplicationActivity::TYPE_SYSTEM)
                  ->where('title', 'Verification reminder sent');
            })
            ->get();

        foreach ($applications as $application) {
            try {
                $organization = MailContext::resolveOrganization($application->organization_id);
                MailContext::sendMailable(
                    $application->email,
                    new VerificationReminder($application, $organization),
                    true // queue
                );

                ApplicationActivityService::logSystemEvent(
                    $application,
                    'Verification reminder sent',
                    'Automated reminder sent because email was not verified within 24 hours'
                );
            } catch (\Throwable $e) {
                Log::warning('Failed to send verification reminder', [
                    'application_id' => $application->application_id,
                    'error'          => $e->getMessage(),
                ]);
            }
        }
    }
}
