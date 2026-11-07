<?php

/*
|--------------------------------------------------------------------------
| Platform modules
|--------------------------------------------------------------------------
| Every feature area is a module that an institution's plan switches on.
| "core" is always on. "requires" lists modules that must also be on.
| "items" refers to the numbered features in Target.txt the module covers.
| Plans list their module keys in database/seeders/PlanSeeder.php.
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
        'description' => 'Essay marking, moderation, approval chains, publication, appeals, result audit.',
        'requires' => ['question_bank'],
        'items' => '49, 60-61, 159-161',
    ],
    'analytics' => [
        'name' => 'Analytics',
        'description' => 'Item difficulty and discrimination, risk scoring, institution dashboards.',
        'requires' => ['question_bank'],
        'items' => '62-69, 194-197',
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
        'name' => 'LMS',
        'description' => 'Native learning management: course pages, materials, assignments, quizzes, gradebook, forums, announcements and progress tracking, sharing the question bank and accounts with exams.',
        'requires' => ['question_bank'],
        'items' => 'LMS list 1-250',
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
