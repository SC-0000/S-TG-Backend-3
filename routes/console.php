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
