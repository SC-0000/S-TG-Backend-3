<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncAllOpenOrders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle()
    {
        try {
            Log::info('SyncAllOpenOrders: starting sweep for billing customers');

            $customerIds = DB::table('users')
                ->whereNotNull('billing_customer_id')
                ->pluck('billing_customer_id')
                ->unique()
                ->filter()
                ->values();

            foreach ($customerIds as $cid) {
                if (class_exists(\App\Jobs\SyncBillingInvoicesJob::class)) {
                    \App\Jobs\SyncBillingInvoicesJob::dispatch($cid);
                    Log::info('SyncAllOpenOrders: dispatched SyncBillingInvoicesJob', ['billing_customer_id' => $cid]);
                } else {
                    Log::warning('SyncAllOpenOrders: SyncBillingInvoicesJob class not found, skipping dispatch');
                }
            }

            Log::info('SyncAllOpenOrders: finished dispatching jobs');
        } catch (\Throwable $e) {
            Log::error('SyncAllOpenOrders exception: ' . $e->getMessage(), [
                'exception' => (string) $e,
            ]);
            throw $e;
        }
    }
}
