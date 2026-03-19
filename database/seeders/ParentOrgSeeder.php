<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\Child;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ParentOrgSeeder extends Seeder
{
    /**
     * Seeds a test parent account for Organisation ID 1.
     *
     * Login: parent@test.com / parent123
     *
     * Run with: php artisan db:seed --class=ParentOrgSeeder
     */
    public function run(): void
    {
        $organizationId = 1;
        $now = now();

        $org = Organization::find($organizationId);

        if (! $org) {
            $this->command->error("Organisation ID {$organizationId} does not exist. Run the main seeder first.");
            return;
        }

        // ── Parent user ────────────────────────────────────────────────────────
        $parent = User::updateOrCreate(
            ['email' => 'parent@test.com'],
            [
                'name'                    => 'Sarah Thompson',
                'password'                => Hash::make('parent123'),
                'role'                    => User::ROLE_PARENT,
                'email_verified_at'       => $now,
                'address_line1'           => '42 Elm Street',
                'address_line2'           => 'Mapleton',
                'mobile_number'           => '07700900001',
                'current_organization_id' => $organizationId,
                'onboarding_complete'     => true,
            ]
        );

        // ── Organisation membership ────────────────────────────────────────────
        DB::table('organization_users')->updateOrInsert(
            ['organization_id' => $organizationId, 'user_id' => $parent->id],
            [
                'role'      => 'parent',
                'status'    => 'active',
                'joined_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        // ── Application (required parent record for children) ──────────────────
        $applicationId = Str::uuid()->toString();

        $application = Application::updateOrCreate(
            ['email' => 'parent@test.com'],
            [
                'application_id'    => $applicationId,
                'applicant_name'    => 'Sarah Thompson',
                'phone_number'      => '07700900001',
                'application_status' => 'approved',
                'submitted_date'    => $now,
                'application_type'  => 'Type1',
                'verification_token' => Str::random(32),
                'verified_at'       => $now,
                'referral_source'   => 'seeder',
                'tracking_code'     => 'SEED-PARENT-001',
                'address_line1'     => '42 Elm Street',
                'address_line2'     => 'Mapleton',
                'mobile_number'     => '07700900001',
                'children_data'     => json_encode([]),
                'user_id'           => $parent->id,
                'organization_id'   => $organizationId,
            ]
        );

        // Ensure we have the application_id for child FK
        $appId = $application->application_id;

        // ── Children ───────────────────────────────────────────────────────────
        $childrenData = [
            [
                'child_name'               => 'Oliver Thompson',
                'age'                      => 10,
                'date_of_birth'            => '2015-03-14',
                'school_name'              => 'Mapleton Primary School',
                'area'                     => 'Mapleton',
                'year_group'               => 'Year 5',
                'learning_difficulties'    => null,
                'focus_targets'            => 'Maths and English comprehension',
                'other_information'        => 'Enjoys science and reading',
                'emergency_contact_name'   => 'James Thompson',
                'emergency_contact_phone'  => '07700900002',
                'academic_info'            => 'Working at expected level for year group',
                'previous_grades'          => 'Mostly Bs and Cs',
                'medical_info'             => null,
                'additional_info'          => null,
            ],
            [
                'child_name'               => 'Ella Thompson',
                'age'                      => 7,
                'date_of_birth'            => '2018-09-22',
                'school_name'              => 'Mapleton Primary School',
                'area'                     => 'Mapleton',
                'year_group'               => 'Year 2',
                'learning_difficulties'    => 'Mild dyslexia',
                'focus_targets'            => 'Reading fluency and phonics',
                'other_information'        => 'Responds well to visual learning aids',
                'emergency_contact_name'   => 'James Thompson',
                'emergency_contact_phone'  => '07700900002',
                'academic_info'            => 'Slightly below expected level — additional support in place',
                'previous_grades'          => null,
                'medical_info'             => null,
                'additional_info'          => 'Prefers working in quiet environments',
            ],
        ];

        foreach ($childrenData as $data) {
            $child = Child::updateOrCreate(
                [
                    'user_id'    => $parent->id,
                    'child_name' => $data['child_name'],
                ],
                array_merge($data, [
                    'application_id'  => $appId,
                    'organization_id' => $organizationId,
                ])
            );

            // Permissions / consent record for each child
            Permission::updateOrCreate(
                ['user_id' => $parent->id, 'child_id' => $child->id],
                [
                    'terms_accepted_at' => $now,
                    'signature_path'    => null,
                ]
            );
        }

        $this->command->info('✓ Parent seeded successfully.');
        $this->command->info('  Email:    parent@test.com');
        $this->command->info('  Password: parent123');
        $this->command->info('  Org ID:   ' . $organizationId);
        $this->command->info('  Children: Oliver Thompson (Y5), Ella Thompson (Y2)');
    }
}
