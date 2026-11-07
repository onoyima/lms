<?php

namespace App\Services\Exams;

use App\Models\Attempt;

/**
 * Stateless credential for the exam API. The candidate's browser, desktop app or SEB
 * sends it as "Authorization: Bearer <token>". No session lookup is needed on the
 * hot path, and only the newest token for an attempt is accepted (token_hash on the
 * attempt row), so opening the exam elsewhere stops the old window.
 *
 * token = base64url(attempt_id . "." . random) . "." . hmac
 */
class AttemptToken
{
    public static function issue(Attempt $attempt): string
    {
        $body = rtrim(strtr(base64_encode($attempt->id.'.'.bin2hex(random_bytes(16))), '+/', '-_'), '=');
        $token = $body.'.'.self::sign($body);
        $attempt->forceFill(['token_hash' => hash('sha256', $token)])->save();

        return $token;
    }

    /** @return int|null attempt id if the signature is valid (the hash still has to match the row) */
    public static function attemptId(string $token): ?int
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || ! hash_equals(self::sign($parts[0]), $parts[1])) {
            return null;
        }
        $decoded = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if ($decoded === false || ! preg_match('/^(\d+)\.[0-9a-f]{32}$/', $decoded, $m)) {
            return null;
        }

        return (int) $m[1];
    }

    public static function matches(Attempt $attempt, string $token): bool
    {
        return $attempt->token_hash !== null && hash_equals($attempt->token_hash, hash('sha256', $token));
    }

    private static function sign(string $body): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'attempt:'.$body, (string) config('app.key'), true)), '+/', '-_'), '=');
    }
}
