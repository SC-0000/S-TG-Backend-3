<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// Basic artisan command
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily maintenance: cancel expired user subscriptions
Schedule::call(function () {
    DB::table('user_subscriptions')
        ->where('status', 'active')
        ->whereNotNull('ends_at')
        ->where('ends_at', '<', now())
        ->update(['status' => 'canceled']);
})->daily();

// Billing sync: for users with pending transactions, sync invoice statuses from billing provider.
// Runs every 15 minutes (webhooks handle real-time updates, this is a safety net).
Schedule::call(function () {
    // Only sync users who have pending transactions (not all billing customers)
    $customerIds = DB::table('transactions')
        ->where('status', 'pending')
        ->whereNotNull('invoice_id')
        ->join('users', 'users.id', '=', 'transactions.user_id')
        ->whereNotNull('users.billing_customer_id')
        ->pluck('users.billing_customer_id')
        ->unique();

    foreach ($customerIds as $cid) {
        \App\Jobs\SyncBillingInvoicesJob::dispatch($cid);
    }
})->everyFifteenMinutes()->name('billing:sync-invoices')->withoutOverlapping();

// Daily billing reconciliation: catch missed webhooks, expire plans, sync customer data.
Schedule::job(new \App\Jobs\DailyBillingReconciliationJob())
    ->dailyAt('04:00')
    ->name('billing:daily-reconciliation')
    ->withoutOverlapping();

// Background Agent System: dispatch scheduled agents
Schedule::call(function () {
    app(\App\Services\AI\BackgroundAgents\BackgroundAgentOrchestrator::class)->dispatchScheduled();
})->everyFiveMinutes()->name('background-agents:dispatch')->withoutOverlapping();

// If using a dedicated agent queue (set QUEUE_AGENT_QUEUE=ai-background in .env),
// this worker processes it. Not needed if agents use the default queue.
if (config('queue.agent_queue') && config('queue.agent_queue') !== 'default') {
    Schedule::command('queue:work database --queue=' . config('queue.agent_queue') . ' --max-time=240 --sleep=3 --tries=2 --memory=256')
        ->everyFiveMinutes()
        ->name('queue:work-agent-queue')
        ->withoutOverlapping();
}

// Scheduling: auto-generate lessons from fixed allocations (weekly)
Schedule::command('schedule:generate-lessons --weeks=1')
    ->weekly()
    ->mondays()
    ->at('06:00')
    ->name('schedule:generate-lessons')
    ->withoutOverlapping();

// Audit log: delete records older than 90 days (keep DB lean)
Schedule::call(function () {
    \App\Services\AuditLogger::cleanup(90);
})->daily()->name('audit-logs:cleanup')->withoutOverlapping();

// Background Agent System: clean up stale/orphaned runs
Schedule::call(function () {
    // Runs stuck as "pending" for more than 5 minutes → mark failed
    \App\Models\BackgroundAgentRun::where('status', 'pending')
        ->where('created_at', '<', now()->subMinutes(5))
        ->update([
            'status' => 'failed',
            'completed_at' => now(),
            'error_message' => 'Timed out: job was never picked up by the queue worker',
        ]);

    // Runs stuck as "running" for more than 15 minutes → mark failed
    \App\Models\BackgroundAgentRun::where('status', 'running')
        ->where('started_at', '<', now()->subMinutes(15))
        ->update([
            'status' => 'failed',
            'completed_at' => now(),
            'error_message' => 'Timed out: worker terminated before completion',
        ]);

    // Purge empty failed/skipped runs older than 7 days (keep runs that have actions)
    \App\Models\BackgroundAgentRun::whereIn('status', ['failed', 'skipped'])
        ->where('items_processed', 0)
        ->where('items_affected', 0)
        ->where('created_at', '<', now()->subDays(7))
        ->whereDoesntHave('actions')
        ->delete();
})->everyFiveMinutes()->name('background-agents:cleanup')->withoutOverlapping();

// Communications: daily digest emails for admin/teachers
Schedule::job(new \App\Jobs\SendDigestEmailsJob('daily'))
    ->dailyAt('08:00')
    ->name('communications:daily-digest')
    ->withoutOverlapping();

// Communications: weekly digest emails (Mondays)
Schedule::job(new \App\Jobs\SendDigestEmailsJob('weekly'))
    ->weeklyOn(1, '08:00')
    ->name('communications:weekly-digest')
    ->withoutOverlapping();

// Communications: process automated reminders (lesson, payment, homework)
Schedule::job(new \App\Jobs\ProcessAutomatedRemindersJob())
    ->hourly()
    ->name('communications:automated-reminders')
    ->withoutOverlapping();

// Applications: send verification reminders for unverified applications (24h+)
Schedule::job(new \App\Jobs\SendVerificationReminderJob())
    ->hourly()
    ->name('applications:verification-reminders')
    ->withoutOverlapping();

// Organization billing: sync invoice statuses from I-BLS-2
Schedule::call(function () {
    app(\App\Services\BillingService::class)->syncOrganizationInvoiceStatuses();
})->everyFifteenMinutes()->name('billing:sync-org-invoices')->withoutOverlapping();

// Organization billing: auto-generate monthly invoices for orgs with active plans
Schedule::job(new \App\Jobs\GenerateOrganizationInvoicesJob())
    ->monthlyOn(1, '03:00')
    ->name('billing:generate-org-invoices')
    ->withoutOverlapping();

// Plans & Usage: capture daily usage snapshots and reset AI action counters
Schedule::command('plans:capture-snapshots')
    ->daily()
    ->at('02:00')
    ->name('plans:capture-snapshots')
    ->withoutOverlapping();
