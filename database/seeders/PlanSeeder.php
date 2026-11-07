<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        // Starting prices in kobo (₦1 = 100). Platform administrators change them under Platform → Plans.
        $plans = [
            ['key' => 'exams_only', 'name' => 'Exams only', 'sort' => 0, 'tagline' => 'Secure computer-based exams',
                'description' => 'Question bank, CBT delivery and browser proctoring.',
                'modules' => ['question_bank', 'browser_proctoring'], 'price_yearly' => 35_000_000, 'price_monthly' => 3_500_000, 'student_limit' => 1000,
                'features' => ['Question bank with Word & equation import', 'Objective, essay, practical and viva exams', 'Browser proctoring and incident review', 'Up to 1,000 students']],
            ['key' => 'starter', 'name' => 'Starter', 'sort' => 1, 'tagline' => 'Exams with analytics',
                'description' => 'Exams with browser proctoring and basic analytics.',
                'modules' => ['question_bank', 'browser_proctoring', 'analytics'], 'price_yearly' => 75_000_000, 'price_monthly' => 7_500_000, 'student_limit' => 3000,
                'features' => ['Everything in Exams only', 'Item analysis and similarity checks', 'Reports in Excel, CSV and PDF', 'Up to 3,000 students']],
            ['key' => 'professional', 'name' => 'Professional', 'sort' => 2, 'tagline' => 'Halls, results and secure clients', 'highlighted' => true,
                'description' => 'Adds CBT halls, results governance, analytics and secure clients.',
                'modules' => ['question_bank', 'browser_proctoring', 'cbt_halls', 'results', 'analytics', 'secure_client'], 'price_yearly' => 180_000_000, 'price_monthly' => 18_000_000, 'student_limit' => 10000,
                'features' => ['Everything in Starter', 'CBT halls, seats, PINs and approved computers', 'Desktop exam app and Safe Exam Browser', 'Results approval and publication', 'Up to 10,000 students']],
            ['key' => 'enterprise', 'name' => 'Enterprise', 'sort' => 3, 'tagline' => 'The complete platform',
                'description' => 'Everything, including live proctoring, the LMS and enterprise hosting.',
                'modules' => array_keys(config('modules')), 'price_yearly' => 0, 'price_monthly' => 0, 'student_limit' => null,
                'features' => ['Everything in Professional', 'Live video, audio and screen proctoring', 'Face identity checks and single sign-on', 'Full learning management system', 'Unlimited students, priority support']],
        ];

        // starting capacities; platform administrators change them from the console afterwards
        $limits = [
            'exams_only' => ['students' => 1000, 'staff' => 100, 'admins' => 5, 'storage_mb' => 10240, 'upload_mb' => 100],
            'starter' => ['students' => 3000, 'staff' => 300, 'admins' => 10, 'storage_mb' => 51200, 'upload_mb' => 250],
            'professional' => ['students' => 10000, 'staff' => 1000, 'admins' => 25, 'storage_mb' => 204800, 'upload_mb' => 1024],
            'enterprise' => [],
        ];

        // Never overwrites a package the platform team has already edited: new packages are created,
        // existing ones only get capacities when they have none yet.
        foreach ($plans as $plan) {
            $existing = Plan::where('key', $plan['key'])->first();
            if (! $existing) {
                Plan::create($plan + ['limits' => $limits[$plan['key']] ?? []]);
            } elseif ($existing->limits === null) {
                $existing->update(['limits' => ($limits[$plan['key']] ?? []) + ['students' => $existing->student_limit]]);
            }
        }
    }
}
