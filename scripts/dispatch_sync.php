<?php
// scripts/dispatch_sync.php
// Dispatches the SyncBillingInvoicesJob for a given billing customer id.
//
// Usage: php scripts/dispatch_sync.php
require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$billingCustomerId = 'cf3cd285-9081-450b-9c43-3ce93173c962';

try {
    \App\Jobs\SyncBillingInvoicesJob::dispatch($billingCustomerId);
    echo "Dispatched SyncBillingInvoicesJob for {$billingCustomerId}\n";
} catch (\Throwable $e) {
    echo "Failed to dispatch SyncBillingInvoicesJob: " . $e->getMessage() . "\n";
    exit(1);
}
