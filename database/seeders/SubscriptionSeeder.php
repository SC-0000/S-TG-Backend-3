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
                'name'             => 'Tutor AI Plus',
                'owner_type'       => 'platform',
                'organization_id'  => null,
                'description'      => 'AI-powered tutoring with analysis and enhanced reports for your child.',
                'features'         => [
                    'ai_analysis'      => true,
                    'enhanced_reports' => true,
                ],
                'price'            => 9.99,
                'currency'         => 'GBP',
                'billing_interval' => 'monthly',
                'is_active'        => true,
                'sort_order'       => 0,
            ],
        );
    }
}
