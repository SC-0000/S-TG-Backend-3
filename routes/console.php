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

// Billing sync: for every user with a billing_customer_id, dispatch a SyncBillingInvoicesJob.
// Runs every 15 minutes and avoids overlapping runs.
Schedule::call(function () {
    $customerIds = DB::table('users')
        ->whereNotNull('billing_customer_id')
        ->pluck('billing_customer_id')
        ->unique();

    foreach ($customerIds as $cid) {
        // Dispatch the job for each billing customer ID
        \App\Jobs\SyncBillingInvoicesJob::dispatch($cid);
    }
})->everyMinute()->name('billing:sync-invoices')->withoutOverlapping();

// Optionally add a lighter sync for open orders (if you have many customers)
// Runs every 30 minutes and dispatches a global sync job (if implemented)
Schedule::call(function () {
    // If you have an app/Jobs/SyncAllOpenOrders job, you can dispatch it here.
    if (class_exists(\App\Jobs\SyncAllOpenOrders::class)) {
        \App\Jobs\SyncAllOpenOrders::dispatch();
    }
})->everyThirtyMinutes()->name('billing:sync-all-open-orders')->withoutOverlapping();

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

// Background Agent System: clean up stale/orphaned runs
Schedule::call(function () {
    // Runs stuck as "pending" for more than 10 minutes → mark failed
    \App\Models\BackgroundAgentRun::where('status', 'pending')
        ->where('created_at', '<', now()->subMinutes(10))
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
