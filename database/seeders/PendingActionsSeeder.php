<?php

namespace Database\Seeders;

use App\Models\Assessment;
use App\Models\AssessmentSubmission;
use App\Models\Child;
use App\Models\HomeworkAssignment;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\ParentFeedbacks;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PendingActionsSeeder extends Seeder
{
    /**
     * Seeds pending actions data for the Delivery Hub "Needs Action" panel.
     * Creates realistic test data across all action categories for Organisation 1.
     *
     * Run with: php artisan db:seed --class=PendingActionsSeeder
     */
    public function run(): void
    {
        $orgId = 1;
        $org = Organization::find($orgId);

        if (! $org) {
            $this->command->error("Organisation ID {$orgId} does not exist. Run the main seeder first.");
            return;
        }

        // Resolve or create required entities
        $admin = $this->resolveAdmin($orgId);
        $children = $this->resolveChildren($orgId);
        $service = $this->resolveService($orgId, $admin);

        if ($children->isEmpty()) {
            $this->command->error('No children found for org 1. Run ParentOrgSeeder first.');
            return;
        }

        $this->seedHomeworkActions($orgId, $admin, $children);
        $this->seedAssessmentActions($orgId, $children);
        $this->seedAttendanceActions($orgId, $admin, $children, $service);
        $this->seedUnassignedSessions($orgId, $children, $service);
        $this->seedParentFeedback($orgId);

        $this->command->info('');
        $this->command->info('✓ Pending actions seeded successfully for org 1.');
        $this->command->info('  Homework submissions needing grading: 5');
        $this->command->info('  Assessment submissions needing grading: 4');
        $this->command->info('  Sessions needing attendance: 3');
        $this->command->info('  Sessions needing teacher assignment: 3');
        $this->command->info('  Parent feedback awaiting response: 3');
    }

    private function resolveAdmin(int $orgId): User
    {
        // Use existing admin or super admin
        $admin = User::whereIn('role', [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN])->first();

        if (! $admin) {
            $admin = User::create([
                'name'              => 'Seed Admin',
                'email'             => 'seed-admin@test.com',
                'password'          => bcrypt('admin123'),
                'role'              => User::ROLE_ADMIN,
                'email_verified_at' => now(),
                'current_organization_id' => $orgId,
            ]);

            DB::table('organization_users')->updateOrInsert(
                ['organization_id' => $orgId, 'user_id' => $admin->id],
                ['role' => 'admin', 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]
            );
        }

        return $admin;
    }

    private function resolveChildren(int $orgId)
    {
        return Child::where('organization_id', $orgId)->limit(5)->get();
    }

    private function resolveService(int $orgId, User $admin): Service
    {
        $service = Service::where('organization_id', $orgId)->first();

        if (! $service) {
            $service = Service::create([
                'organization_id'         => $orgId,
                'service_name'            => 'Maths Tutoring',
                '_type'                   => 'lesson',
                'booking_mode'            => 'fixed_schedule',
                'price'                   => 30.00,
                'instructor_id'           => $admin->id,
                'max_participants'        => 4,
                'session_duration_minutes' => 60,
            ]);
        }

        return $service;
    }

    /**
     * 5 homework submissions awaiting grading — mix of urgent and recent.
     */
    private function seedHomeworkActions(int $orgId, User $admin, $children): void
    {
        $subjects = ['Mathematics', 'English', 'Science', 'History', 'Geography'];
        $titles = [
            'Fractions & Decimals Worksheet',
            'Creative Writing: Story Starter',
            'Forces & Motion Questions',
            'The Romans: Timeline Activity',
            'Map Skills: UK Rivers',
        ];

        foreach ($titles as $i => $title) {
            $child = $children[$i % $children->count()];
            $daysAgo = [8, 5, 3, 1, 0][$i]; // Mix of critical (8d), overdue (5d, 3d), recent (1d, today)

            $assignment = HomeworkAssignment::create([
                'title'           => $title,
                'subject'         => $subjects[$i],
                'description'     => "Complete all questions in the worksheet.",
                'due_date'        => now()->subDays($daysAgo + 2),
                'created_by'      => $admin->id,
                'assigned_by'     => $admin->id,
                'assigned_by_role' => 'admin',
                'status'          => 'published',
                'organization_id' => $orgId,
            ]);

            HomeworkSubmission::create([
                'assignment_id'     => $assignment->id,
                'student_id'        => $child->id,
                'organization_id'   => $orgId,
                'submission_status' => 'submitted',
                'content'           => 'Student completed all questions.',
                'attempt'           => 1,
                'submitted_at'      => now()->subDays($daysAgo),
            ]);
        }

        $this->command->info('  ✓ 5 homework submissions created');
    }

    /**
     * 4 assessment submissions awaiting grading.
     */
    private function seedAssessmentActions(int $orgId, $children): void
    {
        $assessmentTitles = [
            'Year 5 Maths: End of Term',
            'Reading Comprehension Test',
            'Science: Living Things Quiz',
            'Year 2 Phonics Check',
        ];

        foreach ($assessmentTitles as $i => $title) {
            $child = $children[$i % $children->count()];
            $daysAgo = [10, 4, 2, 0][$i];

            $assessment = Assessment::create([
                'title'           => $title,
                'description'     => "End of term assessment for {$title}.",
                'type'            => $i % 2 === 0 ? 'mcq' : 'mixed',
                'status'          => 'active',
                'availability'    => now()->subDays($daysAgo + 7),
                'deadline'        => now()->subDays($daysAgo),
                'time_limit'      => 60,
                'retake_allowed'  => false,
                'organization_id' => $orgId,
                'is_global'       => false,
                'questions_json'  => json_encode([
                    ['id' => 1, 'question' => 'Sample question', 'type' => 'mcq', 'options' => ['A', 'B', 'C'], 'answer' => 'A'],
                ]),
            ]);

            AssessmentSubmission::create([
                'assessment_id'  => $assessment->id,
                'child_id'       => $child->id,
                'user_id'        => $child->user_id,
                'retake_number'  => 0,
                'status'         => 'pending',
                'total_marks'    => 100,
                'started_at'     => now()->subDays($daysAgo)->subHours(1),
                'finished_at'    => now()->subDays($daysAgo),
                'answers_json'   => json_encode([['question_id' => 1, 'answer' => 'A']]),
            ]);
        }

        $this->command->info('  ✓ 4 assessment submissions created');
    }

    /**
     * 3 ended lessons with no attendance marked.
     */
    private function seedAttendanceActions(int $orgId, User $admin, $children, Service $service): void
    {
        $lessonTitles = ['Monday Maths Group', 'Tuesday English 1:1', 'Wednesday Science Group'];

        foreach ($lessonTitles as $i => $title) {
            $daysAgo = [5, 2, 1][$i];

            $lesson = Lesson::create([
                'title'           => $title,
                'lesson_type'     => $i % 2 === 0 ? 'group' : '1:1',
                'lesson_mode'     => $i % 2 === 0 ? 'online' : 'in_person',
                'start_time'      => now()->subDays($daysAgo)->setHour(10)->setMinute(0),
                'end_time'        => now()->subDays($daysAgo)->setHour(11)->setMinute(0),
                'status'          => 'ended',
                'instructor_id'   => $admin->id,
                'service_id'      => $service->id,
                'organization_id' => $orgId,
                'max_participants' => 4,
            ]);

            // Attach children but don't create attendance records
            $attachChildren = $children->take($i % 2 === 0 ? 3 : 1);
            $lesson->children()->attach($attachChildren->pluck('id'));
        }

        $this->command->info('  ✓ 3 ended sessions without attendance created');
    }

    /**
     * 3 upcoming sessions without a teacher assigned.
     */
    private function seedUnassignedSessions(int $orgId, $children, Service $service): void
    {
        $titles = ['Thursday Maths Group', 'Friday Reading 1:1', 'Saturday Science Workshop'];

        foreach ($titles as $i => $title) {
            $daysAhead = [0, 1, 3][$i]; // Today, tomorrow, 3 days out

            $lesson = Lesson::create([
                'title'           => $title,
                'lesson_type'     => $i === 1 ? '1:1' : 'group',
                'lesson_mode'     => 'online',
                'start_time'      => now()->addDays($daysAhead)->setHour(14)->setMinute(0),
                'end_time'        => now()->addDays($daysAhead)->setHour(15)->setMinute(0),
                'status'          => 'scheduled',
                'instructor_id'   => null, // No teacher assigned
                'service_id'      => $service->id,
                'organization_id' => $orgId,
                'max_participants' => 4,
            ]);

            $attachChildren = $children->take($i === 1 ? 1 : 2);
            $lesson->children()->attach($attachChildren->pluck('id'));
        }

        $this->command->info('  ✓ 3 unassigned upcoming sessions created');
    }

    /**
     * 3 parent feedback items awaiting response.
     */
    private function seedParentFeedback(int $orgId): void
    {
        $parent = User::where('role', User::ROLE_PARENT)
            ->whereHas('organizations', fn ($q) => $q->where('organizations.id', $orgId))
            ->first();

        // Fallback: use any parent
        if (! $parent) {
            $parent = User::where('role', User::ROLE_PARENT)->first();
        }

        if (! $parent) {
            $this->command->warn('  ⚠ No parent user found — skipping feedback seed.');
            return;
        }

        $feedbacks = [
            [
                'category'   => 'Teaching',
                'name'       => $parent->name,
                'message'    => 'Oliver mentioned that the maths sessions are moving a bit fast for him. Could the pace be adjusted slightly?',
                'days_ago'   => 6,
            ],
            [
                'category'   => 'Billing',
                'name'       => $parent->name,
                'message'    => 'I was charged twice for the March session package. Could you please look into this?',
                'days_ago'   => 3,
            ],
            [
                'category'   => 'General',
                'name'       => $parent->name,
                'message'    => 'Is it possible to switch Ella\'s Tuesday session to Wednesday instead? We have a schedule conflict.',
                'days_ago'   => 1,
            ],
        ];

        foreach ($feedbacks as $fb) {
            ParentFeedbacks::create([
                'organization_id' => $orgId,
                'user_id'         => $parent->id,
                'name'            => $fb['name'],
                'user_email'      => $parent->email,
                'category'        => $fb['category'],
                'message'         => $fb['message'],
                'status'          => 'New',
                'submitted_at'    => now()->subDays($fb['days_ago']),
                'created_at'      => now()->subDays($fb['days_ago']),
                'updated_at'      => now()->subDays($fb['days_ago']),
            ]);
        }

        $this->command->info('  ✓ 3 parent feedback items created');
    }
}
