<?php

namespace App\Services\Media;

use App\Models\Attempt;
use App\Models\MediaRecording;
use App\Models\RecordingChunk;
use App\Support\Exams\ExamSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;

/**
 * Camera and screen recordings made in the candidate's own browser and uploaded in short pieces,
 * so recording works on ordinary web hosting with no media server. A lost connection only delays
 * pieces (the browser keeps them and sends them later); a piece sent twice is stored once.
 * Pieces are encrypted at rest (AES-256-GCM), hashed, and deleted with other evidence.
 */
class BrowserRecordings
{
    public const KINDS = ['camera', 'screen'];

    public const MIMES = ['video/webm', 'video/mp4'];

    /** What this candidate's browser should record when no media server is set up. */
    public function plan(Attempt $a): array
    {
        $p = ExamSettings::forCandidate($a->exam->proctoring, $a->user_id, $a->exam_id);
        $c = config('platform.recording');

        return [
            'camera' => (bool) $p['record_video'],
            'screen' => (bool) ($p['record_screen'] && $p['screen_share']),
            'audio' => (bool) ($p['record_video'] && $p['microphone']),
            'camera_kbps' => max(50, (int) $c['camera_kbps']),
            'screen_kbps' => max(50, (int) $c['screen_kbps']),
            'chunk_seconds' => max(2, min(60, (int) $c['chunk_seconds'])),
        ];
    }

    /** Pieces are accepted while the exam is open and for a while after (they may still be queued in the browser). */
    public function accepts(Attempt $a): bool
    {
        if ($a->isOpen()) {
            return true;
        }

        return $a->submitted_at && $a->submitted_at->gt(now()->subMinutes((int) config('platform.recording.accept_after_submit_minutes', 120)));
    }

    public function allowedKind(Attempt $a, string $kind): bool
    {
        $plan = $this->plan($a);

        return in_array($kind, self::KINDS, true) && $plan[$kind];
    }

    /** Store one piece. Safe to call again with the same piece: it is kept once. */
    public function store(Attempt $a, string $key, string $kind, string $mime, int $seq, int $offsetMs, string $bytes, bool $final): MediaRecording
    {
        $rec = $this->recording($a, $key, $kind, $mime);

        if (! RecordingChunk::where('media_recording_id', $rec->id)->where('seq', $seq)->exists()) {
            $disk = (string) config('platform.recording.disk', 'local');
            $path = 'recordings/'.$a->tenant_id.'/'.$a->id.'/'.$rec->id.'/'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT).'.bin';
            Storage::disk($disk)->put($path, $this->seal($bytes));
            try {
                RecordingChunk::create([
                    'tenant_id' => $a->tenant_id, 'media_recording_id' => $rec->id, 'seq' => $seq, 'disk' => $disk, 'path' => $path,
                    'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'offset_ms' => max(0, $offsetMs), 'created_at' => now(),
                ]);
            } catch (QueryException) {
                // the same piece arrived twice at the same moment; the first one is kept (same path, same bytes)
            }
        }

        $rec->forceFill([
            'status' => $final ? 'complete' : ($rec->status === 'complete' ? 'complete' : 'active'),
            'size' => (int) RecordingChunk::where('media_recording_id', $rec->id)->sum('size'),
            // the browser sends each piece's end time, measured from the start of the recording
            'duration_seconds' => max((int) $rec->duration_seconds, (int) ceil(max(0, $offsetMs) / 1000)),
            'ended_at' => now(),
        ])->save();

