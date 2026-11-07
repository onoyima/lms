<?php

namespace App\Services\Exams;

use App\Models\Device;
use App\Models\Exam;
use Illuminate\Http\Request;

/**
 * Works out which way the candidate came in (web, desktop, seb) and whether the
 * exam allows it (Target 13, 167-168).
 *
 * Desktop app: every request carries
 *   X-Platform-Device: <device id>         (sha256 stored as fingerprint_hash)
 *   X-Platform-Timestamp: <unix seconds>
 *   X-Platform-Signature: base64 Ed25519 signature of "METHOD\nPATH\nTIMESTAMP\nsha256(body)"
 * signed with the app's private key; the public key is registered when the computer is approved.
 *
 * Safe Exam Browser: SEB sends X-SafeExamBrowser-ConfigKeyHash = sha256(url . configKey)
 * and X-SafeExamBrowser-RequestHash = sha256(url . browserExamKey). The exam stores the
 * Config Key and/or Browser Exam Key shown by the SEB configuration tool.
 */
class ChannelVerifier
{
    public function channel(Request $request): string
    {
        if ($request->hasHeader('X-Platform-Signature')) {
            return 'desktop';
        }
        if ($request->hasHeader('X-SafeExamBrowser-ConfigKeyHash') || $request->hasHeader('X-SafeExamBrowser-RequestHash')) {
            return 'seb';
        }

        return 'web';
    }

    /** @return array{ok: bool, channel: string, reason: ?string, device: ?Device} */
    public function verify(Request $request, Exam $exam): array
    {
        $channel = $this->channel($request);
        $allowed = $exam->channels ?: ['web', 'desktop', 'seb'];

        if (! in_array($channel, $allowed, true)) {
            return ['ok' => false, 'channel' => $channel, 'reason' => 'This exam must be taken in: '.implode(' or ', array_map(fn ($c) => ['web' => 'a web browser', 'desktop' => 'the desktop exam app', 'seb' => 'Safe Exam Browser'][$c] ?? $c, $allowed)).'.', 'device' => null];
        }

        if ($channel === 'desktop') {
            $device = $this->verifyDesktop($request);

            return $device
                ? ['ok' => true, 'channel' => $channel, 'reason' => null, 'device' => $device]
                : ['ok' => false, 'channel' => $channel, 'reason' => 'This computer is not approved for exams, or its signature is invalid.', 'device' => null];
        }

        if ($channel === 'seb' && ! $this->verifySeb($request, $exam)) {
            return ['ok' => false, 'channel' => $channel, 'reason' => 'Safe Exam Browser settings do not match this exam. Use the settings file from the exam page.', 'device' => null];
        }

        return ['ok' => true, 'channel' => $channel, 'reason' => null, 'device' => null];
    }

    /**
     * File uploads (multipart) cannot be hashed by the app before sending, so for those the app
     * signs the word "unsigned" in place of the body hash; the caller decides where that is allowed.
     */
    public function verifyDesktop(Request $request, bool $allowUnsignedBody = false): ?Device
    {
        $deviceId = (string) $request->header('X-Platform-Device');
        $ts = (int) $request->header('X-Platform-Timestamp');
        $sig = base64_decode((string) $request->header('X-Platform-Signature'), true);

        if ($deviceId === '' || ! $sig || abs(time() - $ts) > 120) {
            return null; // also stops replays older than two minutes
        }

        $device = Device::where('fingerprint_hash', hash('sha256', $deviceId))->where('status', 'approved')->first();
        if (! $device || ! $device->public_key) {
            return null;
        }

        $bodyHash = $allowUnsignedBody && $request->header('X-Platform-Body') === 'unsigned' ? 'unsigned' : hash('sha256', $request->getContent());
        $message = strtoupper($request->method())."\n/".ltrim($request->path(), '/')."\n".$ts."\n".$bodyHash;
        try {
            $valid = sodium_crypto_sign_verify_detached($sig, $message, base64_decode($device->public_key));
        } catch (\SodiumException) {
            $valid = false;
        }

        if ($valid) {
            $device->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $valid ? $device : null;
    }

    public function verifySeb(Request $request, Exam $exam): bool
    {
        $seb = (array) data_get($exam->settings, 'seb', []);
        $url = $request->fullUrl();
        $checked = false;

        if (! empty($seb['config_key'])) {
            $checked = true;
            if (! hash_equals(hash('sha256', $url.$seb['config_key']), strtolower((string) $request->header('X-SafeExamBrowser-ConfigKeyHash')))) {
                return false;
            }
        }
        foreach ((array) ($seb['browser_exam_keys'] ?? []) as $bek) {
            $checked = true;
            if (hash_equals(hash('sha256', $url.$bek), strtolower((string) $request->header('X-SafeExamBrowser-RequestHash')))) {
                return true;
            }
        }

        // No keys configured yet: accept SEB by header only (admins see a warning on the exam page)
        return ! $checked || empty($seb['browser_exam_keys']);
    }
}
