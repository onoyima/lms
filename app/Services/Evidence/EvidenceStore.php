<?php

namespace App\Services\Evidence;

use App\Models\Attempt;
use App\Models\EvidenceAccessLog;
use App\Models\EvidenceFile;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Proctoring evidence (snapshots, camera/screen recordings, audio): stored outside the
 * web root, encrypted at rest, hashed for integrity, deleted on schedule, and every
 * view or download is logged (Target 34-39, 86, 90-91, 116, 174-180, 208, 210).
 */
class EvidenceStore
{
    public const KINDS = ['snapshot', 'webcam_video', 'second_camera', 'screen', 'audio', 'document'];

    public const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'video/webm', 'audio/webm', 'video/mp4', 'application/pdf'];

    public function store(Attempt $attempt, string $kind, string $bytes, string $mime, ?\DateTimeInterface $capturedAt = null): EvidenceFile
    {
        abort_unless(in_array($kind, self::KINDS, true) && in_array($mime, self::MIMES, true), 422, 'Unsupported evidence type.');
        abort_if(strlen($bytes) > 25 * 1024 * 1024, 413, 'Evidence chunk too large.');

        $sha = hash('sha256', $bytes);
        $path = 'evidence/'.$attempt->tenant_id.'/'.$attempt->id.'/'.$kind.'/'.now()->format('His').'_'.Str::random(10).'.bin';
        Storage::disk('local')->put($path, Crypt::encryptString(base64_encode($bytes)));

        $days = (int) data_get($attempt->tenant?->settings, 'evidence_retention_days', 180);

        return EvidenceFile::create([
            'attempt_id' => $attempt->id, 'kind' => $kind, 'disk' => 'local', 'path' => $path, 'mime' => $mime,
            'size' => strlen($bytes), 'sha256' => $sha, 'encrypted' => true,
            'captured_at' => $capturedAt ?? now(), 'delete_after' => now()->addDays($days),
        ]);
    }

    /** Decrypt, re-check the hash (tamper detection) and log who looked. */
    public function read(EvidenceFile $file, User $viewer, string $action = 'view'): string
    {
        $bytes = base64_decode(Crypt::decryptString(Storage::disk($file->disk)->get($file->path)));
        abort_unless(hash_equals($file->sha256, hash('sha256', $bytes)), 409, 'Evidence integrity check failed: the file has been altered.');

        EvidenceAccessLog::create(['evidence_file_id' => $file->id, 'user_id' => $viewer->id, 'action' => $action, 'ip' => request()?->ip(), 'created_at' => now()]);

        return $bytes;
    }

    public function response(EvidenceFile $file, User $viewer, bool $download): StreamedResponse
    {
        $bytes = $this->read($file, $viewer, $download ? 'download' : 'view');

        return response()->streamDownload(fn () => print ($bytes), "evidence-{$file->id}.".(explode('/', $file->mime)[1] ?? 'bin'), [
            'Content-Type' => $file->mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], $download ? 'attachment' : 'inline');
    }

    public function verify(EvidenceFile $file): bool
    {
        try {
            $bytes = base64_decode(Crypt::decryptString(Storage::disk($file->disk)->get($file->path)));

            return hash_equals($file->sha256, hash('sha256', $bytes));
        } catch (\Throwable) {
            return false;
        }
    }

    /** Secure deletion when retention ends, unless the file is attached to an open incident. */
    public function purgeExpired(): int
    {
        $n = 0;
        EvidenceFile::query()->withoutGlobalScopes()->where('delete_after', '<', now())
            ->whereDoesntHave('attempt.incidents', fn ($q) => $q->withoutGlobalScopes()->whereIn('status', ['open', 'investigating', 'escalated']))
            ->chunkById(200, function ($files) use (&$n) {
                foreach ($files as $f) {
                    Storage::disk($f->disk)->delete($f->path);
                    $f->delete();
                    $n++;
                }
            });

        return $n;
    }
}
