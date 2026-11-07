<?php

/*
 * Upload screening. Every uploaded file (form uploads and large chunked uploads) is checked before it is
 * stored: blocked file types, programs disguised as documents, the EICAR test signature, and — when an
 * antivirus daemon is configured — a ClamAV scan. Nothing here turns any protection off.
 */
return [
    'uploads' => [
        // file types that are never accepted, whatever their content
        'blocked_extensions' => array_filter(explode(',', (string) env('UPLOAD_BLOCKED_EXTENSIONS',
            'exe,dll,com,bat,cmd,scr,msi,msp,ps1,vbs,vbe,jse,wsf,wsh,hta,cpl,php,phtml,phar,php3,php4,php5,php7,pht,cgi,asp,aspx,jsp,htaccess'))),
        // ClamAV daemon (clamd): "tcp://127.0.0.1:3310" or "unix:///var/run/clamav/clamd.ctl"; empty = built-in checks only
        'clamav' => env('CLAMAV_SOCKET', ''),
        // refuse uploads when the antivirus daemon is configured but cannot be reached
        'fail_closed' => (bool) env('CLAMAV_FAIL_CLOSED', true),
        'timeout' => (int) env('CLAMAV_TIMEOUT', 30),
    ],
];
