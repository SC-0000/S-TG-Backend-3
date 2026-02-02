<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Subscription;

class SubscriptionSeeder extends Seeder
{
    public function run(): void
    {
        Subscription::updateOrCreate(
            ['slug' => 'tutor_ai_plus'],
            [
                'name'     => 'Tutor AI Plus',
                'features' => [
                    'ai_analysis'      => true,
                    'enhanced_reports' => true,
                ],
            ],
        );
    }
}
