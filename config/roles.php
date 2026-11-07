<?php

/*
| Default roles created for every institution, and what each may do.
| Institutions can add their own roles later; these are the starting set from Target.txt.
*/

return [
    'permissions' => [
        'institution.manage',      // branding, settings
        'users.manage',            // staff and students, bulk import
        'roles.manage',
        'academics.manage',        // faculties, departments, programmes, courses, sessions
        'questions.view',
        'questions.create',        // write and import questions
        'questions.moderate',      // review and approve
        'exams.manage',            // schedule, configure, publish
        'exams.invigilate',
        'exams.proctor',
        'exams.lead_hall',         // chief invigilator: leads a hall, receives escalations
        'results.mark',
        'results.approve',
        'results.publish',
        'lms.teach',               // course pages, materials, assignments
        'lms.learn',
        'audit.view',
        'integrations.manage',
        'billing.manage',          // subscription, invoices and payments
        'reports.all_departments', // reports and dashboards for the whole institution
        'reports.faculty',         // reports for the person's own faculty (otherwise their department)
        'meetings.host',           // create and run meetings beyond their own courses (department, faculty, seminars)
        'conferencing.manage',     // every meeting, recordings, meeting servers and defaults
    ],

    // What each permission means, in plain words, for the Roles & permissions screen
    'labels' => [
        'institution.manage' => ['Institution settings', 'Branding, policy, single sign-on, maintenance, e-mail delivery, system health'],
        'users.manage' => ['People', 'Add, import and suspend people; give them roles they hold themselves; identity checks; access reviews'],
        'roles.manage' => ['Roles & permissions', 'Create roles and decide what each role may do'],
        'academics.manage' => ['Academic structure', 'Faculties, departments, programmes, courses, semesters and course offerings'],
        'questions.view' => ['See question bank', 'Read questions in the bank'],
        'questions.create' => ['Write questions', 'Write, edit and import questions'],
        'questions.moderate' => ['Moderate questions', 'Review and approve other people\'s questions'],
        'exams.manage' => ['Run exams', 'Set papers, schedule sittings, add candidates, halls and devices, exceptions'],
        'exams.invigilate' => ['Invigilate', 'Exam-day console for assigned sittings: check-in, attendance, incidents'],
        'exams.proctor' => ['Proctor', 'Watch candidates live and handle flags for assigned sittings'],
        'exams.lead_hall' => ['Lead a hall', 'Chief invigilator: rostered to lead a sitting and hears first when a proctor drops off'],
        'results.mark' => ['Mark', 'Mark scripts and assignments'],
        'results.approve' => ['Approve results', 'Approve course results and course reviews in their area'],
        'results.publish' => ['Publish results', 'Publish approved results to students'],
        'lms.teach' => ['Teach courses', 'Course pages, materials, assignments, attendance and recordings'],
        'lms.learn' => ['Study', 'Take courses, register, sit exams and see results'],
        'audit.view' => ['Audit log', 'See the audit trail and personal-data reports'],
        'integrations.manage' => ['Integrations', 'API keys and connected services'],
        'billing.manage' => ['Subscription & billing', 'See the plan, invoices and pay them'],
        'reports.all_departments' => ['Institution-wide reports', 'Reports and dashboards cover every department'],
        'reports.faculty' => ['Faculty reports', 'Reports cover the person\'s whole faculty instead of only their department'],
        'meetings.host' => ['Host meetings', 'Create and run live meetings, seminars and department or faculty meetings (lecturers can always run their own courses\' classes)'],
        'conferencing.manage' => ['Manage conferencing', 'See and run every meeting, manage recordings, meeting servers and defaults'],
    ],

    // Permissions that can never arrive through single sign-on group mapping
    'privileged' => ['institution.manage', 'users.manage', 'roles.manage', 'integrations.manage', 'billing.manage'],

    // Plain names for the starting roles (institutions can rename, change or add their own)
    'role_labels' => [
        'institution_admin' => ['Institution administrator', 'Everything, including billing. Always has every permission.'],
        'ict_admin' => ['ICT administrator', 'Settings, people, integrations and the audit log'],
        'registry' => ['Registry', 'People and academic structure'],
        'exam_officer' => ['Exams officer', 'Exams, sittings, moderation and publishing results'],
        'dean' => ['Dean', 'Approves results and sees reports for the faculty'],
        'hod' => ['Head of department', 'Approves results and moderates questions for the department'],
        'lecturer' => ['Lecturer', 'Teaches courses, writes questions and marks'],
        'question_setter' => ['Question setter', 'Writes questions'],
        'moderator' => ['Moderator', 'Reviews questions'],
        'chief_invigilator' => ['Chief invigilator', 'Leads a hall on exam day'],
        'invigilator' => ['Invigilator', 'Supervises a hall on exam day'],
        'proctor' => ['Proctor', 'Watches online candidates'],
        'student' => ['Student', 'Studies and sits exams'],
    ],

    'roles' => [
        'institution_admin' => ['*'],
        'ict_admin' => ['institution.manage', 'users.manage', 'roles.manage', 'audit.view', 'integrations.manage', 'reports.all_departments'],
        'registry' => ['users.manage', 'academics.manage', 'reports.all_departments'],
        'exam_officer' => ['questions.view', 'questions.moderate', 'exams.manage', 'results.publish', 'audit.view', 'reports.all_departments'],
        'dean' => ['questions.view', 'results.approve', 'audit.view', 'reports.faculty'],
        'hod' => ['questions.view', 'questions.moderate', 'results.approve'],
        'lecturer' => ['questions.view', 'questions.create', 'results.mark', 'lms.teach'],
        'question_setter' => ['questions.view', 'questions.create'],
        'moderator' => ['questions.view', 'questions.moderate'],
        'chief_invigilator' => ['exams.invigilate', 'exams.proctor', 'exams.lead_hall'],
        'invigilator' => ['exams.invigilate'],
        'proctor' => ['exams.proctor'],
        'student' => ['lms.learn'],
    ],
];
