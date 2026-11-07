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
        $quit = (string) data_get($exam->settings, 'seb.quit_password', '');
        $policy = \App\Support\Exams\ExamSettings::proctoring($exam->proctoring);

        $settings = [
            'startURL' => $startUrl,
            'sendBrowserExamKey' => true,
            'browserWindowAllowReload' => true,
            'allowQuit' => true,
            'hashedQuitPassword' => $quit !== '' ? hash('sha256', $quit) : '',
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

    public function download(Exam $exam, string $startUrl): Response
    {
        return response($this->xml($exam, $startUrl), 200, [
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
