<?php

namespace App\Services\Exams;

use App\Models\Exam;
use Illuminate\Http\Response;

/**
 * Builds a Safe Exam Browser settings file (.seb, plist XML) for an exam (Target 13, 119-121, 168).
 * It opens the exam room, blocks other apps and URLs, and sends the Browser Exam Key header
 * that ChannelVerifier checks. Settings follow the SEB configuration-file format.
 */
class SebConfig
{
    public function xml(Exam $exam, string $startUrl): string
    {
        $host = parse_url($startUrl, PHP_URL_HOST);
        $quit = (string) data_get($exam->settings, 'seb.quit_password', '') ?: (string) $this->institution('quit_password');
        $policy = \App\Support\Exams\ExamSettings::proctoring($exam->proctoring);

        $settings = [
            'startURL' => $startUrl,
            'sendBrowserExamKey' => true,
            'browserWindowAllowReload' => true,
            'allowQuit' => true,
            'hashedQuitPassword' => $quit !== '' ? hash('sha256', $quit) : '',
            // the school's SEB password also protects SEB's own settings on the candidate's computer
            'hashedAdminPassword' => ($pw = $this->settingsPassword($exam)) !== '' ? hash('sha256', $pw) : '',
            'quitURL' => url('/my/exams'),
            'quitURLConfirm' => true,
            'allowSpellCheck' => false,
            'allowDictionaryLookup' => false,
            'enableRightMouse' => false,
            'enablePrintScreen' => false,
            'allowVirtualMachine' => false,
            'allowDisplayMirroring' => false,
            'allowedDisplaysMaxNumber' => 1,
            'allowedDisplayBuiltin' => true,
            'enableAppSwitcherCheck' => true,
            'forceAppFolderInstall' => true,
            'killExplorerShell' => false,
            'createNewDesktop' => true,
            // nothing else on screen during the exam: on Windows SEB runs on its own locked desktop, where other
            // apps' and Phone Link notifications do not appear; on macOS and iPad it uses Apple's assessment
            // mode, which silences all notifications; assistants and dictation are off
            'enableMacOSAAC' => true,
            'mobileEnableASAM' => true,
            'allowSiri' => false,
            'allowDictation' => false,
            'allowScreenSharing' => false,
            'enablePrivateClipboard' => true,
            'allowAudioCapture' => (bool) $policy['microphone'],
            'allowVideoCapture' => (bool) ($policy['webcam'] || $policy['live_video']),
            'URLFilterEnable' => true,
            'URLFilterEnableContentFilter' => false,
            'URLFilterRules' => [[
                'active' => true, 'regex' => false, 'action' => 1, 'expression' => $host.'/*',
            ]],
        ];

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">'."\n"
            .'<plist version="1.0">'.$this->value($settings).'</plist>'."\n";
    }

    /**
     * The school's SEB password for this exam (the exam's own, else the institution's). When set, the
     * settings file is encrypted with it: SEB asks for it before it opens the exam.
     */
    public function settingsPassword(Exam $exam): string
    {
        $own = (string) data_get($exam->settings, 'seb.settings_password', '');
        if ($own !== '') {
            try {
                return \Illuminate\Support\Facades\Crypt::decryptString($own);
            } catch (\Throwable) {
                return $own;
            }
        }

        return (string) $this->institution('settings_password');
    }

    /** Institution-wide SEB settings (kept encrypted in the tenant's settings). */
    public function institution(string $key): ?string
    {
        $v = data_get(tenant()?->settings, "seb.$key");
        if (! is_string($v) || $v === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Facades\Crypt::decryptString($v);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The .seb file as SEB reads it. Without a password: the plain settings (XML). With one: the settings
     * are compressed, encrypted with the password (RNCryptor v3: AES-256-CBC, HMAC-SHA256, keys by
     * PBKDF2-SHA1 with 10,000 rounds), marked "pswd", and compressed again, as in the SEB file format.
     */
    public function file(Exam $exam, string $startUrl): string
    {
        $xml = $this->xml($exam, $startUrl);
        $password = $this->settingsPassword($exam);
        if ($password === '') {
            return $xml;
        }

        return gzencode('pswd'.self::rncryptor(gzencode($xml, 9), $password), 9);
    }

    public static function rncryptor(string $plain, string $password): string
    {
        $encSalt = random_bytes(8);
        $hmacSalt = random_bytes(8);
        $iv = random_bytes(16);
        $encKey = hash_pbkdf2('sha1', $password, $encSalt, 10000, 32, true);
        $hmacKey = hash_pbkdf2('sha1', $password, $hmacSalt, 10000, 32, true);
        $header = chr(3).chr(1).$encSalt.$hmacSalt.$iv;
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA, $iv);

        return $header.$cipher.hash_hmac('sha256', $header.$cipher, $hmacKey, true);
    }

    /** Opens a file made by file() (used by the tests, and to check an uploaded settings file). */
    public static function open(string $file, string $password): ?string
    {
        $outer = @gzdecode($file);
        if ($outer === false || ! str_starts_with($outer, 'pswd')) {
            return null;
        }
        $data = substr($outer, 4);
        if (strlen($data) < 66 || ord($data[0]) !== 3 || ord($data[1]) !== 1) {
            return null;
        }
        $header = substr($data, 0, 34);
        $cipher = substr($data, 34, -32);
        $hmacKey = hash_pbkdf2('sha1', $password, substr($data, 10, 8), 10000, 32, true);
        if (! hash_equals(hash_hmac('sha256', $header.$cipher, $hmacKey, true), substr($data, -32))) {
            return null;
        }
        $encKey = hash_pbkdf2('sha1', $password, substr($data, 2, 8), 10000, 32, true);
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA, substr($data, 18, 16));

        return $plain === false ? null : (gzdecode($plain) ?: null);
    }

    public function download(Exam $exam, string $startUrl): Response
    {
        return response($this->file($exam, $startUrl), 200, [
            'Content-Type' => 'application/seb',
            'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^A-Za-z0-9_-]+/', '_', $exam->title).'.seb"',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function value(mixed $v): string
    {
        return match (true) {
            is_bool($v) => $v ? '<true/>' : '<false/>',
            is_int($v) => "<integer>$v</integer>",
            is_array($v) && array_is_list($v) => '<array>'.implode('', array_map(fn ($x) => $this->value($x), $v)).'</array>',
            is_array($v) => '<dict>'.implode('', array_map(fn ($k, $x) => '<key>'.htmlspecialchars((string) $k, ENT_XML1).'</key>'.$this->value($x), array_keys($v), $v)).'</dict>',
            default => '<string>'.htmlspecialchars((string) $v, ENT_XML1).'</string>',
        };
    }
}
