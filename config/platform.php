<?php

return [
    // Staff in the roles listed in the institution policy must turn on two-factor sign-in
    'enforce_mfa' => (bool) env('ENFORCE_MFA', true),

    // Days a browser that answered a two-factor code is trusted (0 = ask every time)
    'trust_two_factor_days' => (int) env('TRUST_TWO_FACTOR_DAYS', 30),

    // Monitoring tools send this in X-Health-Token to see detailed health (empty = summary only)
    'health_token' => env('HEALTH_TOKEN', ''),

    // Paystack for subscription payments (platform billing); leave empty to accept bank transfers only
    'paystack' => [
        'secret' => env('PAYSTACK_SECRET_KEY', ''),
        'public' => env('PAYSTACK_PUBLIC_KEY', ''),
    ],
    'bank_transfer' => env('PLATFORM_BANK_DETAILS', ''),
    // campus install: institutions may point the platform at private campus addresses (mail server, hall switches).
    // Turn OFF on shared hosting so one institution cannot reach the host's internal network.
    'allow_private_targets' => (bool) env('PLATFORM_ALLOW_PRIVATE_TARGETS', true),
    // other application servers checked by the recovery drill, e.g. https://app2.campus.local (comma-separated)
    'dr_peers' => env('PLATFORM_DR_PEERS', ''),
    // optional: Slack / Teams / Google Chat incoming-webhook URL that also receives platform alerts
    'alert_webhook' => env('PLATFORM_ALERT_WEBHOOK', ''),
    // the mail service signs bounce reports with this (POST /webhooks/mail)
    'mail_webhook_secret' => env('PLATFORM_MAIL_WEBHOOK_SECRET', ''), // shown on invoices, e.g. "Bank · Account name · Account number"

    // Self-hosted LiveKit media server for live video, audio, screens and recordings (deploy/livekit)
    'livekit' => [
        'url' => env('LIVEKIT_URL', ''),              // wss://media.example.edu.ng — what browsers connect to
        'api_url' => env('LIVEKIT_API_URL', ''),      // https://media.example.edu.ng — server-to-server (defaults to url)
        'key' => env('LIVEKIT_API_KEY', ''),
        'secret' => env('LIVEKIT_API_SECRET', ''),
        'recordings_path' => env('LIVEKIT_RECORDINGS_PATH', '/recordings'), // inside the egress container
        'recordings_local' => env('LIVEKIT_RECORDINGS_LOCAL', ''),          // the same folder as seen by this web server (optional)
    ],

    // Backups: where to write them (use a different disk or a mounted share), how long to keep them,
    // and an optional passphrase to encrypt them (AES-256)
    'backup' => [
        'path' => env('BACKUP_PATH') ?: storage_path('app/private/backups'),
        'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),
        'passphrase' => env('BACKUP_PASSPHRASE', ''),
    ],

    /*
    | Tenant used when a request carries no domain match, no X-Tenant header and
    | no logged-in user (e.g. a single-institution installation on a campus LAN).
    */
    // shared hosting without always-on processes (e.g. SiteGround): work the queue from the scheduler
    'queue_via_scheduler' => (bool) env('QUEUE_VIA_SCHEDULER', false),

    // institution used when no address matches; blank = the first institution added
    'default_tenant' => env('PLATFORM_DEFAULT_TENANT', ''),

    /*
    | Pandoc converts Word (.docx) files, including Word equations, to HTML with
    | LaTeX maths. Windows: install from https://pandoc.org/installing.html and
    | set the full path, e.g. "C:\\Program Files\\Pandoc\\pandoc.exe".
    */
    'pandoc_path' => env('PANDOC_PATH', 'pandoc'),

    /*
    | Ed25519 key used to sign /.well-known/exam-platform.json so the desktop app and
    | SEB can verify they are talking to the real institution server.
    | Generated on first use if missing. Never commit it.
    */
    'signing_key_path' => env('PLATFORM_SIGNING_KEY', storage_path('app/private/platform-signing.key')),

    /*
    | Public addresses advertised to clients (comma separated): campus LAN name or
    | IP first, then the live URL. Example: "https://exams.example.edu.ng,https://192.168.10.5"
    */
    'client_endpoints' => array_values(array_filter(array_map('trim', explode(',', (string) env('PLATFORM_CLIENT_ENDPOINTS', ''))))),

    'question_import' => [
        'max_upload_kb' => 20480,
        'allowed_extensions' => ['docx', 'txt', 'md', 'html', 'htm'],
    ],
];
