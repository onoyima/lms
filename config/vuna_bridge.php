<?php

$list = fn (string $key, string $default) => array_values(array_filter(array_map('trim', explode(',', (string) env($key, $default))), fn ($v) => $v !== ''));
$ints = fn (string $key, string $default) => array_map('intval', $list($key, $default));

/*
| Portal bridge for Veritas University's student portal ("model2").
|
| A separate system that lives beside the portal's own database and gives the exams platform two things:
|   1. single sign-on: staff sign in with their `staff` account, students with their `students` account and
|      applicants with their `users` account, through a standard OpenID Connect provider;
|   2. a read-only directory API: active students and staff, the active session and semester(s), courses,
|      course registrations and teaching assignments.
|
| SAFETY: the bridge never writes to the portal. The database session is put in READ ONLY mode and every statement
| is checked before it is sent: anything that is not a read is refused. By default only a database on this computer
| is opened; a remote portal database needs VUNA_DB_ALLOW_REMOTE=true (use a read-only database user).
*/
return [
    'enabled' => (bool) env('VUNA_BRIDGE_ENABLED', false),
    'name' => env('VUNA_BRIDGE_NAME', 'Veritas University portal'),
    // the bridge's public address, e.g. https://portal.veritas.edu.ng/bridge/vuna (default: APP_URL/bridge/vuna)
    'issuer' => env('VUNA_BRIDGE_ISSUER'),

    // The portal's database: must be on this computer. Use a read-only database user if you can.
    'db' => [
        'driver' => env('VUNA_DB_DRIVER', 'mysql'),
        'host' => env('VUNA_DB_HOST', '127.0.0.1'),
        'port' => env('VUNA_DB_PORT', '3306'),
        'unix_socket' => env('VUNA_DB_SOCKET', ''),
        'database' => env('VUNA_DB_DATABASE', ''),
        'username' => env('VUNA_DB_USERNAME', ''),
        'password' => env('VUNA_DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'strict' => false,
        'ssl_ca' => env('VUNA_DB_SSL_CA'), // path to the server's CA certificate, to encrypt a remote connection
    ],

    // Allow the portal database to be on another server (read-only). Off unless asked for.
    'allow_remote' => (bool) env('VUNA_DB_ALLOW_REMOTE', false),

    // Applications allowed to use the bridge. The exams platform is the only one by default.
    'clients' => [
        [
            'id' => env('VUNA_BRIDGE_CLIENT_ID', 'veritas-exams-lms'),
            'secret' => env('VUNA_BRIDGE_CLIENT_SECRET', ''),
            'name' => env('VUNA_BRIDGE_CLIENT_NAME', 'Exams & LMS'),
            // exact sign-in return addresses, e.g. https://exams.veritas.edu.ng/sso/portal/callback
            'redirect_uris' => $list('VUNA_BRIDGE_REDIRECT_URIS', ''),
            // where people may be sent after signing out
            'post_logout_redirect_uris' => $list('VUNA_BRIDGE_LOGOUT_URIS', ''),
            'directory' => true, // may read the directory API
        ],
    ],

    'ttl' => [
        'code' => 120,          // seconds a sign-in code stays usable (once)
        'id_token' => 300,
        'access_token' => 900,
        'session' => (int) env('VUNA_BRIDGE_SESSION_MINUTES', 480), // the portal sign-in is remembered for this long
    ],

    'key_path' => env('VUNA_BRIDGE_KEY_PATH', storage_path('app/bridges/vuna-oidc.pem')),

    // Who counts as active. Codes come from the portal (StudentAcademic, Student, User, VuSemester models).
    'rules' => [
        // student_academics studentship: 1 normal, 2 deferment, 3 self withdrawn, 4 expelled, 5 suspended
        'active_studentships' => $ints('VUNA_ACTIVE_STUDENTSHIPS', '1'),
        'studentship_columns' => ['studentship_id', 'studentship', 'studentships'], // first filled one wins
        'studentship_names' => [1 => 'active', 2 => 'deferred', 3 => 'withdrawn', 4 => 'withdrawn', 5 => 'suspended'],
        'studentship_default' => 1, // an empty studentship means normal
        // students.status: 1 good standing, 2 probation
        'active_student_statuses' => $ints('VUNA_ACTIVE_STUDENT_STATUSES', '1,2'),
        // vu_semesters.status for the semester(s) running now
        'active_semester_status' => (int) env('VUNA_ACTIVE_SEMESTER_STATUS', 1),
        // also bring in the last semester(s) before the running one, as students' previous courses
        'previous_semesters' => (int) env('VUNA_PREVIOUS_SEMESTERS', 1),
        // course_regs.is_course_reg: 2 registered (3 deleted, 4 voided)
        'registered_flags' => $ints('VUNA_REGISTERED_FLAGS', '2'),
        // staff.status values that mean "no access" (empty: only terminated appointments end access)
        'inactive_staff_statuses' => $ints('VUNA_INACTIVE_STAFF_STATUSES', ''),
        // where teaching assignments come from (only tables that exist are read)
        'teaching_tables' => ['assigned_courses', 'course_assigneds'],
        // users.user_type of applicants
        'applicant_user_type' => (int) env('VUNA_APPLICANT_USER_TYPE', 7),
        // only applicants of an active admission session (vu_sessions.status) when that link exists
        'applicants_current_session_only' => (bool) env('VUNA_APPLICANTS_CURRENT_ONLY', true),
    ],
];
