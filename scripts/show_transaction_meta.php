<?php
// scripts/show_transaction_meta.php
// Prints the meta column for a transaction id (hardcoded below).
// Usage: php scripts/show_transaction_meta.php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$transactionId = 55;

try {
    $t = \App\Models\Transaction::find($transactionId);
    if (! $t) {
        echo "Transaction not found: {$transactionId}\n";
        exit(1);
    }
    echo json_encode([
        'id' => $t->id,
        'meta' => $t->meta,
    ], JSON_PRETTY_PRINT) . "\n";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
