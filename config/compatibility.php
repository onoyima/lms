<?php

/*
| Proctoring hardware and software compatibility (Target 169). Shown in the help centre and used
| by the computer check before an exam. "minimum" is the oldest version we test; older may work.
*/
return [
    'browsers' => [
        ['name' => 'Google Chrome', 'minimum' => 110, 'match' => 'Chrome', 'status' => 'supported', 'note' => 'Recommended for browser-proctored exams.'],
        ['name' => 'Microsoft Edge', 'minimum' => 110, 'match' => 'Edg', 'status' => 'supported', 'note' => ''],
        ['name' => 'Mozilla Firefox', 'minimum' => 115, 'match' => 'Firefox', 'status' => 'supported', 'note' => 'Screen sharing asks again each exam.'],
        ['name' => 'Safari (macOS)', 'minimum' => 16, 'match' => 'Version/', 'status' => 'limited', 'note' => 'No screen sharing of the whole screen; use the desktop app for screen-proctored exams.'],
        ['name' => 'Safari / Chrome on iPhone and iPad', 'minimum' => 16, 'match' => 'iPhone|iPad', 'status' => 'limited', 'note' => 'Coursework, quizzes and the phone second camera only.'],
        ['name' => 'Chrome on Android', 'minimum' => 110, 'match' => 'Android', 'status' => 'limited', 'note' => 'Coursework, quizzes and the phone second camera only.'],
        ['name' => 'Internet Explorer, Opera Mini, UC Browser', 'minimum' => null, 'match' => 'MSIE|Trident|Opera Mini|UCBrowser', 'status' => 'unsupported', 'note' => 'Not supported.'],
    ],
    'systems' => [
        ['name' => 'Windows 10 and 11 (64-bit)', 'status' => 'supported', 'note' => 'Desktop app and Safe Exam Browser.'],
        ['name' => 'macOS 12 Monterey or later', 'status' => 'supported', 'note' => 'Desktop app; allow camera, microphone and screen recording in System Settings.'],
        ['name' => 'Ubuntu 22.04 or later', 'status' => 'supported', 'note' => 'Desktop app (AppImage).'],
        ['name' => 'ChromeOS', 'status' => 'limited', 'note' => 'Browser-proctored exams only.'],
        ['name' => 'Windows 7 and 8', 'status' => 'unsupported', 'note' => 'No security updates; not allowed for exams.'],
    ],
    'hardware' => [
        ['name' => 'Webcam', 'status' => 'required when the exam uses camera checks', 'note' => '720p or better; built-in or USB. Virtual cameras (OBS, ManyCam, Snap Camera) are refused.'],
        ['name' => 'Second camera', 'status' => 'when the exam asks for it', 'note' => 'A phone (any recent Android or iPhone) or a second USB webcam pointed at the desk.'],
        ['name' => 'Microphone', 'status' => 'required when the exam uses audio checks', 'note' => 'Built-in is fine. Noise alerts are measured on the computer; audio is only sent when live proctoring is on.'],
        ['name' => 'Screen', 'status' => 'one screen', 'note' => 'Extra monitors, HDMI or wireless displays must be unplugged during proctored exams.'],
        ['name' => 'Memory and processor', 'status' => '4 GB RAM, dual-core 2 GHz', 'note' => '8 GB recommended for live video and face checks.'],
        ['name' => 'Internet', 'status' => '1 Mbps up and down', 'note' => '2 Mbps for live video. Answers are saved even if the connection drops for a while.'],
    ],
];
