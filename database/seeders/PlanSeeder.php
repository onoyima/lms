<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /** Six packages from $4,000 to $15,000,000 a year; the last is the institution-owned licence. */
    public const DEFAULTS = [
        ['key' => 'exams_only', 'name' => 'Essentials', 'sort' => 0, 'currency' => 'USD', 'tagline' => 'Secure computer-based exams',
            'description' => 'Question bank, online exams and browser proctoring for a school or department.',
            'modules' => ['question_bank', 'browser_proctoring'], 'price_yearly' => 400_000, 'price_monthly' => 40_000, 'student_limit' => 1000,
            'features' => ['Objective, theory, practical and viva papers', 'Question bank with Word and equation upload', 'Pre-exam checks and browser proctoring', 'Up to 1,000 students']],
        ['key' => 'starter', 'name' => 'Standard', 'sort' => 1, 'currency' => 'USD', 'tagline' => 'Exams with analytics',
            'description' => 'Adds item analysis, similarity checks and reports.',
            'modules' => ['question_bank', 'browser_proctoring', 'analytics'], 'price_yearly' => 2_500_000, 'price_monthly' => 250_000, 'student_limit' => 3000,
            'features' => ['Everything in Essentials', 'Item analysis and similarity checks', 'Reports in Excel, CSV and PDF', 'Up to 3,000 students']],
        ['key' => 'professional', 'name' => 'Professional', 'sort' => 2, 'currency' => 'USD', 'tagline' => 'Halls, results and secure clients', 'highlighted' => true,
            'description' => 'CBT halls, results approval and publication, the desktop exam app and Safe Exam Browser.',
            'modules' => ['question_bank', 'browser_proctoring', 'cbt_halls', 'results', 'analytics', 'secure_client'], 'price_yearly' => 15_000_000, 'price_monthly' => 1_500_000, 'student_limit' => 10000,
            'features' => ['Everything in Standard', 'CBT halls, seats, PINs and approved computers', 'Desktop exam app and Safe Exam Browser', 'Results moderation, approval and publication', 'Up to 10,000 students']],
        ['key' => 'university', 'name' => 'University', 'sort' => 3, 'currency' => 'USD', 'tagline' => 'Exams and learning for a whole university',
            'description' => 'Adds the learning management system, live classes and recorded, live proctoring.',
            'modules' => ['question_bank', 'browser_proctoring', 'cbt_halls', 'results', 'analytics', 'secure_client', 'live_proctoring', 'lms', 'conferencing', 'test_shop'], 'price_yearly' => 100_000_000, 'price_monthly' => 10_000_000, 'student_limit' => 40000,
            'features' => ['Everything in Professional', 'Courses, assignments, grades and live classes', 'Camera, microphone and screen recording', 'Face identity checks', 'Up to 40,000 students']],
        ['key' => 'enterprise', 'name' => 'Enterprise', 'sort' => 4, 'currency' => 'USD', 'tagline' => 'Several campuses or a national body',
            'description' => 'Every module, unlimited students, single sign-on and priority support.',
            'modules' => '*', 'price_yearly' => 500_000_000, 'price_monthly' => 50_000_000, 'student_limit' => null,
            'features' => ['Everything in University', 'Unlimited students and campuses', 'Single sign-on and integrations', 'Dedicated server capacity on our cloud', 'Priority support']],
        ['key' => 'institution_owned', 'name' => 'Institution-owned', 'sort' => 5, 'currency' => 'USD', 'tagline' => 'Your own copy, on your own servers',
            'description' => 'The whole platform installed and run by your institution, with your name on it. Agreed with the platform owner.',
            'modules' => '*', 'price_yearly' => 1_500_000_000, 'price_monthly' => 0, 'student_limit' => null,
            'features' => ['The complete platform under your own brand', 'Hosted and managed by your institution', 'Installation, training and handover', 'Updates and support by agreement', 'Price agreed with the platform owner']],
    ];

    /** Starting capacities; the platform team changes them from the console afterwards. */
    public const LIMITS = [
        'exams_only' => ['students' => 1000, 'staff' => 100, 'admins' => 5, 'storage_mb' => 10240, 'upload_mb' => 100],
        'starter' => ['students' => 3000, 'staff' => 300, 'admins' => 10, 'storage_mb' => 51200, 'upload_mb' => 250],
        'professional' => ['students' => 10000, 'staff' => 1000, 'admins' => 25, 'storage_mb' => 204800, 'upload_mb' => 1024],
        'university' => ['students' => 40000, 'staff' => 4000, 'admins' => 60, 'storage_mb' => 1048576, 'upload_mb' => 2048],
        'enterprise' => [],
        'institution_owned' => [],
    ];

    public function run(): void
    {
        // Starting packages and prices (minor units: 100 = 1 dollar). The platform team changes names,
        // prices, currency, modules, capacities and features under Platform → Plans & prices.
        $plans = self::DEFAULTS;
        $limits = self::LIMITS;

        // Never overwrites a package the platform team has already edited: new packages are created,
        // existing ones only get capacities when they have none yet.
        foreach ($plans as $plan) {
            $plan['modules'] = $plan['modules'] === '*' ? array_keys(config('modules')) : $plan['modules']; // every module
            $existing = Plan::where('key', $plan['key'])->first();
            if (! $existing) {
                Plan::create($plan + ['limits' => $limits[$plan['key']] ?? []]);
            } elseif ($existing->limits === null) {
                $existing->update(['limits' => ($limits[$plan['key']] ?? []) + ['students' => $existing->student_limit]]);
            }
        }
    }
}
