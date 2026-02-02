<?php
// scripts/simulate_guest_update.php
// Simulate updating an existing child via GuestOnboardingController::store()
// This will bootstrap the app, authenticate as user id 38 and submit a payload
// that includes children[].id = 49 with additional fields to update.

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Http\Request;
use App\Models\User;

echo "Bootstrapping and simulating guest onboarding UPDATE...\n";

$user = User::find(38);
if (! $user) {
    echo "User 38 not found. Aborting.\n";
    exit(1);
}

app('auth')->setUser($user);

$payload = [
    'name' => $user->name,
    'email' => $user->email,
    'mobile_number' => $user->mobile_number ?? '',
    'address_line1' => $user->address_line1 ?? '',
    'address_line2' => $user->address_line2 ?? '',
    'referral_source' => 'Updated referral',
    'application_type' => 'Type2',
    'terms_accepted' => true,
    'redirect_to' => '/',
    'children' => [
        [
            'id' => 49, // existing child created earlier
            'child_name' => 'Simulated Child Updated',
            'date_of_birth' => '2016-05-02',
            'age' => 8,
            'year_group' => 'Year 2',
            'school_name' => 'Updated School',
            'area' => 'Updated Area',
            'emergency_contact_name' => 'Jane Doe',
            'emergency_contact_phone' => '+441112223333',
            'academic_info' => 'Updated academic info',
            'previous_grades' => 'B+',
            'medical_info' => 'None',
            'additional_info' => 'Updated additional info',
        ],
    ],
];

$request = Request::create('/guest/complete-profile', 'POST', $payload);
$request->setLaravelSession(app('session')->driver());

$controller = app(\App\Http\Controllers\GuestOnboardingController::class);

echo "Calling GuestOnboardingController::store()\n";
$response = $controller->store($request);

echo "Controller returned: ";
if (is_object($response) && method_exists($response, 'getStatusCode')) {
    echo "Status " . $response->getStatusCode() . PHP_EOL;
} elseif (is_string($response)) {
    echo $response . PHP_EOL;
} else {
    print_r($response);
}

echo "Simulation complete. Check storage/logs/laravel.log for GuestOnboarding logs and DB for updated child.\n";
