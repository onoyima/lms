<?php

/*
|--------------------------------------------------------------------------
| Platform modules
|--------------------------------------------------------------------------
| Every feature area is a module that an institution's plan switches on.
| "core" is always on. "requires" lists modules that must also be on.
| "items" refers to the numbered features in Target.txt the module covers.
| Plans list their module keys in database/seeders/PlanSeeder.php.
|
| "part_of" marks a feature inside a bigger module (or inside "core"): it is on wherever that
| module is on, unless the platform switches it off for an institution, and it can be switched on
| or off on its own. It does not count towards a plan's module allowance.
| Each module works by itself: "requires" lists only what a module truly cannot run without.
*/

return [
    'core' => [
        'name' => 'Core',
        'description' => 'Institutions, users, roles and permissions, faculties, departments, courses, sessions and semesters, audit log, notifications, integration API.',
        'always_on' => true,
        'requires' => [],
        'items' => '50-53, 89, 100, 199',
    ],
    'question_bank' => [
        'name' => 'Question bank & exam delivery',
        'description' => 'Word/PDF import with equations, question types, pools and blueprints, shuffling, server timer, autosave, recovery, auto-marking.',
        'requires' => [],
        'items' => '14-28, 59, 158, 198',
    ],
    'browser_proctoring' => [
        'name' => 'Browser proctoring',
        'description' => 'Tab-switch, fullscreen, focus and copy-paste detection, multiple-login detection, snapshots, AI face checks.',
        'requires' => ['question_bank'],
        'items' => '8-12, 70-75',
    ],
    'cbt_halls' => [
        'name' => 'CBT hall operations',
        'description' => 'Venues, seats, attendance, exam PINs, device whitelisting, LAN hall server.',
        'requires' => ['question_bank'],
        'items' => '44-47, 57, 97, 163',
    ],
    'results' => [
        'name' => 'Results & governance',
        'description' => 'Essay marking, moderation, approval chains, publication, appeals and result audit.',
        'requires' => ['question_bank'],
        'items' => '49, 60-61, 159-161',
    ],
    'my_results' => [
        'name' => 'My results',
        'description' => 'Students see their published scores, result statements and can appeal a result.',
        'part_of' => 'question_bank',
        'requires' => ['question_bank'],
        'items' => '60',
    ],
    'analytics' => [
        'name' => 'Analytics',
        'description' => 'Item difficulty and discrimination, risk scoring, institution dashboards.',
        'requires' => ['question_bank'],
        'items' => '62-69, 194-197',
    ],
    'test_shop' => [
        'name' => 'Test-series shop',
        'description' => 'Sell practice and mock exam packages to candidates: catalogue, cart, vouchers, online payment, receipts and timed access.',
        'requires' => ['question_bank'],
        'items' => 'spec 2.2, 12',
    ],
    'secure_client' => [
        'name' => 'Secure clients (desktop app & SEB)',
        'description' => 'Desktop exam app and Safe Exam Browser support with display, USB, VM and remote-desktop checks.',
        'requires' => ['question_bank'],
        'items' => '13, 125-141, 164-169',
    ],
    'live_proctoring' => [
        'name' => 'Live proctoring',
        'description' => 'Live video, audio and screen, dual camera, proctor dashboard, incidents, recordings, evidence.',
        'requires' => ['browser_proctoring'],
        'items' => '3-7, 29-43, 111-157, 174-180',
    ],
    'lms' => [
        'name' => 'Teaching (LMS)',
        'description' => 'Course pages, materials of every kind, assignments, gradebook, forums, announcements, registration, attendance and progress. Works without exams; course quizzes appear when exams are also on.',
        'requires' => [],
        'items' => 'LMS list 1-250',
    ],
    'course_feedback' => [
        'name' => 'Course reviews, polls & requests',
        'description' => 'Students rate courses, answer polls and ask for topics not covered.',
        'requires' => ['lms'],
        'part_of' => 'lms',
        'items' => 'LMS feedback',
    ],
    'exam_board' => [
        'name' => 'Exam board',
        'description' => 'Live statistics and score board per exam, rescaling and exports, for the exams office and each exam\'s examiners.',
        'requires' => ['question_bank'],
        'part_of' => 'question_bank',
        'items' => 'Exam board',
    ],
    'computer_checks' => [
        'name' => 'Computer checks',
        'description' => 'Test lab computers before an exam with a link or QR code; problems are reported with the computer\'s IP.',
        'requires' => ['question_bank'],
        'part_of' => 'question_bank',
        'items' => 'Computer checks',
    ],
    'identity_checks' => [
        'name' => 'Face identity',
        'description' => 'Students and staff enrol their face and ID photo; identity is checked before exams.',
        'requires' => [],
        'part_of' => 'question_bank',
        'items' => '8, 70',
    ],
    'certificates' => [
        'name' => 'Certificates & badges',
        'description' => 'Certificate templates, automatic awards, verifiable PDF certificates and badges.',
        'requires' => [],
        'part_of' => 'core',
        'items' => 'Certificates',
    ],
    'student_requests' => [
        'name' => 'Requests & exceptions',
        'description' => 'Students ask for deferrals, extra time and other exceptions; staff decide.',
        'requires' => [],
        'part_of' => 'core',
        'items' => 'LMS 178, 141',
    ],
    'ai_tools' => [
        'name' => 'AI tools',
        'description' => 'Read question papers with AI, write questions and suggest essay marks (off until a key is set).',
        'requires' => [],
        'part_of' => 'core',
        'items' => 'AI',
    ],
    'sms' => [
        'name' => 'Text messages',
        'description' => 'Sign-in codes and alerts by SMS through the institution\'s gateway.',
        'requires' => [],
        'part_of' => 'core',
        'items' => 'SMS',
    ],
    'conferencing' => [
        'name' => 'Live classes & meetings',
        'description' => 'Virtual classrooms and meetings on BigBlueButton: scheduling, waiting room, whiteboard, breakout rooms, recordings and attendance from join and leave events.',
        'requires' => [],
        'items' => 'Conferencing brief',
    ],
    'enterprise_hosting' => [
        'name' => 'Enterprise hosting',
        'description' => 'Single sign-on, dedicated database or on-premise, high availability, backups with restore tests.',
        'requires' => [],
        'items' => '92-94, 211-214',
    ],
];
