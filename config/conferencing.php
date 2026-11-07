<?php

/*
 * Live meetings on BigBlueButton. Servers and their secrets are added in the platform console
 * (or an institution's own conferencing page) and stored encrypted; these are only defaults.
 */
return [
    // A first shared server can also come from the environment (handy for a single install)
    'default_server' => [
        'url' => env('BBB_URL', ''),          // https://bbb.example.edu/bigbluebutton/
        'secret' => env('BBB_SECRET', ''),    // `bbb-conf --secret` on the server; stays on this server
        'algo' => env('BBB_CHECKSUM', 'sha256'),
    ],
    // How long before the start a host may open the room, and how long after the end it stays joinable
    'open_minutes_before' => 15,
    'close_minutes_after' => 60,
    // Attendance: share of the scheduled time a student must be present to count as "present"
    'present_share' => 0.5,
    'late_after_minutes' => 10,
    // A signed playback link lasts this long
    'playback_link_minutes' => 30,

    // Transcripts of recordings: any speech-to-text service with the OpenAI-style
    // POST /v1/audio/transcriptions API, e.g. a self-hosted faster-whisper server
    // (github.com/fedirz/faster-whisper-server or speaches) so audio never leaves your servers.
    'transcription' => [
        'url' => env('TRANSCRIBE_URL', ''),          // http://whisper.campus.local:8000
        'key' => env('TRANSCRIBE_KEY', ''),
        'model' => env('TRANSCRIBE_MODEL', 'Systran/faster-whisper-small'),
        'language' => env('TRANSCRIBE_LANGUAGE', ''), // blank = detect
        'max_mb' => (int) env('TRANSCRIBE_MAX_MB', 500),
    ],
    // Translation of transcripts: a LibreTranslate-compatible service (POST /translate), self-hosted or hosted
    'translation' => [
        'url' => env('TRANSLATE_URL', ''),           // http://translate.campus.local:5000
        'key' => env('TRANSLATE_KEY', ''),
        'languages' => ['en' => 'English', 'fr' => 'French', 'ar' => 'Arabic', 'ha' => 'Hausa', 'yo' => 'Yoruba', 'ig' => 'Igbo', 'es' => 'Spanish', 'pt' => 'Portuguese', 'zh' => 'Chinese', 'de' => 'German'],
    ],

    // Built-in rooms: browsers send video straight to each other and the platform only introduces them,
    // so they need no meeting server and run on ordinary web hosting. Every person sends their video to
    // every other person, which is why rooms are kept small.
    'builtin' => [
        'max_participants' => (int) env('BUILTIN_MEETING_MAX', 6),
        // finds each computer's public address; public servers work, or name your own
        'stun' => array_values(array_filter(array_map('trim', explode(',', (string) env('BUILTIN_STUN_URLS', 'stun:stun.l.google.com:19302,stun:stun1.l.google.com:19302'))))),
        // optional relay for networks that block direct connections (e.g. coturn). With TURN_SECRET the
        // platform hands out short-lived passwords (coturn "use-auth-secret"); otherwise a fixed user/password.
        'turn_urls' => array_values(array_filter(array_map('trim', explode(',', (string) env('BUILTIN_TURN_URLS', ''))))),
        'turn_secret' => env('BUILTIN_TURN_SECRET', ''),
        'turn_username' => env('BUILTIN_TURN_USERNAME', ''),
        'turn_password' => env('BUILTIN_TURN_PASSWORD', ''),
        // how often browsers check in (ms) and when a silent browser counts as gone (seconds)
        'poll_ms' => (int) env('BUILTIN_POLL_MS', 1500),
        'gone_after' => 25,
    ],
];
