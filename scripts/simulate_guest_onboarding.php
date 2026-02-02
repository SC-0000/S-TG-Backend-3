<?php
// scripts/simulate_guest_onboarding.php
// Simulate a guest onboarding POST by bootstrapping the app, ensuring a guest_parent user exists,
// setting the current auth user to that guest, and calling GuestOnboardingController::store()
// with a sample payload.
//
// NOTE: This will modify the database (may create/upgrade a user and create a child).
// Run only if you accept these changes.

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';

/** @var \Illuminate\Contracts\Console\Kernel $kernel */
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;

echo "Bootstrapped application\n";

try {
    // Find an existing guest_parent or create one
    $user = User::where('role', User::ROLE_GUEST_PARENT)->first();

    if (! $user) {
        echo "No guest_parent found, creating a temporary guest user...\n";
        $tempPassword = Str::random(20);
        $user = User::create([
            'name' => 'Simulated Guest',
            'email' => 'simulated-guest+' . time() . '@example.test',
            'password' => Hash::make($tempPassword),
            'role' => User::ROLE_GUEST_PARENT,
        ]);
        echo "Created user id={$user->id} email={$user->email}\n";
    } else {
        echo "Found guest_parent id={$user->id} email={$user->email}\n";
    }

    // Set the current authenticated user for the container
    app('auth')->setUser($user);
    echo "Set auth user for simulation\n";

    // Build sample payload (one child)
    $payload = [
        'name' => 'Updated Simulated Guest',
        'email' => $user->email,
        'mobile_number' => '+441234567890',
        'address_line1' => '1 Test Street',
        'address_line2' => 'Unit 5',
        'referral_source' => 'Friend or family',
        'application_type' => 'Type2',
        'terms_accepted' => true,
        'redirect_to' => '/',
        'children' => [
            [
                'child_name' => 'Simulated Child',
                'date_of_birth' => '2016-05-01',
                'school_name' => 'Test Primary',
                'area' => 'Test Area',
                'year_group' => 'Year 1',
                'age' => 7,
                'emergency_contact_name' => 'John Doe',
                'emergency_contact_phone' => '+441234000000',
                'academic_info' => 'N/A',
                'previous_grades' => 'N/A',
                'medical_info' => 'N/A',
                'additional_info' => 'Simulated data',
            ]
        ],
    ];

    // Create a Request instance
    $request = Request::create('/guest/complete-profile', 'POST', $payload);

    // Ensure the request uses the console session store if needed
    $request->setLaravelSession(app('session')->driver());

    // Call the controller
    /** @var \App\Http\Controllers\GuestOnboardingController $controller */
    $controller = app(\App\Http\Controllers\GuestOnboardingController::class);

    echo "Calling GuestOnboardingController::store()\n";

    $response = $controller->store($request);

    echo "Controller returned: ";
    if (is_string($response)) {
        echo $response . PHP_EOL;
    } elseif (is_object($response) && method_exists($response, 'getStatusCode')) {
        echo "Status " . $response->getStatusCode() . PHP_EOL;
    } else {
        print_r($response);
    }

    echo "Simulation complete. Check storage/logs/laravel.log for 'GuestOnboarding:' entries and DB for created/updated records.\n";
} catch (\Throwable $e) {
    echo "Exception during simulation: " . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
    exit(1);
}
