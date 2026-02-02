<?php
// scripts/inspect_guest_49.php
// Prints the Child, Application and User for the simulated onboarding run (child_id 49, user_id 38)
// Bootstraps the Laravel application and outputs arrays for inspection.

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Child;
use App\Models\Application;
use App\Models\User;

echo "Inspecting guest onboarding artifacts\n\n";

$childId = 49;
$userId = 38;
$appIdPrefix = '37b7c133'; // prefix observed in logs

echo "Child (id={$childId}):\n";
$child = Child::find($childId);
if ($child) {
    print_r($child->toArray());
} else {
    echo "Child not found\n";
}

echo "\nApplications with application_id starting with {$appIdPrefix}:\n";
$apps = Application::where('application_id', 'like', $appIdPrefix . '%')->get()->toArray();
print_r($apps);

echo "\nUser (id={$userId}):\n";
$user = User::find($userId);
if ($user) {
    print_r($user->toArray());
} else {
    echo "User not found\n";
}

echo "\nDone.\n";
