<?php

namespace Database\Seeders;

use App\Models\PlatformPricing;
use Illuminate\Database\Seeder;

class PlatformPricingSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            // User seats
            ['category' => 'user_seat', 'item_key' => 'child', 'label' => 'Student Seat', 'description' => 'Per enrolled student', 'price_monthly' => 2.00, 'sort_order' => 1],
            ['category' => 'user_seat', 'item_key' => 'teacher', 'label' => 'Teacher Seat', 'description' => 'Per active teacher', 'price_monthly' => 5.50, 'sort_order' => 2],
            ['category' => 'user_seat', 'item_key' => 'admin', 'label' => 'Admin Seat', 'description' => 'Per admin user', 'price_monthly' => 5.50, 'sort_order' => 3],

            // AI Workspace - Essentials tier
            ['category' => 'ai_workspace', 'item_key' => 'ai_essentials_admin', 'label' => 'AI Essentials — Admin', 'description' => 'Basic AI chat and simple tools', 'price_monthly' => 25.00, 'tier' => 'essentials', 'sort_order' => 10, 'metadata' => ['monthly_action_limit' => 200, 'features' => ['ai_chat', 'ai_basic_grading'], 'feature_labels' => ['ai_chat' => 'AI Operations Assistant', 'ai_basic_grading' => 'Basic Grading Assist']]],
            ['category' => 'ai_workspace', 'item_key' => 'ai_essentials_teacher', 'label' => 'AI Essentials — Teacher', 'description' => 'Basic AI chat and simple tools', 'price_monthly' => 15.00, 'tier' => 'essentials', 'sort_order' => 11, 'metadata' => ['monthly_action_limit' => 150, 'features' => ['ai_chat', 'ai_basic_grading'], 'feature_labels' => ['ai_chat' => 'AI Teaching Assistant', 'ai_basic_grading' => 'Basic Grading Assist']]],

            // AI Workspace - Professional tier
            ['category' => 'ai_workspace', 'item_key' => 'ai_professional_admin', 'label' => 'AI Professional — Admin', 'description' => 'Full AI suite with studio, advanced grading, and reports', 'price_monthly' => 45.00, 'tier' => 'professional', 'sort_order' => 20, 'metadata' => ['monthly_action_limit' => 1000, 'features' => ['ai_chat', 'ai_studio', 'ai_grading', 'ai_reports', 'ai_feedback', 'ai_content_gen'], 'feature_labels' => ['ai_chat' => 'AI Operations Assistant', 'ai_studio' => 'AI Content Studio', 'ai_grading' => 'Auto-Grade Subjective Answers', 'ai_reports' => 'AI Reports & Parent Updates', 'ai_feedback' => 'Assessment Feedback PDFs', 'ai_content_gen' => 'Course & Lesson Generator']]],
            ['category' => 'ai_workspace', 'item_key' => 'ai_professional_teacher', 'label' => 'AI Professional — Teacher', 'description' => 'Full AI suite with advanced grading and reports', 'price_monthly' => 30.00, 'tier' => 'professional', 'sort_order' => 21, 'metadata' => ['monthly_action_limit' => 750, 'features' => ['ai_chat', 'ai_studio', 'ai_grading', 'ai_reports', 'ai_feedback'], 'feature_labels' => ['ai_chat' => 'AI Teaching Assistant', 'ai_studio' => 'AI Content Studio', 'ai_grading' => 'Auto-Grade Subjective Answers', 'ai_reports' => 'AI Reports & Insights', 'ai_feedback' => 'Assessment Feedback PDFs']]],

            // AI Workspace - Enterprise tier
            ['category' => 'ai_workspace', 'item_key' => 'ai_enterprise_admin', 'label' => 'AI Enterprise — Admin', 'description' => 'Unlimited AI with priority processing and custom agents', 'price_monthly' => 75.00, 'tier' => 'enterprise', 'sort_order' => 30, 'metadata' => ['monthly_action_limit' => -1, 'features' => ['ai_chat', 'ai_studio', 'ai_grading', 'ai_reports', 'ai_feedback', 'ai_content_gen', 'ai_custom_agents', 'ai_priority'], 'feature_labels' => ['ai_chat' => 'AI Operations Assistant', 'ai_studio' => 'AI Content Studio', 'ai_grading' => 'Auto-Grade Subjective Answers', 'ai_reports' => 'AI Reports & Parent Updates', 'ai_feedback' => 'Assessment Feedback PDFs', 'ai_content_gen' => 'Course & Lesson Generator', 'ai_custom_agents' => 'Custom Background Agents', 'ai_priority' => 'Priority AI Processing']]],
            ['category' => 'ai_workspace', 'item_key' => 'ai_enterprise_teacher', 'label' => 'AI Enterprise — Teacher', 'description' => 'Unlimited AI with priority processing', 'price_monthly' => 50.00, 'tier' => 'enterprise', 'sort_order' => 31, 'metadata' => ['monthly_action_limit' => -1, 'features' => ['ai_chat', 'ai_studio', 'ai_grading', 'ai_reports', 'ai_feedback', 'ai_content_gen', 'ai_priority'], 'feature_labels' => ['ai_chat' => 'AI Teaching Assistant', 'ai_studio' => 'AI Content Studio', 'ai_grading' => 'Auto-Grade Subjective Answers', 'ai_reports' => 'AI Reports & Insights', 'ai_feedback' => 'Assessment Feedback PDFs', 'ai_content_gen' => 'Course & Lesson Generator', 'ai_priority' => 'Priority AI Processing']]],

            // Platform
            ['category' => 'platform', 'item_key' => 'platform_base', 'label' => 'Platform Access', 'description' => 'Core platform access', 'price_monthly' => 0.00, 'sort_order' => 0, 'metadata' => ['storage_limit_mb' => 10000]],
        ];

        foreach ($items as $item) {
            PlatformPricing::updateOrCreate(
                [
                    'category' => $item['category'],
                    'item_key' => $item['item_key'],
                ],
                $item
            );
        }
    }
}
