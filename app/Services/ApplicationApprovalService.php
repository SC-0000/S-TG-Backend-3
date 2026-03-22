<?php

namespace App\Services;

use App\Mail\ApplicationRejected;
use App\Mail\ApplicationUnderReview;
use App\Mail\WelcomeSetupAccount;
use App\Models\Application;
use App\Models\ApplicationActivity;
use App\Models\Child;
use App\Models\Permission;
use App\Models\User;
use App\Support\MailContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ApplicationApprovalService
{
    protected BillingService $billing;
    protected CommissionEngine $commissionEngine;

    public function __construct(BillingService $billing, CommissionEngine $commissionEngine)
    {
        $this->billing          = $billing;
        $this->commissionEngine = $commissionEngine;
    }

    /**
     * Approve an application: create user, billing, children, permissions, send welcome email.
     */
    public function approve(Application $application, ?int $reviewerId = null): User
    {
        $organizationId = $application->organization_id ?? 2;

        // Create or find the parent user — no plaintext password shared
        $user = User::firstOrCreate(
            ['email' => $application->email],
            [
                'name'                    => $application->applicant_name,
                'password'                => bcrypt(Str::random(40)), // unguessable, never sent
                'role'                    => User::ROLE_PARENT,
                'email_verified_at'       => now(),
                'address_line1'           => $application->address_line1,
                'address_line2'           => $application->address_line2,
                'mobile_number'           => $application->mobile_number,
                'current_organization_id' => $organizationId,
            ]
        );

        // Billing customer
        if (!$user->billing_customer_id) {
            $custId = $this->billing->createCustomer($user);
            if ($custId) {
                $user->billing_customer_id = $custId;
                $user->saveQuietly();
            }
        }

        // Org membership
        if (!$user->organizations()->where('organization_id', $organizationId)->exists()) {
            $user->organizations()->attach($organizationId, [
                'role'       => 'parent',
                'status'     => 'active',
                'invited_by' => null,
                'joined_at'  => now(),
            ]);
        }

        // Generate magic-link setup token and send welcome email
        $rawToken = $user->generateSetupToken();
        $organization = MailContext::resolveOrganization($organizationId, $user);

        $setupUrl = $this->buildSetupUrl($user, $rawToken, $organization);
        MailContext::sendMailable(
            $user->email,
            new WelcomeSetupAccount($user, $setupUrl, $organization)
        );

        // Link user to application
        $application->update([
            'user_id'            => $user->id,
            'application_status' => 'Approved',
            'pipeline_status'    => Application::PIPELINE_APPROVED,
            'pipeline_status_changed_at' => now(),
            'reviewer_id'        => $reviewerId,
        ]);

        // Import children from stored JSON
        $children = json_decode($application->children_data, true) ?? [];
        foreach ($children as $childData) {
            $child = Child::create([
                'application_id'          => $application->application_id,
                'user_id'                 => $user->id,
                'child_name'              => $childData['name'] ?? null,
                'age'                     => $childData['age'] ?? null,
                'school_name'             => $childData['school_name'] ?? null,
                'area'                    => $childData['area'] ?? null,
                'year_group'              => $childData['year_group'] ?? null,
                'date_of_birth'           => $childData['date_of_birth'] ?? null,
                'emergency_contact_name'  => $childData['emergency_contact_name'] ?? null,
                'emergency_contact_phone' => $childData['emergency_contact_phone'] ?? null,
                'academic_info'           => $childData['academic_info'] ?? null,
                'previous_grades'         => $childData['previous_grades'] ?? null,
                'medical_info'            => $childData['medical_info'] ?? null,
                'additional_info'         => $childData['additional_info'] ?? null,
                'organization_id'         => $organizationId,
            ]);

            Permission::create([
                'user_id'           => $user->id,
                'child_id'          => $child->id,
                'terms_accepted_at' => now(),
                'signature_path'    => $application->signature_path,
            ]);
        }

        if (!empty($children)) {
            $application->update(['children_data' => null]);
        }

        // Fire commission rules
        try {
            $this->commissionEngine->onSignupApproved($organizationId, $user);
        } catch (\Throwable $e) {
            Log::warning('CommissionEngine: failed on signup_approved', ['error' => $e->getMessage()]);
        }

        // Log activity
        ApplicationActivityService::logSystemEvent(
            $application,
            'Application approved',
            $reviewerId ? 'Approved by admin' : 'Auto-approved after email verification',
            ['user_id' => $user->id, 'reviewer_id' => $reviewerId]
        );

        return $user;
    }

    /**
     * Reject an application.
     */
    public function reject(Application $application, ?string $feedback = null, ?int $reviewerId = null): void
    {
        $application->update([
            'application_status' => 'Rejected',
            'pipeline_status'    => Application::PIPELINE_REJECTED,
            'pipeline_status_changed_at' => now(),
            'admin_feedback'     => $feedback,
            'reviewer_id'        => $reviewerId,
        ]);

        // Send rejection email
        $organization = MailContext::resolveOrganization($application->organization_id);
        MailContext::sendMailable(
            $application->email,
            new ApplicationRejected($application, $organization)
        );

        ApplicationActivityService::logSystemEvent(
            $application,
            'Application rejected',
            $feedback,
            ['reviewer_id' => $reviewerId]
        );
    }

    /**
     * Send the "under review" notification for Type2 applications.
     */
    public function notifyUnderReview(Application $application): void
    {
        $organization = MailContext::resolveOrganization($application->organization_id);
        MailContext::sendMailable(
            $application->email,
            new ApplicationUnderReview($application, $organization)
        );

        ApplicationActivityService::logSystemEvent(
            $application,
            'Under review notification sent',
            'Applicant notified that their application is under review'
        );
    }

    /**
     * Build the setup account URL.
     */
    protected function buildSetupUrl(User $user, string $rawToken, $organization): string
    {
        $base = null;

        if ($organization) {
            $portalDomain = $organization->portal_domain;
            if ($portalDomain) {
                $base = str_starts_with($portalDomain, 'http') ? $portalDomain : 'https://' . $portalDomain;
            }
        }

        if (!$base) {
            $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        }

        return $base . '/setup-account?' . http_build_query([
            'token' => $rawToken,
            'email' => $user->email,
        ]);
    }
}
