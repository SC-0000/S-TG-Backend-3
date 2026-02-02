<?php
// scripts/dispatch_all_sync.php
// Dispatches the SyncAllOpenOrders job (reconcile all open billing orders).
// Usage: php scripts/dispatch_all_sync.php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    \App\Jobs\SyncAllOpenOrders::dispatch();
    echo "Dispatched SyncAllOpenOrders job\n";
} catch (\Throwable $e) {
    echo "Failed to dispatch SyncAllOpenOrders: " . $e->getMessage() . "\n";
    exit(1);
}
