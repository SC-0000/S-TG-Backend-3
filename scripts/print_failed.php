<?php
require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$rows = app('db')->table('failed_jobs')->get()->toArray();

echo json_encode($rows, JSON_PRETTY_PRINT);
