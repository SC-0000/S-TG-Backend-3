<?php
// scripts/inspect_tx.php
// Inspect transaction, its items and the linked user's children.
// Usage: php scripts/inspect_tx.php [transaction_id]
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$transactionId = $argv[1] ?? 55;

try {
    $t = \App\Models\Transaction::with('items')->find($transactionId);
    if (! $t) {
        echo "Transaction not found: {$transactionId}\n";
        exit(1);
    }
    $user = $t->user;
    $children = $user ? $user->children()->get()->map(fn($c) => ['id'=>$c->id,'child_name'=>$c->child_name])->toArray() : [];
    $items = $t->items->map(fn($it) => ['id'=>$it->id,'item_type'=>$it->item_type,'item_id'=>$it->item_id,'description'=>$it->description,'qty'=>$it->qty,'unit_price'=>$it->unit_price])->toArray();

    echo json_encode([
        'transaction_id' => $t->id,
        'status' => $t->status,
        'invoice_id' => $t->invoice_id,
        'user_id' => $t->user_id,
        'user_email' => $t->user_email,
        'meta' => $t->meta,
        'items' => $items,
        'user_children' => $children,
    ], JSON_PRETTY_PRINT) . "\n";
} catch (\Throwable $e) {
    echo "Error: ".$e->getMessage()."\n";
    exit(1);
}
