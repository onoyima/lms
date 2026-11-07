<?php

namespace App\Services\Lms;

use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Resumable uploads (LMS 57, 225): large files are sent in chunks; if the network drops the
 * browser asks how much arrived and continues from there. Chunks must arrive in order, the
 * total size is fixed at the start, and unfinished uploads expire after a day.
 */
class Uploads
{
    public const MAX = 512 * 1024 * 1024; // 512 MB

    public const CHUNK_MAX = 8 * 1024 * 1024;

    public function start(User $user, string $purpose, string $filename, int $size, ?string $mime): UploadSession
    {
        // refuse blocked file types before any byte is sent
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext !== '' && in_array($ext, array_map('strtolower', (array) config('security.uploads.blocked_extensions')), true)) {
            throw ValidationException::withMessages(['filename' => 'Files of this type cannot be uploaded.']);
        }
        if ($size < 1 || $size > self::MAX) {
            throw ValidationException::withMessages(['size' => 'Files must be under 512 MB.']);
        }
        // the institution's subscription: largest file and storage left (announced size, before any byte arrives)
        $ent = app(\App\Services\Billing\Entitlements::class);
        $ent->check('upload_mb', $size / 1048576);
        $ent->check('storage_mb', $size / 1048576);
        // a person can only have a few uploads running at once
        abort_if(UploadSession::where('user_id', $user->id)->where('status', 'open')->where('expires_at', '>', now())->count() >= 5, 429, 'Finish your other uploads first.');
        $session = UploadSession::create([
            'user_id' => $user->id, 'purpose' => $purpose, 'filename' => mb_substr(basename(str_replace('\\', '/', $filename)), 0, 200), 'mime' => $mime ? mb_substr($mime, 0, 120) : null,
            'size' => $size, 'path' => 'uploads/tmp/'.bin2hex(random_bytes(16)).'.part', 'expires_at' => now()->addDay(),
        ]);
        Storage::disk('local')->put($session->path, '');

        return $session;
    }

    /** Append one chunk at the given offset; returns bytes received so far. */
    public function append(UploadSession $s, User $user, int $offset, string $bytes): int
    {
        abort_unless($s->user_id === $user->id, 404);
        abort_unless($s->status === 'open' && $s->expires_at->isFuture(), 410, 'This upload has expired. Start again.');
        abort_if(strlen($bytes) === 0 || strlen($bytes) > self::CHUNK_MAX, 413, 'Chunk too large.');
        if ($offset !== $s->received) {
            // the browser is out of step (for example after a reconnect): tell it where to continue
            abort(409, (string) $s->received);
        }
        abort_if($offset + strlen($bytes) > $s->size, 422, 'More data than announced.');
        $fh = fopen(Storage::disk('local')->path($s->path), 'ab');
        flock($fh, LOCK_EX);
        fwrite($fh, $bytes);
        flock($fh, LOCK_UN);
        fclose($fh);
        $s->received = $offset + strlen($bytes);
        if ($s->received === $s->size) {
            $s->status = 'complete';
        }
        $s->save();

        return $s->received;
    }

    /** Hand over a finished upload for its purpose (once). */
    public function take(string $id, User $user, string $purpose): UploadedFile
    {
        $s = UploadSession::where('id', $id)->where('user_id', $user->id)->first();
        if (! $s || $s->status !== 'complete' || $s->purpose !== $purpose) {
            throw ValidationException::withMessages(['file' => 'The upload is not finished. Try again.']);
        }
        $path = Storage::disk('local')->path($s->path);
        try {
            app(\App\Services\Security\UploadScanner::class)->check($path, $s->filename);
        } catch (ValidationException $e) {
            $s->update(['status' => 'used']);
            Storage::disk('local')->delete($s->path);
            throw $e;
        }
        $s->update(['status' => 'used']);

        return new UploadedFile($path, $s->filename, $s->mime, null, true);
    }

    public function purge(): int
    {
        $n = 0;
        UploadSession::withoutGlobalScopes()->where(fn ($q) => $q->where('expires_at', '<', now())->orWhere('status', 'used'))->where('updated_at', '<', now()->subHour())
            ->each(function ($s) use (&$n) {
                Storage::disk('local')->delete($s->path);
                $s->delete();
                $n++;
            });

        return $n;
    }
}
