<?php

namespace App\Services\Security;

use App\Models\SecurityEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Screens an uploaded file before it is kept. Throws a validation error (shown to the person) when the
 * file is refused, and records a security event. Content checks look at the bytes, not the name.
 */
class UploadScanner
{
    private const EICAR = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    /** Executable formats, recognised by their first bytes. */
    private const MAGIC = ["MZ" => 'Windows program', "\x7fELF" => 'Linux program', "\xcf\xfa\xed\xfe" => 'macOS program', "\xca\xfe\xba\xbe" => 'macOS program'];

    public function check(string $path, string $originalName, string $field = 'file'): void
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $blocked = array_map('strtolower', array_map('trim', (array) config('security.uploads.blocked_extensions')));
        // also catch "report.pdf.exe" and "shell.php.jpg"-style double extensions
        $parts = array_map('strtolower', array_slice(explode('.', $originalName), 1));
        if ($ext !== '' && in_array($ext, $blocked, true) || array_intersect(array_slice($parts, 0, -1), ['php', 'phtml', 'phar', 'exe'])) {
            $this->refuse($field, $originalName, 'blocked_type', 'Files of this type cannot be uploaded.');
        }
        $fh = @fopen($path, 'rb');
        if (! $fh) {
            return;
        }
        $head = (string) fread($fh, 4096);
        fclose($fh);
        foreach (self::MAGIC as $magic => $what) {
            if (str_starts_with($head, $magic)) {
                $this->refuse($field, $originalName, 'executable', "This file is a $what and cannot be uploaded.");
            }
        }
        if (str_contains($head, self::EICAR)) {
            $this->refuse($field, $originalName, 'malware', 'This file was flagged by the virus check and was not uploaded.');
        }
        if (stripos($head, '<?php') !== false) {
            $this->refuse($field, $originalName, 'script', 'This file contains program code and cannot be uploaded.');
        }
        $this->clamav($path, $originalName, $field);
    }

    private function clamav(string $path, string $name, string $field): void
    {
        $socket = (string) config('security.uploads.clamav');
        if ($socket === '') {
            return;
        }
        $timeout = (int) config('security.uploads.timeout', 30);
        $conn = @stream_socket_client($socket, $errno, $err, 5);
        if (! $conn) {
            Log::error('Antivirus daemon unreachable', ['socket' => $socket, 'error' => $err]);
            if (config('security.uploads.fail_closed')) {
                throw ValidationException::withMessages([$field => 'The virus check is not available right now. Try again in a few minutes.']);
            }

            return;
        }
        stream_set_timeout($conn, $timeout);
        fwrite($conn, "zINSTREAM\0");
        $fh = fopen($path, 'rb');
        while (! feof($fh)) {
            $chunk = fread($fh, 1 << 16);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            fwrite($conn, pack('N', strlen($chunk)).$chunk);
        }
        fclose($fh);
        fwrite($conn, pack('N', 0));
        $reply = trim((string) stream_get_contents($conn), "\0\r\n ");
        fclose($conn);
        if (str_ends_with($reply, 'FOUND')) {
            $this->refuse($field, $name, 'malware', 'This file was flagged by the virus check and was not uploaded.', ['signature' => mb_substr($reply, 0, 190)]);
        }
        if (! str_ends_with($reply, 'OK') && config('security.uploads.fail_closed')) {
            Log::error('Antivirus scan failed', ['reply' => mb_substr($reply, 0, 190)]);
            throw ValidationException::withMessages([$field => 'The virus check could not finish. Try again in a few minutes.']);
        }
    }

    private function refuse(string $field, string $name, string $why, string $message, array $extra = []): never
    {
        try {
            SecurityEvent::create(['tenant_id' => tenant_id(), 'user_id' => auth()->id(), 'type' => 'upload_blocked', 'ip' => request()?->ip(),
                'path' => mb_substr((string) request()?->path(), 0, 255), 'data' => ['file' => mb_substr($name, 0, 190), 'reason' => $why] + $extra, 'created_at' => now()]);
        } catch (\Throwable) {
            // never let logging stop the refusal
        }
        throw ValidationException::withMessages([$field => $message]);
    }
}
