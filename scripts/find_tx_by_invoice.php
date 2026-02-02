<?php
// scripts/find_tx_by_invoice.php
// Usage: php scripts/find_tx_by_invoice.php [invoice_id]
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$invoiceId = $argv[1] ?? '8460ae72-08bd-4349-a1b6-2ee6a537938c';

try {
    $tx = \App\Models\Transaction::where('invoice_id', $invoiceId)->first();
    if (! $tx) {
        echo "No transaction found for invoice_id: {$invoiceId}\n";
        // Try a looser match (like contains)
        $tx2 = \App\Models\Transaction::where('invoice_id', 'like', "%{$invoiceId}%")->first();
        if ($tx2) {
            echo "Found looser match: id={$tx2->id}, invoice_id={$tx2->invoice_id}\n";
        }
        exit(0);
    }
    echo "Found transaction id={$tx->id}, status={$tx->status}, invoice_id={$tx->invoice_id}\n";
    echo json_encode([
        'id' => $tx->id,
        'status' => $tx->status,
        'invoice_id' => $tx->invoice_id,
        'meta' => $tx->meta,
        'paid_at' => $tx->paid_at,
    ], JSON_PRETTY_PRINT) . "\n";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
