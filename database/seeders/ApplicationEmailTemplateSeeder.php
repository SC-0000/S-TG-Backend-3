<?php

namespace Database\Seeders;

use App\Models\CommunicationTemplate;
use Illuminate\Database\Seeder;

class ApplicationEmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'system_key' => 'application_verified',
                'name'       => 'Application Email Verified',
                'channel'    => 'email',
                'category'   => 'transactional',
                'subject'    => 'Verify Your Application',
                'body_text'  => "Hello {{applicant_name}},\n\nThank you for submitting your application. Please verify your email by clicking the link below:\n\n{{verify_url}}\n\nIf you did not submit an application, please ignore this email.\n\nThank you!",
                'body_html'  => '<p>Hello {{applicant_name}},</p><p>Thank you for submitting your application. Please verify your email by clicking the link below:</p><p><a href="{{verify_url}}">Verify Email Address</a></p><p>If you did not submit an application, please ignore this email.</p><p>Thank you!</p>',
                'variables'  => ['applicant_name', 'verify_url', 'org_name'],
            ],
            [
                'system_key' => 'application_under_review',
                'name'       => 'Application Under Review',
                'channel'    => 'email',
                'category'   => 'transactional',
                'subject'    => 'Your Application Is Under Review',
                'body_text'  => "Hello {{applicant_name}},\n\nThank you for verifying your email address. Your application to {{org_name}} has been received and is now under review.\n\nOur team will review your application within 48 hours. You'll receive an email once a decision has been made.\n\nThank you for your patience!",
                'body_html'  => '<p>Hello {{applicant_name}},</p><p>Thank you for verifying your email address. Your application to {{org_name}} has been received and is now under review.</p><p>Our team will review your application within 48 hours. You\'ll receive an email once a decision has been made.</p><p>Thank you for your patience!</p>',
                'variables'  => ['applicant_name', 'org_name'],
            ],
            [
                'system_key' => 'application_approved',
                'name'       => 'Application Approved — Set Up Account',
                'channel'    => 'email',
                'category'   => 'transactional',
                'subject'    => 'Welcome — Set Up Your Account',
                'body_text'  => "Hello {{applicant_name}},\n\nWelcome to {{org_name}}! Your application has been approved and your account is ready.\n\nTo get started, please set up your password:\n\n{{setup_url}}\n\nThis link is valid for 48 hours.\n\nThank you!",
                'body_html'  => '<p>Hello {{applicant_name}},</p><p>Welcome to {{org_name}}! Your application has been approved and your account is ready.</p><p>To get started, please set up your password:</p><p><a href="{{setup_url}}">Set Up My Account</a></p><p>This link is valid for 48 hours.</p><p>Thank you!</p>',
                'variables'  => ['applicant_name', 'org_name', 'setup_url'],
            ],
            [
                'system_key' => 'application_rejected',
                'name'       => 'Application Rejected',
                'channel'    => 'email',
                'category'   => 'transactional',
                'subject'    => 'Update on Your Application',
                'body_text'  => "Hello {{applicant_name}},\n\nThank you for your interest in {{org_name}}. After reviewing your application, we are unfortunately unable to approve it at this time.\n\n{{feedback}}\n\nIf you believe this decision was made in error, you are welcome to submit a new application or contact us.\n\nThank you for your understanding.",
                'body_html'  => '<p>Hello {{applicant_name}},</p><p>Thank you for your interest in {{org_name}}. After reviewing your application, we are unfortunately unable to approve it at this time.</p>{{#feedback}}<p><strong>Feedback:</strong> {{feedback}}</p>{{/feedback}}<p>If you believe this decision was made in error, you are welcome to submit a new application or contact us.</p><p>Thank you for your understanding.</p>',
                'variables'  => ['applicant_name', 'org_name', 'feedback', 'contact_url'],
            ],
            [
                'system_key' => 'verification_reminder',
                'name'       => 'Verification Reminder',
                'channel'    => 'email',
                'category'   => 'reminder',
                'subject'    => 'Reminder: Verify Your Email to Complete Your Application',
                'body_text'  => "Hello {{applicant_name}},\n\nWe noticed you haven't verified your email address yet. Please verify to complete your application:\n\n{{verify_url}}\n\nYour application cannot be processed until your email is verified.\n\nThank you!",
                'body_html'  => '<p>Hello {{applicant_name}},</p><p>We noticed you haven\'t verified your email address yet. Please verify to complete your application:</p><p><a href="{{verify_url}}">Verify Email Address</a></p><p>Your application cannot be processed until your email is verified.</p><p>Thank you!</p>',
                'variables'  => ['applicant_name', 'verify_url', 'org_name'],
            ],
        ];

        foreach ($templates as $template) {
            CommunicationTemplate::updateOrCreate(
                [
                    'system_key'      => $template['system_key'],
                    'organization_id' => null, // platform-level default
                ],
                array_merge($template, [
                    'organization_id' => null,
                    'is_active'       => true,
                ])
            );
        }
    }
}