        return $rec;
    }

    private function recording(Attempt $a, string $key, string $kind, string $mime): MediaRecording
    {
        $egress = 'web:'.$key;
        $found = MediaRecording::where('egress_id', $egress)->first();
        if ($found) {
            abort_unless($found->attempt_id === $a->id && $found->kind === $kind, 409, 'This recording belongs to another exam.');

            return $found;
        }
        try {
            $rec = (new MediaRecording)->forceFill([
                'tenant_id' => $a->tenant_id, 'attempt_id' => $a->id, 'kind' => $kind, 'egress_id' => $egress,
                'status' => 'active', 'location' => 'web/'.$mime, 'started_at' => now(), 'size' => 0, 'duration_seconds' => 0,
            ]);
            $rec->save();

            return $rec;
        } catch (QueryException) {
            return MediaRecording::where('egress_id', $egress)->firstOrFail();
        }
    }

    public static function isBrowser(MediaRecording $r): bool
    {
        return str_starts_with((string) $r->egress_id, 'web:');
    }

    public static function mime(MediaRecording $r): string
    {
        $m = substr((string) $r->location, 4);

        return in_array($m, self::MIMES, true) ? $m : 'video/webm';
    }

    /** Recordings whose browser went quiet (closed, crashed, exam over) are finished after a while. */
    public function sweep(int $quietMinutes = 10): int
    {
        return MediaRecording::query()->withoutGlobalScopes()->where('egress_id', 'like', 'web:%')->where('status', 'active')
            ->where('updated_at', '<', now()->subMinutes($quietMinutes))->update(['status' => 'complete', 'updated_at' => now()]);
    }

    /** Pieces received, and how many are missing in between (a gap shows as a jump in the video). */
    public function summary(MediaRecording $r): array
    {
        $seqs = RecordingChunk::where('media_recording_id', $r->id)->pluck('seq')->all();
        $count = count($seqs);

        return ['pieces' => $count, 'missing' => $count ? max($seqs) + 1 - $count : 0, 'has_start' => in_array(0, $seqs, true)];
    }

    /**
     * One playable file for a proctor: the pieces decrypted and joined, with the length written into
     * the file so the player can jump around. Cached for a few hours, then removed.
     */
    public function playable(MediaRecording $r): ?string
    {
        $chunks = RecordingChunk::where('media_recording_id', $r->id)->orderBy('seq')->get();
        if ($chunks->isEmpty() || $chunks->first()->seq !== 0) {
            return null;
        }
        $dir = storage_path('app/private/recording-cache');
        is_dir($dir) || mkdir($dir, 0700, true);
        $file = $dir.'/'.$r->id.'-'.$chunks->count().'-'.$chunks->last()->id.(self::mime($r) === 'video/mp4' ? '.mp4' : '.webm');
        if (is_file($file)) {
            touch($file);

            return $file;
        }
        $tmp = $file.'.part'.getmypid();
        $out = fopen($tmp, 'wb');
        $durationMs = (float) max(1000, ((int) $r->duration_seconds) * 1000, (int) $chunks->max('offset_ms'));
        foreach ($chunks as $i => $c) {
            $bytes = $this->open(Storage::disk($c->disk)->get($c->path));
            if ($bytes === null || ! hash_equals($c->sha256, hash('sha256', $bytes))) {
                continue; // altered or unreadable piece: skipped (the integrity check reports it)
            }
            if ($i === 0 && self::mime($r) === 'video/webm') {
                $bytes = WebmDuration::write($bytes, $durationMs);
            }
            fwrite($out, $bytes);
        }
        fclose($out);
        rename($tmp, $file);
        foreach (glob($dir.'/'.$r->id.'-*') ?: [] as $old) {
            $old === $file || @unlink($old);
        }

        return $file;
    }

    /** Remove cached playable files nobody has opened for a while. */
    public function clearCache(int $hours = 3): int
    {
        $n = 0;
        foreach (glob(storage_path('app/private/recording-cache').'/*') ?: [] as $f) {
            if (filemtime($f) < time() - $hours * 3600 && @unlink($f)) {
                $n++;
            }
        }

        return $n;
    }

    /** Delete recordings past the institution's evidence retention, unless an incident on the attempt is still open. */
    public function purgeExpired(): int
    {
        $n = 0;
        MediaRecording::query()->withoutGlobalScopes()->where('egress_id', 'like', 'web:%')
            ->with(['tenant'])
            ->chunkById(200, function ($recs) use (&$n) {
                foreach ($recs as $r) {
                    $days = (int) data_get($r->tenant?->settings, 'evidence_retention_days', 180);
                    if ($r->created_at->gt(now()->subDays($days))) {
                        continue;
                    }
                    $open = \App\Models\Incident::query()->withoutGlobalScopes()->where('attempt_id', $r->attempt_id)
                        ->whereIn('status', ['open', 'investigating', 'escalated'])->exists();
                    if ($open) {
                        continue;
                    }
                    foreach (RecordingChunk::query()->withoutGlobalScopes()->where('media_recording_id', $r->id)->get() as $c) {
                        Storage::disk($c->disk)->delete($c->path);
                    }
                    RecordingChunk::query()->withoutGlobalScopes()->where('media_recording_id', $r->id)->delete();
                    $r->delete();
                    $n++;
                }
            });

        return $n;
    }

    /** Re-check every piece's hash; returns the number of altered or unreadable pieces. */
    public function verify(MediaRecording $r): int
    {
        $bad = 0;
        foreach (RecordingChunk::where('media_recording_id', $r->id)->get() as $c) {
            $bytes = $this->open((string) Storage::disk($c->disk)->get($c->path));
            $bad += ($bytes === null || ! hash_equals($c->sha256, hash('sha256', $bytes))) ? 1 : 0;
        }

        return $bad;
    }

    private function key(): string
    {
        $k = (string) config('app.key');
        $k = str_starts_with($k, 'base64:') ? base64_decode(substr($k, 7)) : $k;

        return hash('sha256', 'recordings|'.$k, true);
    }

    /** AES-256-GCM, binary: "RC1" + iv(12) + tag(16) + ciphertext — no base64, so files stay small. */
    public function seal(string $bytes): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($bytes, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);

        return 'RC1'.$iv.$tag.$cipher;
    }

    public function open(string $sealed): ?string
    {
        if (! str_starts_with($sealed, 'RC1') || strlen($sealed) < 31) {
            return null;
        }
        $plain = openssl_decrypt(substr($sealed, 31), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr($sealed, 3, 12), substr($sealed, 15, 16));

        return $plain === false ? null : $plain;
    }
}
